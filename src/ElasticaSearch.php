<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\ORM\DataExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use Symbiote\MultiValueField\Fields\MultiValueDropdownField;
use Symbiote\MultiValueField\Fields\MultiValueTextField;
use SilverStripe\Forms\DropdownField;
use Symbiote\MultiValueField\Fields\KeyValueField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ViewableData;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\View\ArrayData;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use Psr\Log\LoggerInterface;
use Exception;
use ArrayObject;
use InvalidArgumentException;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\ToggleCompositeField;

/**
 * @author marcus
 */
class ElasticaSearch extends DataExtension
{
    private static $db = [
        'QueryType' => 'Varchar',
        'Fuzziness'      => 'Int',
        'SearchType' => 'MultiValueField', // types that a user can search within
        'SearchOnFields' => 'MultiValueField',
        'ExtraSearchFields' => 'MultiValueField',
        'BoostFields' => 'MultiValueField',
        'BoostMatchFields' => 'MultiValueField',

        'ContentMatchBoost' => 'Int',

        // faceting fields
        'FacetFields' => 'MultiValueField',
        'CustomFacetFields' => 'MultiValueField',
        'FacetMapping' => 'MultiValueField',
        'FacetQueries' => 'MultiValueField',
        'MinFacetCount' => 'Int',
        'MaxFacetResults'   => 'Int', // number of items shown in facet results
        'ExpandedResultCount' => 'Int',
        'InitialExpandField'    => 'Varchar(64)',
        // filter fields (not used for relevance, just for restricting data set)
        'FilterFields' => 'MultiValueField',
        // filters that users can explicitly choose from
        'UserFilters' => 'MultiValueField',

        'DefaultFilters' => 'MultiValueField',

        'FacetStyle' => 'Varchar',
        'ShowFacetCount' => 'Boolean',
    ];

    private static $facet_styles = [
        'Dropdown' => 'Dropdown',
        'Links' => 'Links',
        'Checkbox'  => 'Checkbox',
    ];

    /**
     *
     * @var \Symbiote\ElasticSearcha\ExtensibleElasticService
     */
    public $searchService;

    /**
     * Current result set
     * @var ArrayList
     */
    protected $currentResults;

    /**
     * URL param for current search string
     *
     * @var string
     */
    public static $filter_param = 'filter';

    /**
     *
     * @var LoggerInterface
     */
    public $logger;


    public function getSelectableFields()
    {
        // only exists due to parent page type not implementing it, but failing with a custom engine
    }

    public function updateExtensibleSearchPageCMSFields(FieldList $fields)
    {
        $objFields = $this->owner->getSelectableFields();

        $ff = NumericField::create('Fuzziness', _t('ExtensibleElasticaSearch.FUZZ', 'Term fuzziness'));
        $ff->setRightTitle('0 means only the exact spelling will be searched, 2 means that up to 2 differences will be considered');
        $fields->insertBefore('SortBy', $ff);

        $types  = SiteTree::page_type_classes();
        $source = array_combine($types, $types);

        $extraSearchTypes = Config::inst()->get(ElasticaSearch::class, 'additional_search_types');
        ksort($source);
        $source           = is_array($extraSearchTypes) ? array_merge($source, $extraSearchTypes) : $source;
        $types            = MultiValueDropdownField::create(
            'SearchType',
            _t('ExtensibleSearchPage.SEARCH_ITEM_TYPE', 'Search items of type'),
            $source
        );
        $fields->addFieldToTab('Root.Main', $types, 'Content');

        $fields->addFieldToTab(
            'Root.Main',
            MultiValueDropdownField::create(
                'SearchOnFields',
                _t('ExtensibleSearchPage.INCLUDE_FIELDS', 'Search On Fields'),
                $objFields
            ),
            'Content'
        );
        $fields->addFieldToTab(
            'Root.Main',
            MultiValueTextField::create('ExtraSearchFields', _t('ElasticSearch.EXTRA_FIELDS', 'Custom fields to search')),
            'Content'
        );

        $this->addSortFields($fields, $objFields);
        $this->addBoostFields($fields, $objFields);
        $this->addFacetFields($fields, $objFields);



        $fields->removeByName('FacetMapping');
    }

