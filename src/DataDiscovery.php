<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use Symbiote\MultiValueField\Fields\MultiValueTextField;
use SilverStripe\CMS\Model\SiteTree;

/**
 * @author marcus
 */
class DataDiscovery extends Extension
{
    //put your code here
    private static array $db = [
        'BoostTerms' => 'MultiValueField',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab('Root.Tagging', $mvf = MultiValueTextField::create('BoostTerms', 'Boost for these keywords'));
        $mvf->setRightTitle("Enter the word 'important' to boost this item in any search it appears in");

    }


    /**
     * Sets appropriate mappings for fields that need to be subsequently faceted upon
     */
    public function updateElasticMappings(array|\ArrayObject $mappings)
    {
        $mappings['BoostTerms'] = ['type' => 'text'];
        $mappings['BoostedKeywords'] = ['type' => 'keyword'];

        $mappings['Categories'] = ['type' => 'keyword'];
        $mappings['Keywords'] = ['type' => 'text'];
        $mappings['Tags'] = ['type' => 'keyword'];

        if ($this->getOwner() instanceof SiteTree) {
            // store the SS_URL for consistency
            $mappings['SS_URL'] = ['type' => 'keyword'];
        }
    }

    public function updateElasticDoc($document)
    {

        $document->set('BoostTerms', $this->getOwner()->BoostTerms->getValues());
        $document->set('BoostedKeywords', $this->getOwner()->BoostTerms->getValues());

        // expects taxonomy terms here...
        if ($this->getOwner()->hasMethod('Terms')) {
            $categories = $this->getOwner()->Terms()->column('Name');

            $currentCats = $document->has('Categories') ? $document->get('Categories') : [];

            $document->set('Categories', array_merge($currentCats, $categories));
            $document->set('Keywords', implode(' ', $categories));
        }

        if ($this->getOwner()->hasMethod('Tags')) {
            $tags = $this->getOwner()->Tags()->column('Title');
            $currentCats = $document->has('Tags') ? $document->get('Tags') : [];
            $document->set('Tags', array_merge($currentCats, $tags));
        }


        if ($this->getOwner() instanceof SiteTree) {
            // store the SS_URL for consistency
            $document->set('SS_URL', $this->getOwner()->RelativeLink());
        }
    }
}
