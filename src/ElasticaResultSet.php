<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\ORM\ArrayList;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\PaginatedList;
use stdClass;

/**
 * A result set that provides access to results of a solr query, either as
 * a data object set, or as more specific solr items
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class ElasticaResultSet
{
    /**
     * The actual decoded search result
     *
     * @var StdClass
     */
    protected $result;

    /**
     * The list of data objects that is represented by this search result set
     *
     * @var DataObjectSet
     */
    protected $dataObjects;

    /**
     * The total number of results found in this query
     *
     * @var Int
     */
    protected $totalResults;

    /**
     * Create a new result set object
     *
     * @param $query
     *			The raw lucene query issued
     * @param string $query
     * @param string $rawResponse
     * @param \StdClass $parameters
     */
    public function __construct(
        /**
         * The raw lucene query issued
         */
        protected $query,
        /**
         * The raw result from elastic
         */
        protected $response,
        /**
         * The query parameters that were used for the query
         */
        protected $queryParameters,
        protected $searchService
    )
    {
    }

    public function getErrors()
    {

    }

    /**
     * Get all the parameters used in this query
     *
     */
    public function getQueryParameters()
    {
        return $this->queryParameters;
    }


    /**
     * Gets the raw result set as an object graph.
     *
     * This is effectively the results as sent from solre
     */
    public function getResult()
    {
        if (!$this->result && $this->response && $this->response->getHttpStatus() >= 200 && $this->response->getHttpStatus() < 300) {
            // decode the response
            $this->result = json_decode((string) $this->response->getRawResponse());
        }

        return $this->result;
    }

    /**
     * The number of results found for the given parameters.
     *
     * @return Int
     */
    public function getTotalResults()
    {
        return $this->totalResults;
    }

    /**
     * Return all the dataobjects that were found in this query
     *
     * @param $evaluatePermissions
     *			Should we evaluate whether the user can view before adding the result to the dataset?
     *
     * @return DataObjectSet
     */
    public function getDataObjects($evaluatePermissions = false, $expandRawObjects = true)
    {
        if (!$this->dataObjects) {
            $this->dataObjects = ArrayList::create();

            $result = $this->getResult();
            $documents = $result && isset($result->response) ? $result->response : null;

            if ($documents && isset($documents->docs)) {
                $totalAdded = 0;
                foreach ($documents->docs as $doc) {
                    $bits = explode('_', $doc->id);
                    if (count($bits) == 3) {
                        [$type, $id, $stage] = $bits;
                    } else {
                        [$type, $id] = $bits;
                        $stage = Versioned::current_stage();
                    }

                    if (!$type || !$id) {
                        error_log("Invalid solr document ID $doc->id");
                        continue;
                    }

                    if (str_starts_with($doc->id, '@TODO_RAW_THINGS')) {
                        $object = $this->inflateRawResult($doc, $expandRawObjects);
                    } else {
                        if (!class_exists($type)) {
                            continue;
                        }

                        // a double sanity check for the stage here.
                        if (($currentStage = Versioned::current_stage()) && $currentStage != $stage) {
                            continue;
                        }

                        $object = DataObject::get_by_id($type, $id);
                    }

                    if ($object && $object->ID) {
                        // check that the user has permission
                        if (isset($doc->score)) {
                            $object->SearchScore = $doc->score;
                        }

                        $canAdd = true;
                        // check if we've got a way of evaluating perms
                        if ($evaluatePermissions && $object->hasMethod('canView')) {
                            $canAdd = $object->canView();
                        }

                        if (!$evaluatePermissions || $canAdd) {
                            if ($object->hasMethod('canShowInSearch')) {
                                if ($object->canShowInSearch()) {
                                    $this->dataObjects->push($object);
                                }
                            } else {
                                $this->dataObjects->push($object);
                            }
                        }

                        $totalAdded++;
                    } else {
                        error_log("Object $doc->id is no longer in the system, removing from index");
                        $this->searchService->remove($doc);
                    }
                }

                $this->totalResults = $documents->numFound;

                // update the dos with stats about this query

                $this->dataObjects = PaginatedList::create($this->dataObjects);

                $this->dataObjects->setPageLength($this->queryParameters->limit)
                        ->setPageStart($documents->start)
                        ->setTotalItems($documents->numFound)
                        ->setLimitItems(false);
            }

        }

        return $this->dataObjects;
    }


    protected $returnedFacets;

    /**
     * Gets the details about facets found in this query
     *
     * @return array
     *			An array of facet values in the format
     *			array(
     *				'field_name' => stdClass {
     *					name,
     *					count
     *				}
     *			)
     */
    public function getFacets()
    {
        if ($this->returnedFacets) {
            return $this->returnedFacets;
        }

        $result = $this->getResult();
        if (!isset($result->facet_counts)) {
            return;
        }

        if (isset($result->facet_counts->exception)) {
            // $this->logger->error($result->facet_counts->exception)
            return [];
        }

        $elems = $result->facet_counts->facet_fields;

        $facets = [];
        foreach ($elems as $field => $values) {
            $elemVals = [];
            foreach ($values as $vname => $vcount) {
                if ($vname == '_empty_') {
                    continue;
                }

                $r = new stdClass();
                $r->Name = $vname;
                $r->Query = $vname;
                $r->Count = $vcount;
                $elemVals[] = $r;
            }

            $facets[$field] = $elemVals;
        }

        // see if there's any query facets for things too
        $query_elems = $result->facet_counts->facet_queries;
        if ($query_elems) {
            foreach ($query_elems as $vname => $count) {
                if ($vname == '_empty_') {
                    continue;
                }

                [$field, $query] = explode(':', $vname);

                $r = new stdClass();
                $r->Type = 'query';
                $r->Name = $vname;
                $r->Query = $query;
                $r->Count = $count;

                $existing = $facets[$field] ?? [];
                $existing[] = $r;
                $facets[$field] = $existing;
            }
        }

        $this->returnedFacets = $facets;
        return $this->returnedFacets;
    }

    /**
     * Gets the query's elapsed time.
     *
     * @return Int
     */
    public function getTimeTaken()
    {
        return ($this->result ? $this->result->responseHeader->QTime : null);
    }
}