    protected function addSortFields($fields, $objFields)
    {
        $sortFields = $objFields;
        unset($sortFields['Content']);
        unset($sortFields['Groups']);
        $fields->replaceField(
            'SortBy',
            new DropdownField('SortBy', _t('ExtensibleSearchPage.SORT_BY', 'Sort By'), $sortFields)
        );
    }

    protected function addFacetFields(FieldList $fields, $objFields)
    {
        $facetMappingFields = $objFields;
        if ($this->owner->CustomFacetFields && ($cff = $this->owner->CustomFacetFields->getValues())) {
            foreach ($cff as $facetField) {
                $facetMappingFields[$facetField] = $facetField;
            }
        }

        $opts = Config::inst()->get(self::class, 'facet_styles');


        $filtering = ToggleCompositeField::create("FilterFieldsList", "Filtering and facets", [
            $kva = new KeyValueField('FilterFields', _t('ExtensibleSearchPage.FILTER_FIELDS', 'Fields to filter by')),
            $kvb = KeyValueField::create('UserFilters', _t('ExtensibleSearchPage.USER_FILTER_FIELDS', 'User selectable filters')),
            $kvdf = KeyValueField::create('DefaultFilters', _t('ExtensibleSearchPage.DEFAULT_USER_FIELDS', 'Default filters')),
            new HeaderField('FacetHeader', _t('ExtensibleSearchPage.FACET_HEADER', 'Facet Settings')),
            // new MultiValueDropdownField('FacetFields', _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields),
            // new MultiValueTextField('CustomFacetFields', _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')),
            new KeyValueField('FacetMapping', _t('ExtensibleSearchPage.FACET_MAPPING', 'Mapping of facet title to nice title'), $facetMappingFields),
            KeyValueField::create(
                'FacetQueries',
                _t('ExtensibleSearchPage.FACET_QUERIES', 'Fields to create query facets for')
            )->setRightTitle("Enter an elastic query, then the field name"),
            $kvc = KeyValueField::create(
                'FacetFields',
                _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'),
                $objFields
            ),
            $kvd = KeyValueField::create(
                'CustomFacetFields',
                _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')
            ),
            DropdownField::create('FacetStyle', _t('ExtensibleSearchPage.FACET_STYLE', 'Facet display'), $opts)->setEmptyString('Manual'),
            CheckboxField::create('ShowFacetCount', _t('ExtensibleSearchPage.SHOW_FACET_COUNT', 'Show facet count')),
            NumericField::create(
                'MaxFacetResults',
                _t('ExtensibleSearchPage.MAX_FACET_COUNT', 'Maximum results displayed in facet list'),
                20
            ),
            $tf = TextField::create('InitialExpandField', _t('ExtensibleSearchPage.INITIAL_EXPAND_FIELD', 'Initial facet to display results for')),
            $efc = NumericField::create('ExpandedResultCount', _t('ExtensibleSearchPage.EXPAND_COUNT', 'Number of expanded results to show'), '5'),
            $mfc = NumericField::create(
                'MinFacetCount',
                _t('ExtensibleSearchPage.MIN_FACET_COUNT', 'Minimum facet count for inclusion in facet results'),
                2
            ),
        ]);


        $kva->setRightTitle("FieldName in the left column, value in the right. This will be applied before the search is executed");
        $kvb->setRightTitle('Field match (FieldName:Value) on the left, label displayed on right. These are shown on the search form.');
        $kvc->setRightTitle('FieldName in left column, display label in the right');
        $kvdf->setRightTitle('Filter values selected by default - also applies to facet fields if applicable');
        $kvd->setRightTitle('FieldName in left column, display label in the right');
        $efc->setRightTitle("Number of facet hits to expand in result set. Used to display multiple result groups on the result page");
        $tf->setRightTitle('Set a field name to use for the initial expanded facet view. Requires templates to support this');
        $mfc->setRightTitle('If set to 0, all facets will be returned regardless of applied filters');

        $fields->addFieldToTab('Root.Main', $filtering, 'Content');
    }

    protected function addBoostFields($fields, $objFields)
    {
        $boostVals = [];
        for ($i = 1; $i <= 50; $i++) {
            $boostVals[$i] = $i;
        }

        $boostFields = ToggleCompositeField::create('BoostSettings', 'Boost settings', [
            NumericField::create('ContentMatchBoost', 'Boost for exact content matching'),
            new KeyValueField('BoostFields', _t('ExtensibleSearchPage.BOOST_FIELDS', 'Boost values'), $objFields, $boostVals),
            $f = new KeyValueField('BoostMatchFields', _t('ExtensibleSearchPage.BOOST_MATCH_FIELDS', 'Boost fields with field/value matches'), [], $boostVals)
        ]);

        $f->setRightTitle('Enter a field name, followed by the value to boost if found in the result set, eg "title:Home" ');

        $fields->addFieldToTab(
            'Root.Main',
            $boostFields,
            'Content'
        );
    }

