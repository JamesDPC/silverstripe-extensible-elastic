<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\Security\Permission;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Extensible;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\Config\Config;
use Heyday\Elastica\ElasticaService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DB;

/**
 *
 *
 * @author marcus
 */
class VersionedReindexTask extends BuildTask
{
    protected $title = 'Elastic Search Reindex to include Versioned content';

    protected $description = 'Refreshes the elastic search index including versioned content';

    public function __construct(private readonly \Heyday\Elastica\ElasticaService $service)
    {
    }

    public function run($request)
    {
        if (!Permission::check('ADMIN') && !Director::is_cli()) {
            exit("Invalid");
        }

        DB::alteration_message("Specify 'rebuild' to delete the index first, and 'reindex' to re-index content items", "notice");

        $index = $this->service->getIndex();
        $indexName = $index ? $index->getName() : '(not supplied!)';
        DB::alteration_message("Index: {$indexName}", "notice");

        if ($request->getVar('rebuild')) {
            try {
                $index->delete();
            } catch (\Exception) {
                DB::alteration_message("Index not found to be rebuilt", "notice");
            }

        }

        if ($request->getVar('rebuild') || !$index->exists()) {
            DB::alteration_message('Defining the mappings (if not already)', "notice");
            $this->service->define();
        }

        if ($request->getVar('reindex')) {
            DB::alteration_message('Refreshing the index', "notice");
            try {
                // doing this manually because the base module doesn't support versioned directly

                foreach ($this->service->getIndexedClasses() as $class) {
                    if (!Config::inst()->get($class, 'supporting_type')) {
                        //Only index types (or classes) that are not just supporting other index types
                        DB::alteration_message("Type: {$class}", "notice");
                        // Draft stage index
                        Versioned::withVersionedMode(
                            function () use ($class): void {
                                Versioned::set_stage(Versioned::DRAFT);
                                foreach ($class::get() as $record) {
                                    try {
                                        //Only index records with Show In Search enabled for Site Tree descendants
                                        //otherwise index all other data objects
                                        DB::alteration_message("Indexing Draft record #{$record->ID}/{$record->Title}", "notice");
                                        $record->reIndex('Stage');
                                    } catch (\Exception $exception) {
                                        DB::alteration_message("Failed Indexing Draft record #{$record->ID}/{$record->Title}: {$exception->getMessage()}", "error");
                                    }
                                }
                            }
                        );

                        // Live stage index
                        Versioned::withVersionedMode(
                            function () use ($class): void {
                                Versioned::set_stage(Versioned::LIVE);
                                foreach ($class::get() as $record) {
                                    try {
                                        //Only index records with Show In Search enabled for Site Tree descendants
                                        //otherwise index all other data objects
                                        DB::alteration_message("Indexing Live record #{$record->ID}/{$record->Title}", "notice");
                                        $record->reIndex('Live');
                                    } catch (\Exception $exception) {
                                        DB::alteration_message("Failed Indexing Live record #{$record->ID}/{$record->Title}: {$exception->getMessage()}", "error");
                                    }
                                }
                            }
                        );
                    } else {
                        DB::alteration_message("Skip type supporting_type: {$class}", "notice");
                    }
                }
            } catch (\Exception $exception) {
                DB::alteration_message("Some failures detected when indexing: " . $exception->getMessage(), "error");
            }
        }

        if ($request->getVar('remove')) {
            [$id, $type] = explode(',', (string) $request->getVar('remove'));
            if (!$id && !$type) {
                DB::alteration_message("Missing ID and Type for deleting from the index", "error");
                return;
            }

            $qb    = new \Elastica\QueryBuilder();

            $buildQuery = $qb->query();
            $matchId = $buildQuery->match('ID', $id);
            $matchType = $buildQuery->match('ClassNameHierarchy', $type);

            $fullQuery = $buildQuery->bool()->addFilter($matchId)->addFilter($matchType);

            $query = new \Elastica\Query();
            $query->setQuery($fullQuery);

            /** @var \Elastica\ResultSet */
            $result = $this->service->search($query, null, false);

            if ($result->count() > 0) {
                $results = $result->getResults();

                $docs = [];

                foreach ($results as $result) {
                    if ($result->getId()) {
                        DB::alteration_message("Removing " . $result->getId(), "notice");
                        $docs[] = $result->getDocument();
                    }
                }

                if ($request->getVar('confirm') && count($docs)) {
                    $index->deleteDocuments($docs);
                }
            }
        }
    }
}
