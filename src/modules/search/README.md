# Elasticsearch search module

Quanta can index public nodes in Elasticsearch and render full-text results and
facets through Qtags.

## Install

1. Run `composer install` (the project requires `elasticsearch/elasticsearch`).
2. Add the Elasticsearch connection to the site's `.env`:

```ini
ELASTICSEARCH_HOST=http://127.0.0.1:9200
ELASTICSEARCH_INDEX=quanta-example
```

3. Run a full sync:

```sh
php src/modules/search/bin/index.php example.test /path/to/quanta
```

A full sync is deliberately authoritative. Every visible document receives a
new sync marker, then documents not touched by that run are deleted. This keeps
deleted, unpublished, or newly-inaccessible nodes from remaining searchable.

Example cron, every 10 minutes:

```cron
*/10 * * * * cd /path/to/quanta && php src/modules/search/bin/index.php example.test /path/to/quanta
```

## Qtags

`[RESULTS]` reads the `q` query parameter and renders matching nodes. The
`query=` and `limit=` attributes can override the query and result limit.

`[FACETS:category]` renders buckets for a custom field. Selecting a bucket
adds `facet[category]=value` to the query string; those selections are applied
to `[RESULTS]`.

Only published nodes visible to the anonymous/public actor are indexed. Standard
content fields are indexed directly. Custom scalar fields and scalar arrays are
available to search/facets, while credential-like field names and permission
metadata are excluded from the public search document.