    public function updateQueryBuilder($builder, $page)
    {
    }

    /**
     * Gets a list of facet based filters
     */
    public function getActiveFacets()
    {
        return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : [];
    }

    public function fieldsForFacets()
    {
        $fields      = Config::inst()->get(ElasticaSearch::class, 'facets');
        $facetFields = ['FacetFields', 'CustomFacetFields'];
        if (!$fields) {
            $fields = [];
        }
        foreach ($facetFields as $name) {
            if ($this->owner->$name && $ff = $this->owner->$name->getValues()) {
                $types = $this->owner->searchableTypes('Page');
                foreach ($ff as $f) {
                    $fieldName = $this->searchService->getIndexFieldName($f, $types);
                    if (!$fieldName) {
                        $fieldName = $f;
                    }
                    $fields[] = $fieldName;
                }
            }
        }
        return $fields;
    }

    public function facetFieldMapping()
    {

        $selected = $this->owner->FacetFields->getValues();
        if (!$selected) {
            $selected = [];
        }
        $custom = $this->owner->CustomFacetFields->getValues();
        if (!$custom) {
            $custom = [];
        }

        $all = array_merge($selected, $custom);

        return $all;
    }

    /**
     * Get the list of facet values for the given term
     *
     * @param String $term
     */
    public function currentFacets($term = null)
    {
        if (!$this->getResults()) {
            return new ArrayList([]);
        }
        $facets        = $this->getResults()->getFacets();
        $queryFacets   = $this->owner->queryFacets();
        $me            = $this->owner;
        $convertFacets = function ($term, $raw) use ($facets, $queryFacets, $me) {
            $result = [];
            foreach ($raw as $facetTerm) {
                // if it's a query facet, then we may have a label for it
                if (isset($queryFacets[$facetTerm->Name])) {
                    $facetTerm->Name = $queryFacets[$facetTerm->Name];
                }
                $sq                          = $me->SearchQuery();
                $sep                         = strlen($sq) ? '&amp;' : '';
                $facetTerm->SearchLink       = $me->Link(self::RESULTS_ACTION).'?'.$sq.$sep.self::$filter_param."[$term][]=$facetTerm->Query";
                $facetTerm->QuotedSearchLink = $me->Link(self::RESULTS_ACTION).'?'.$sq.$sep.self::$filter_param."[$term][]=&quot;$facetTerm->Query&quot;";
                $result[]                    = new ArrayData($facetTerm);
            }
            return $result;
        };
        if ($term) {
            // return just that term
            $ret    = isset($facets[$term]) ? $facets[$term] : null;
            // lets update them all and add a link parameter
            $result = [];
            if ($ret) {
                $result = $convertFacets($term, $ret);
            }
            return new ArrayList($result);
        } else {
            $all = [];
            foreach ($facets as $term => $ret) {
                $result = $convertFacets($term, $ret);
                $all    = array_merge($all, $result);
            }
            return new ArrayList($all);
        }
        return new ArrayList($facets);
    }

    /**
     * Get the list of field -> query items to be used for faceting by query
     */
    public function queryFacets()
    {
        $fields = [];
        if ($this->owner->FacetQueries && $fq     = $this->owner->FacetQueries->getValues()) {
            $fields = array_flip($fq);
        }
        return $fields;
    }

    /**
     * Returns a url parameter string that was just used to execute the current query.
     *
     * This is useful for ensuring the parameters used in the search can be passed on again
     * for subsequent queries.
     *
     * @param array $exclusions
     * 			A list of elements that should be excluded from the final query string
     *
     * @return String
     */
    public function SearchQuery()
    {
        $parts = parse_url($_SERVER['REQUEST_URI']);
        if (!$parts) {
            throw new InvalidArgumentException("Can't parse URL: ".$uri);
        }
        // Parse params and add new variable
        $params = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            if (count($params)) {
                return http_build_query($params);
            }
        }
    }
}
