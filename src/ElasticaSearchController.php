<?php

namespace Symbiote\ElasticSearch;

use ArrayObject;
use Elastica\ResultSet;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\ORM\FieldType\DBVarchar;

/**
 *
 *
 * @author marcus
 */
class ElasticaSearchController extends Extension
{
    public function updateExtensibleSearchForm(Form $form)
    {
        $page = $this->getOwner()->data();
        if (!$page) {
            return;
        }

        $filters = $page->UserFilters->getValues();
        if ($filters) {
            $filterFieldValues = [];

            $defaults = $page->DefaultFilters->getValues() ?? [];
            $filterOptions = array_keys($filters);
            if (count($defaults)) {
                foreach ($defaults as $field => $value) {
                    $index = array_search($field . ':' . $value, $filterOptions, true);
                    if ($index !== false) {
                        $filterFieldValues[] = $index;
                    }
                }
            }

            $cbsf = CheckboxSetField::create('UserFilter', '', array_values($filters));

            // $filterFieldValues = array_keys(array_values($filters));  //To set UserFilter default value on Search page

            if (isset($_GET['action_getSearchResults']) && isset($_GET['UserFilter'])) {
                $filterFieldValues = $_GET['UserFilter'];
            }

            $cbsf->setValue($filterFieldValues);
            $form->Fields()->push($cbsf);
        }

        $existingSearch = singleton(ElasticaSearchEngine::class)->getCurrentResults();
        if (
            $existingSearch &&
            isset($existingSearch['Aggregations']) &&
            count($existingSearch['Aggregations'])
        ) {

            $request = $this->getOwner()->getRequest();
            $aggregation  = $request->getVar('aggregation');

            $aggregationGroup = FieldGroup::create('AggregationOptions');

            foreach ($existingSearch['Aggregations'] as $facetType) {
                $currentLabel = null;
                $filterField = null;
                $options = [];
                foreach ($facetType as $facetItem) {
                    if (!$currentLabel) {
                        $currentLabel = $facetItem->type;
                        $filterField = $facetItem->field;
                    }

                    $options[$facetItem->key] = $facetItem->key . ($page->ShowFacetCount ? ' (' . $facetItem->doc_count . ')' : '');
                }

                if ($options !== []) {
                    $fieldName = "aggregation[{$filterField}]";
                    $values = $aggregation[$filterField] ?? [];
                    if ($page->FacetStyle === 'Dropdown') {
                        $aggregationGroup->push(
                            DropdownField::create("", $currentLabel, $options)
                                ->addExtraClass('facet-dropdown')
                                ->setEmptyString(' ')
                                ->setValue($values)
                        );
                    } elseif ($page->FacetStyle === 'Checkbox') {
                        $aggregationGroup->push(
                            CheckboxSetField::create("aggregation[{$filterField}]", $currentLabel, $options)
                                ->addExtraClass('facet-checkbox')
                                ->setValue($values)
                        );
                    }
                }
            }

            $form->Fields()->push($aggregationGroup);
        }
    }

    public function getAggregationFilters()
    {

        $request = $this->getOwner()->getRequest();

        // Determine the selected facets/aggregations.

        $aggregation  = $request->getVar('aggregation');
        $aggregations = null;
        if ($aggregation && is_array($aggregation) && count($aggregation)) {
            $aggregations = ArrayList::create();

            // Determine the display title for an aggregation.

            $facets = $this->getOwner()->data()->facetFieldMapping();
            foreach ($aggregation as $type => $filter) {
                if (!$filter) {
                    continue;
                }

                $bucket = [
                    'key' => is_array($filter) ? implode(', ', $filter) : $filter,
                    'type' => ($facets[$type] ?? $type)
                ];

                // Determine the redirect to be used when using the facet/aggregation.

                $vars = $request->getVars();
                unset($vars['url']);
                unset($vars['aggregation']);
                $link = $this->getOwner()->data()->Link('getForm');
                foreach ($vars as $var => $value) {
                    $link = HTTP::setGetVar($var, $value, $link);
                }

                $bucket['link'] = $link;
                $aggregations->push(ArrayData::create($bucket));
            }
        }

        return $aggregations;
    }

