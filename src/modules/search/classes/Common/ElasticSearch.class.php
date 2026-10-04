<?php

namespace Quanta\Common;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

/**
 * Elasticsearch-backed search and faceting for Quanta.
 *
 * A full sync tags every visible document with a unique sync id and removes
 * anything left behind. That means deleted, unpublished, or newly-inaccessible
 * nodes do not linger in search results after the next cron run.
 */
class ElasticSearch {
  private Environment $env;
  private Client $client;
  private string $index;

  public function __construct(Environment $env) {
    $this->env = $env;
    $host = (string) $env->getData('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200');
    $fallback_index = 'quanta-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower((string) $env->host));
    $this->index = (string) $env->getData('ELASTICSEARCH_INDEX', $fallback_index);
    $this->client = ClientBuilder::create()->setHosts(array($host))->build();
  }

  public function getIndex(): string {
    return $this->index;
  }

  /**
   * Create the index with stable mappings for the fields Quanta owns.
   */
  public function ensureIndex(): void {
    if ($this->client->indices()->exists(array('index' => $this->index))->asBool()) {
      return;
    }

    $this->client->indices()->create(array(
      'index' => $this->index,
      'body' => array(
        'mappings' => array(
          'properties' => array(
            'name' => array('type' => 'keyword'),
            'title' => array(
              'type' => 'text',
              'fields' => array('keyword' => array('type' => 'keyword')),
            ),
            'teaser' => array('type' => 'text'),
            'body' => array('type' => 'text'),
            'status' => array('type' => 'keyword'),
            'timestamp' => array('type' => 'long'),
            '_quanta_sync' => array('type' => 'keyword'),
            'fields' => array('type' => 'object', 'dynamic' => TRUE),
          ),
        ),
      ),
    ));
  }

  /**
   * Fully synchronize the public search index.
   *
   * @return array{indexed:int,deleted:int}
   */
  public function sync(): array {
    $this->ensureIndex();
    $sync_id = bin2hex(random_bytes(16));
    $names = $this->env->db()->find(
      array('name_prefix' => ''),
      array('return' => 'names')
    );

    $indexed = 0;
    foreach ($names as $name) {
      $node = NodeFactory::load($this->env, $name, NULL, TRUE);

      // Search is a public read surface. Never index an unavailable node.
      if (!$node->exists || $node->forbidden || !$node->isPublished()) {
        continue;
      }

      $document = $this->document($node);
      $document['_quanta_sync'] = $sync_id;

      $this->client->index(array(
        'index' => $this->index,
        'id' => $name,
        'body' => $document,
      ));
      $indexed++;
    }

    $deleted = $this->pruneStale($sync_id);
    $this->client->indices()->refresh(array('index' => $this->index));

    return array(
      'indexed' => $indexed,
      'deleted' => $deleted,
    );
  }

  /**
   * Remove documents not touched by the current full sync.
   *
   * Kept public so the cleanup contract can be integration-tested without
   * requiring a complete Quanta site fixture.
   */
  public function pruneStale(string $sync_id): int {
    $result = $this->client->deleteByQuery(array(
      'index' => $this->index,
      'conflicts' => 'proceed',
      'refresh' => TRUE,
      'body' => array(
        'query' => array(
          'bool' => array(
            'must_not' => array(
              array('term' => array('_quanta_sync' => $sync_id)),
            ),
          ),
        ),
      ),
    ))->asArray();

    return (int) ($result['deleted'] ?? 0);
  }

  public function search(string $query = '', array $facets = array(), int $size = 20): array {
    $must = $query === ''
      ? array(array('match_all' => new \stdClass()))
      : array(array('multi_match' => array(
          'query' => $query,
          'fields' => array('title^3', 'teaser^2', 'body', 'fields.*'),
        )));

    $filter = array();
    foreach ($facets as $field => $value) {
      if (!$this->validFacetField($field) || !is_scalar($value) || (string) $value === '') {
        continue;
      }
      $filter[] = array(
        'term' => array('fields.' . $field . '.keyword' => (string) $value),
      );
    }

    $result = $this->client->search(array(
      'index' => $this->index,
      'body' => array(
        'query' => array(
          'bool' => array(
            'must' => $must,
            'filter' => $filter,
          ),
        ),
        'size' => max(1, min(100, $size)),
      ),
    ));

    return $result->asArray();
  }

  public function facet(string $field, string $query = '', array $selected = array(), int $size = 25): array {
    if (!$this->validFacetField($field)) {
      return array();
    }

    $must = $query === ''
      ? array(array('match_all' => new \stdClass()))
      : array(array('multi_match' => array(
          'query' => $query,
          'fields' => array('title^3', 'teaser^2', 'body', 'fields.*'),
        )));

    $filter = array();
    foreach ($selected as $key => $value) {
      if ($key === $field || !$this->validFacetField($key) || !is_scalar($value) || (string) $value === '') {
        continue;
      }
      $filter[] = array(
        'term' => array('fields.' . $key . '.keyword' => (string) $value),
      );
    }

    $result = $this->client->search(array(
      'index' => $this->index,
      'body' => array(
        'query' => array(
          'bool' => array(
            'must' => $must,
            'filter' => $filter,
          ),
        ),
        'size' => 0,
        'aggs' => array(
          'facet' => array(
            'terms' => array(
              'field' => 'fields.' . $field . '.keyword',
              'size' => max(1, min(100, $size)),
            ),
          ),
        ),
      ),
    ))->asArray();

    return $result['aggregations']['facet']['buckets'] ?? array();
  }

  private function document(Node $node): array {
    return array(
      'name' => $node->getName(),
      'title' => (string) $node->getTitle(),
      'teaser' => strip_tags((string) $node->getTeaser()),
      'body' => strip_tags((string) $node->getBody()),
      'status' => (string) $node->getStatus(),
      'timestamp' => (int) $node->getTimestamp(),
      'fields' => $this->safeCustomFields($node),
    );
  }

  /**
   * Keep custom fields useful for search/facets without copying credentials or
   * arbitrary nested objects into a public search index.
   */
  private function safeCustomFields(Node $node): array {
    $raw = json_decode(json_encode($node->json), TRUE);
    if (!is_array($raw)) {
      return array();
    }

    $reserved = array(
      'name' => TRUE,
      'title' => TRUE,
      'teaser' => TRUE,
      'body' => TRUE,
      'status' => TRUE,
      'timestamp' => TRUE,
      'permissions' => TRUE,
    );
    $safe = array();

    foreach ($raw as $key => $value) {
      if (!is_string($key) || isset($reserved[$key]) || $this->sensitiveField($key)) {
        continue;
      }

      if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
        $safe[$key] = $value;
        continue;
      }

      if (is_array($value)) {
        $values = array();
        foreach ($value as $item) {
          if (is_string($item) || is_int($item) || is_float($item) || is_bool($item)) {
            $values[] = $item;
          }
        }
        if (!empty($values)) {
          $safe[$key] = $values;
        }
      }
    }

    return $safe;
  }

  private function sensitiveField(string $field): bool {
    return preg_match(
      '/(^|[_-])(password|passwd|pass|secret|token|api[_-]?key|private[_-]?key|permissions?)([_-]|$)/i',
      $field
    ) === 1;
  }

  private function validFacetField($field): bool {
    return is_string($field) && preg_match('/^[A-Za-z0-9_.-]+$/', $field) === 1;
  }
}
