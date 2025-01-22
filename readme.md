# Elastic Extensible Search

An extensible search implementation for Elastic Search.

## Installation

`composer require nyeholt/silverstripe-extensible-elastic`

NOTE: if using filters on your search page, or outputting the Score in results, you'll need the following patch

https://gist.github.com/nyeholt/47be9e689b069375215c96f8ee3f865f



## Configuration

Add the following to your project's config

```yml
---
Name: elastic_config
---
nglasl\extensible\ExtensibleSearchPage:
  custom_search_engines:
    Symbiote\ElasticSearch\ElasticaSearchEngine: 'Elastic'

PageController:
  extensions:
    - 'nglasl\extensible\ExtensibleSearchExtension'
    - 'Symbiote\ElasticSearch\ElasticaSearchController'

Page:
  extensions:
    - 'Symbiote\ElasticSearch\ElasticaSearchable'

---
Name: elastica_service
After:
  - '#extensible-elasticaservice'
---
SilverStripe\Core\Injector\Injector:
  ElasticaClient:
    class: Elastica\Client
    constructor:
      host_details:
        # replace hostname
        host: 'elasticsearch host name'
        # Update the port as required
        port: 9200
        # Support a transport
        # transport: AwsAuthV4 - this is needed for AWS search service compatibility; it adds credentials support
  Symbiote\ElasticSearch\ElasticaSearch:
    properties:
      searchService: '%$Heyday\Elastica\ElasticaService'
  Heyday\Elastica\ElasticaService:
    class: Symbiote\ElasticSearch\ExtensibleElasticService
    constructor:
      # the client (\Elastica\Client)
      client: '%$ElasticaClient'
      # update your index name
      index: 'my-index'
      # logging
      logger: '%$Psr\Log\LoggerInterface'

```

To add additional types for selection in an extensible search page config; note namespaces are supported.

```
---
Name: search_page_config
---
Symbiote\ElasticSearch\ElasticaSearch:
  additional_search_types:
    My\Namespaced\Class: Friendly Label

```

Run /dev/tasks/Symbiote-ElasticSearch-VersionedReindexTask


Note: Reindex will _ONLY_ reindex items that have the Searchable extension applied. There's also
a DataDiscovery extension that will grab taxonomy terms if available.

```
---
Name: elastic_data_config
---
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Symbiote\ElasticSearch\ElasticaSearchable
    # for extra boosting options - Symbiote\ElasticSearch\DataDiscovery
```


## API

To define your own custom field structures in the elastic index, you need to

* define your field mappings for the 'rebuild' phase
* add data for those fields during the indexing phase


```
public function updateElasticMappings($mappings = []) {
    $mappings['Identifier'] = ['type' => 'keyword'];
    $mappings['ContentType'] = ['type' => 'keyword'];
}
```

```
public function updateElasticDoc(Document $document)
{
    $document->set('Identifier', $this->Identifier);
    $document->set('ContentType', $this->ContentType);
}

```

## Details

**How do I use the BoostTerms field?**

BoostTerms are used for subsequent querying, either direct through the builder or by the "Boost values" and
"Boost fields with field/value matches" options on the Extensible Search Page.

The field hint states to use the word "important" in this field to boost the record super high in result sets. This
requires you to set the "Boost fields with field/value matches" to have an entry of

`BoostTerms:important` : `10`

in the search page to boost records with that set. Additionally, set the "Boost values" for BoostTerms to be higher
than all other fields for any match to contribute highly.

**Why the separate ElasticaSearchable extension?**

The base Heyday Elastic module doesn't handle indexing of Versioned content directly;
ElasticaSearchable provides a few overrides that take into account versioned content.

**Can I get rid of stale results?**

You can prune old results by creating the PruneStaleResultsJob ; this
takes as parameters

* The field:value filter to use; typically something like ClassName:MyDataClass.
  If you don't want a filter applied, pass the string 'null'
* How old something should be until it's considered 'old' in strtotime format
* How frequently to run the job in seconds, ie 86400 for every day
* How many to delete in each batch, typically around 1000


```
ClassName:My\Data\Class
-1 month
86400
1000
```
