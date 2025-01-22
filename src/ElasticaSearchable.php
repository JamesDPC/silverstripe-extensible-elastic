<?php

namespace Symbiote\ElasticSearch;

use ArrayObject;
use Heyday\Elastica\Searchable;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Adds additional indexing fields to support broader search usage
 *
 * Ensures that Versioned content is indexed in an appropriate stage.
 *
 * @author marcus
 */
class ElasticaSearchable extends Searchable
{
    public static $stage_field = 'SS_Stage';

    /**
     * Are we indexing _live_ content?
     *
     * @var boolean
     */
    private $liveIndex = false;

    /**
     * Handles indexing of stage and Live content
     *
     * @param string $stage
     * @return void
     */
    public function reIndex($stage = '')
    {
        $currentStage = $stage ? $stage : Versioned::get_stage();
        $this->liveIndex = $currentStage === 'Live' ? true : false;
        return parent::reIndex($currentStage);
    }

    public function onAfterPublish()
    {
        $this->liveIndex = true;
        $this->reIndex('Live');
    }

    /**
     * Requests a reindex after all relations are published
     * See RecursivePublishable::publishRecursive
    */
    public function onAfterPublishRecursive()
    {
        $this->liveIndex = true;
        $this->reIndex('Live');
    }

    public function onBeforeUnpublish()
    {
        // We need to remove the `live` index from the search. Because we're not
        // going through the `reIndex` methods and the `getElasticaDocument` is
        // called from `Heyday\Elastica::remove`, we need to set the `liveIndex`
        // flage here.
        $this->liveIndex = true;
    }

    public function getElasticaFields()
    {
        $result = parent::getElasticaFields();

        // this needs to be an array object because invokeWithExtensions will _not_ pass
        // params by reference
        $result = new ArrayObject($result);

        $result['ID'] = ['type' => 'long'];
        $result['ClassName'] = ['type' => 'keyword'];
        $result['ClassNameHierarchy'] = [
            'type' => 'keyword',
            'store' => true,
        ];
        $result['SS_Stage'] = ['type' => 'keyword'];

        $result['PublicView'] = ['type' => 'boolean'];
        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $result['ParentsHierarchy'] = ['type' => 'long',];
        }

        foreach ($result as $field => $spec) {
            if (isset($spec['type']) && ($spec['type'] == 'date') && !isset($spec['format'])) {
                // changed to support date only fields
                $spec['format'] = 'dateOptionalTime';
                $result[$field] = $spec;
            }
        }
        if (isset($result['Content']) && count($result['Content']) && !isset($result['Content']['store'])) {
            $spec = $result['Content'];
            $spec['store'] = false;
            $result['Content'] = $spec;
        }

        $this->owner->invokeWithExtensions('updateElasticMappings', $result);
        return $result->getArrayCopy();

    }

    public function getElasticaDocument()
    {
        $document = parent::getElasticaDocument();

        $stage = null;
        $indexedInStage = [];
        // is versioned, or has VersionedDataObject extension
        if ($this->owner->hasExtension(Versioned::class) || $this->owner->hasMethod('getCMSPublishedState')) {
            // add in the specific stage(s)
            $stage = $this->liveIndex ? 'Live' : 'Stage';
            $indexedInStage = [$stage];
        } else {
            $indexedInStage = ['Live', 'Stage'];
        }
        $document->set('SS_Stage', $indexedInStage);

        $document->set('PublicView', $this->owner->canView(Member::create()));

        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $document->set('ParentsHierarchy', $this->getParentsHierarchyField());
        }

        if (!$document->has('ClassNameHierarchy')) {
            $classes = array_values(ClassInfo::ancestry($this->owner->ClassName));
            if (!$classes) {
                $classes = [$this->owner->ClassName];
            }
            $self = $this;
            $classes = array_map(function ($item) use ($self) {
                return str_replace('\\', '_', $item);
            }, $classes);

            $document->set('ClassNameHierarchy', $classes);
        }

        // Construct our ID based on type and stage, as _type mappings are being removed
        // in Elastic 6, meaning we need a unique ID

        $this->owner->invokeWithExtensions('updateElasticDoc', $document);

        return $document;
    }

    /**
     * Get a field value representing the parents hierarchy (if applicable)
     *
     * @param type $dataObject
     */
    protected function getParentsHierarchyField()
    {
        // see if we've got Parent values
        $parents = [];

        $parent = $this->owner;
        while ($parent && $parent->ParentID) {
            $parents[] = (int) $parent->ParentID;
            $parent = $parent->Parent();
            // fix for odd behaviour - in some instance a node is being assigned as its own parent.
            if ($parent->ParentID == $parent->ID) {
                $parent = null;
            }
        }
        return $parents;
    }
}