    public function isSearchFiltered(): ?bool
    {
        /** @var HTTPRequest */
        $request = $this->getOwner()->getRequest();

        if ($request->requestVar('aggregation')) {
            return true;
        }
        return null;
    }

    /**
     * Returns a set of _aggregated_ results, ie those aggregated
     * by a certain field
     */
    public function AggregatedResults(string $fieldName)
    {
        /** @var HTTPRequest */
        $request = $this->getOwner()->getRequest();

        if ($request->requestVar('aggregation')) {
            return null;
        }

        /** @var ResultSet */
        $existingSearch = singleton(ElasticaSearchEngine::class)->getCurrentElasticResult();

        if (!$existingSearch) {
            return null;
        }

        $agg = $existingSearch->getAggregation($fieldName);

        $overallList = [];

        if ($agg && isset($agg['buckets'])) {
            foreach ($agg['buckets'] as $bucket) {
                if (!isset($bucket['key']) || !strlen((string) $bucket['key'])) {
                    continue;
                }

                if (
                    !isset($bucket['top_facet_docs']['hits']['hits']) ||
                    count($bucket['top_facet_docs']['hits']['hits']) === 0
                ) {
                    continue;
                }

                $title = $bucket['key'];

                $resultList = \Symbiote\ElasticSearch\AggregateResultList::create($bucket['top_facet_docs']['hits']['hits']);

                $vars = $request->getVars();
                unset($vars['url']);
                unset($vars['aggregation']);
                $link = $this->getOwner()->data()->Link('getForm');
                $vars['aggregation'] = [$fieldName => $title];

                foreach ($vars as $var => $value) {
                    $link = HTTP::setGetVar($var, $value, $link);
                }

                $overallList[] = ArrayData::create([
                    'Title' => $title,
                    'Children' => $resultList->toArrayList(),
                    'Link' => $link,
                ]);
            }
        }

        return ArrayList::create($overallList);
    }

    /**
     * 	Process and render search results
     *
     * @deprecated Since 2018 or thereabouts. Doesn't work!
     */
    public function getSearchResults($data = null, $form = null): array
    {
        $request = $this->getOwner()->getRequest();
        $query   = $this->getOwner()->data()->getResults();
        /* @var $query SilverStripe\Elastica\ResultList */

        // Determine the selected facets/aggregations to apply.

        $aggregation = $request->getVar('aggregation');
        if ($aggregation && is_array($aggregation)) {
            // HACK sorry
            $q = $query->getQuery()->getQuery()->getParam('query');
            foreach ($aggregation as $field => $value) {
                $q->addFilter(new Query\QueryString("{$field}:\"{$value}\""));
            }
        }

        // Determine the query sorting.

        $sortBy        = $request->getVar('SortBy') ?: $this->getOwner()->SortBy;
        $sortDirection = $request->getVar('SortDirection') ?: $this->getOwner()->SortDirection;
        $query->getQuery()->setSort([
            $sortBy => strtolower((string) $sortDirection)
        ]);

        $term    = $request->getVar('Search') ? Convert::raw2xml($request->getVar('Search')) : '';
        $message = '';

        try {
            $results = $query ? $query->getDataObjects(
                $this->getOwner()->ResultsPerPage,
                $request->getVar('start') ?: 0
            ) : ArrayList::create();
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            $message = 'Search failed';
            $query   = null;
            $results = ArrayList::create();
        }

        $elapsed = '< 0.001';

        $count = ($query && ($total = $query->getTotalResults())) ? $total : 0;
        if ($query) {
            $resultData = [
                'TotalResults' => $count
            ];
            $time       = $query->getTimeTaken();
            if ($time) {
                $elapsed = $time / 1000;
            }
        } else {
            $resultData = [];
        }

        $data = new ArrayObject([
            'Message' => $message,
            'Results' => $results,
            'Count' => $count,
            'Query' => DBVarchar::create_field('Varchar', $term),
            'Title' => $this->getOwner()->data()->Title,
            'ResultData' => ArrayData::create($resultData),
            'TimeTaken' => $elapsed,
            'RawQuery' => $query ? json_encode($query->getQuery()->toArray()) : ''
        ]);

        $this->getOwner()->extend('updateSearchResults', $data);

        return $data->getArrayCopy();
    }
}
