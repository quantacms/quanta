<?php

namespace Quanta\Qtags;

use Quanta\Common\ElasticSearch;

class Facets extends Qtag {
  public function render() {
    $field = trim((string) $this->getTarget());
    if ($field === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $field) !== 1) {
      return '';
    }

    $params = is_array($this->env->query_params) ? $this->env->query_params : array();
    $query = (string) $this->getAttribute('query', $params['q'] ?? '');
    $selected = isset($params['facet']) && is_array($params['facet']) ? $params['facet'] : array();

    try {
      $buckets = (new ElasticSearch($this->env))->facet($field, $query, $selected);
    }
    catch (\Throwable $e) {
      return '';
    }

    if (empty($buckets)) {
      return '';
    }

    $html = '<ul class="search-facets search-facets-'
      . htmlspecialchars($field, ENT_QUOTES, 'UTF-8')
      . '">';

    foreach ($buckets as $bucket) {
      $key = (string) ($bucket['key'] ?? '');
      if ($key === '') {
        continue;
      }

      $link_params = $params;
      $link_params['facet'][$field] = $key;
      $url = '?' . http_build_query($link_params);
      $count = (int) ($bucket['doc_count'] ?? 0);

      $html .= '<li><a href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        . '">'
        . htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
        . '</a> (' . $count . ')</li>';
    }

    return $html . '</ul>';
  }
}
