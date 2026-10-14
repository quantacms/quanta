<?php

namespace Quanta\Qtags;

use Quanta\Common\ElasticSearch;

class Results extends Qtag {
  public function render() {
    $params = is_array($this->env->query_params) ? $this->env->query_params : array();
    $query = (string) $this->getAttribute('query', $params['q'] ?? '');
    $limit = max(1, min(100, (int) $this->getAttribute('limit', 20)));
    $facets = isset($params['facet']) && is_array($params['facet']) ? $params['facet'] : array();

    try {
      $result = (new ElasticSearch($this->env))->search($query, $facets, $limit);
    }
    catch (\Throwable $e) {
      return '';
    }

    $hits = $result['hits']['hits'] ?? array();
    if (empty($hits)) {
      return '';
    }

    $html = '<ul class="search-results">';
    foreach ($hits as $hit) {
      $source = $hit['_source'] ?? array();
      $name = (string) ($source['name'] ?? ($hit['_id'] ?? ''));
      if ($name === '') {
        continue;
      }
      $title = (string) ($source['title'] ?? $name);
      $html .= '<li><a href="/' . rawurlencode($name) . '/">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</a>';
      if (!empty($source['teaser'])) {
        $html .= '<p>'
          . htmlspecialchars((string) $source['teaser'], ENT_QUOTES, 'UTF-8')
          . '</p>';
      }
      $html .= '</li>';
    }

    return $html . '</ul>';
  }
}
