# Elasticsearch search module

Quanta search uses the official `elasticsearch/elasticsearch` PHP client.

Site `.env` settings:

```ini
ELASTICSEARCH_HOST=http://127.0.0.1:9200
ELASTICSEARCH_INDEX=quanta-example
```

Index nodes from cron:

```sh
php src/modules/search/bin/index.php example.test /path/to/quanta
```

Example cron (every 10 minutes):

```cron
*/10 * * * * cd /path/to/quanta && php src/modules/search/bin/index.php example.test /path/to/quanta
```

Render search results with `[RESULTS]`. It reads the `q` query-string parameter by default; `query=` and `limit=` can override it.

Render a field facet with `[FACETS:category]`. Selected facets are carried as `facet[field]=value` query parameters and are automatically applied to `[RESULTS]`.
