<?php
namespace Quanta\Common;

/**
 * Class ElasticSearchEngine
 * Provides ElasticSearch node indexing on cron, full-text querying, and field facet aggregation (#58).
 */
class ElasticSearchEngine {
  /** @var Environment */
  protected $env;

  /** @var string */
  protected $endpoint;

  /** @var string */
  protected $indexName;

  public function __construct($env, string $endpoint = 'http://localhost:9200', string $indexName = 'quanta_nodes') {
    $this->env = $env;
    $this->endpoint = rtrim(getenv('QUANTA_ELASTICSEARCH_URL') ?: $endpoint, '/');
    $this->indexName = getenv('QUANTA_ELASTICSEARCH_INDEX') ?: $indexName;
  }

  /**
   * Indexes a single Quanta Node into ElasticSearch.
   */
  public function indexNode(Node $node): bool {
    if (!$node->exists) {
      return FALSE;
    }
    $doc = [
      'name' => $node->getName(),
      'title' => $node->getTitle(),
      'teaser' => $node->getTeaser(),
      'body' => strip_tags((string) $node->getBody()),
      'father' => $node->getFather() ? $node->getFather()->getName() : '',
      'status' => $node->getStatus(),
      'language' => $node->getLanguage(),
      'timestamp' => $node->getTimestamp(),
      'attributes' => $node->json ?? [],
    ];
    $url = $this->endpoint . '/' . urlencode($this->indexName) . '/_doc/' . urlencode($node->getName());
    return $this->request('PUT', $url, $doc) !== NULL;
  }

  /**
   * Executes a search query with optional facet filters and returns matching hits + aggregations.
   */
  public function search(string $queryText = '', array $filters = [], array $facetFields = [], int $size = 20): array {
    $must = [];
    if (!empty($queryText)) {
      $must[] = [
        'multi_match' => [
          'query' => $queryText,
          'fields' => ['title^3', 'teaser^2', 'body'],
        ],
      ];
    }

    $filterClauses = [];
    foreach ($filters as $field => $val) {
      if ($val !== '' && $val !== NULL) {
        $filterClauses[] = ['term' => [$field . '.keyword' => $val]];
      }
    }

    $aggs = [];
    foreach ($facetFields as $field) {
      $aggs[$field] = [
        'terms' => [
          'field' => $field . '.keyword',
          'size' => 25,
        ],
      ];
    }

    $payload = [
      'size' => $size,
      'query' => [
        'bool' => [
          'must' => !empty($must) ? $must : [['match_all' => (object) []]],
          'filter' => $filterClauses,
        ],
      ],
    ];
    if (!empty($aggs)) {
      $payload['aggs'] = $aggs;
    }

    $url = $this->endpoint . '/' . urlencode($this->indexName) . '/_search';
    return $this->request('POST', $url, $payload) ?: ['hits' => ['hits' => []], 'aggregations' => []];
  }

  protected function request(string $method, string $url, array $payload): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_TIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === FALSE || $code >= 400) {
      return NULL;
    }
    return json_decode($raw, TRUE);
  }
}
