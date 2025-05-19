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
 * @property ?string $QueryType
 * @property int $Fuzziness
 * @property mixed $SearchType
 * @property mixed $SearchOnFields
 * @property mixed $ExtraSearchFields
 * @property mixed $BoostFields
 * @property mixed $BoostMatchFields
 * @property int $ContentMatchBoost
 * @property mixed $FacetFields
 * @property mixed $CustomFacetFields
 * @property mixed $FacetMapping
 * @property mixed $FacetQueries
 * @property int $MinFacetCount
 * @property int $MaxFacetResults
 * @property int $ExpandedResultCount
 * @property ?string $InitialExpandField
 * @property mixed $FilterFields
 * @property mixed $UserFilters
 * @property mixed $DefaultFilters
 * @property ?string $FacetStyle
 * @property bool $ShowFacetCount
 * @extends \SilverStripe\ORM\DataExtension<(\nglasl\extensible\ExtensibleSearchPage & static)>
 */
class ElasticaSearch extends DataExtension
{
    private static array $db = [
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

    private static array $facet_styles = [
        'Dropdown' => 'Dropdown',
        'Links' => 'Links',
        'Checkbox'  => 'Checkbox',
    ];

    /**
     *
     * @var \Symbiote\ElasticSearch\ExtensibleElasticService
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
        $objFields = $this->getOwner()->getSelectableFields();

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
            \SilverStripe\Forms\DropdownField::create('SortBy', _t('ExtensibleSearchPage.SORT_BY', 'Sort By'), $sortFields)
        );
    }

    protected function addFacetFields(FieldList $fields, $objFields)
    {
        $facetMappingFields = $objFields;
        if ($this->getOwner()->CustomFacetFields && ($cff = $this->getOwner()->CustomFacetFields->getValues())) {
            foreach ($cff as $facetField) {
                $facetMappingFields[$facetField] = $facetField;
            }
        }

        $opts = Config::inst()->get(self::class, 'facet_styles');

        $filtering = ToggleCompositeField::create(
            "FilterFieldsList",
            "Filtering and facets",
            [
                $kva = \Symbiote\MultiValueField\Fields\KeyValueField::create(
                    'FilterFields',
                    _t('ExtensibleSearchPage.FILTER_FIELDS', 'Fields to filter by')
                ),
                $kvb = KeyValueField::create(
                    'UserFilters',
                    _t('ExtensibleSearchPage.USER_FILTER_FIELDS', 'User selectable filters')
                ),
                $kvdf = KeyValueField::create(
                    'DefaultFilters',
                    _t('ExtensibleSearchPage.DEFAULT_USER_FIELDS', 'Default filters')
                ),
                \SilverStripe\Forms\CompositeField::create([
                    // new MultiValueDropdownField('FacetFields', _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields),
                    // new MultiValueTextField('CustomFacetFields', _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')),
                    \Symbiote\MultiValueField\Fields\KeyValueField::create(
                        'FacetMapping',
                        _t('ExtensibleSearchPage.FACET_MAPPING', 'Mapping of facet title to nice title'),
                        $facetMappingFields
                    ),
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
                    DropdownField::create(
                        'FacetStyle',
                        _t('ExtensibleSearchPage.FACET_STYLE', 'Facet display'),
                        $opts
                    )->setEmptyString('Manual'),
                    CheckboxField::create(
                        'ShowFacetCount',
                        _t('ExtensibleSearchPage.SHOW_FACET_COUNT', 'Show facet count')
                    ),
                    NumericField::create(
                        'MaxFacetResults',
                        _t('ExtensibleSearchPage.MAX_FACET_COUNT', 'Maximum results displayed in facet list'),
                        20
                    ),
                    $tf = TextField::create(
                        'InitialExpandField',
                        _t('ExtensibleSearchPage.INITIAL_EXPAND_FIELD', 'Initial facet to display results for')
                    ),
                    $efc = NumericField::create(
                        'ExpandedResultCount',
                        _t('ExtensibleSearchPage.EXPAND_COUNT', 'Number of expanded results to show'),
                        '5'
                    ),
                    $mfc = NumericField::create(
                        'MinFacetCount',
                        _t('ExtensibleSearchPage.MIN_FACET_COUNT', 'Minimum facet count for inclusion in facet results'),
                        2
                    )
                ])->setTitle(_t('ExtensibleSearchPage.FACET_HEADER', 'Facet Settings'))
            ]
        );


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
            \Symbiote\MultiValueField\Fields\KeyValueField::create('BoostFields', _t('ExtensibleSearchPage.BOOST_FIELDS', 'Boost values'), $objFields, $boostVals),
            $f = \Symbiote\MultiValueField\Fields\KeyValueField::create('BoostMatchFields', _t('ExtensibleSearchPage.BOOST_MATCH_FIELDS', 'Boost fields with field/value matches'), [], $boostVals)
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
        return $_GET[self::$filter_param] ?? [];
    }

    public function fieldsForFacets()
    {
        $fields      = Config::inst()->get(ElasticaSearch::class, 'facets');
        $facetFields = ['FacetFields', 'CustomFacetFields'];
        if (!$fields) {
            $fields = [];
        }

        foreach ($facetFields as $name) {
            if ($this->getOwner()->$name && $ff = $this->getOwner()->$name->getValues()) {
                $types = $this->getOwner()->searchableTypes('Page');
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

    public function facetFieldMapping(): array
    {

        $selected = $this->getOwner()->FacetFields->getValues();
        if (!$selected) {
            $selected = [];
        }

        $custom = $this->getOwner()->CustomFacetFields->getValues();
        if (!$custom) {
            $custom = [];
        }

        return array_merge($selected, $custom);
    }

    /**
     * Returns a url parameter string that was just used to execute the current query.
     *
     * This is useful for ensuring the parameters used in the search can be passed on again
     * for subsequent queries.
     *
     * @return string
     */
    public function SearchQuery(): ?string
    {
        $uri = ($_SERVER['REQUEST_URI'] ?? '');
        $parts = parse_url((string) $uri);
        if ($parts === false) {
            throw new InvalidArgumentException("Can't parse URL: ".$uri);
        }

        // Parse params and add new variable
        $params = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            if ($params !== []) {
                return http_build_query($params);
            }
        }

        return null;
    }
}
