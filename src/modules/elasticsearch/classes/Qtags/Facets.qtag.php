<?php
namespace Quanta\Qtags;
use Quanta\Common\ElasticSearchEngine;

/**
 * Class Facets
 * Renders faceted search filters as [FACETS:fieldname] qtag (#58).
 */
class Facets extends Qtag {
  public function render() {
    $field = $this->getTarget();
    if (empty($field)) {
      return '';
    }
    $engine = new ElasticSearchEngine($this->env);
    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    $res = $engine->search($query, [], [$field], 0);
    $buckets = $res['aggregations'][$field]['buckets'] ?? [];

    $selected = isset($_GET['facet_' . $field]) ? (string) $_GET['facet_' . $field] : '';
    $html = '<div class="quanta-es-facets" data-field="' . htmlspecialchars($field, ENT_QUOTES) . '">';
    $html .= '<ul class="facet-list">';
    foreach ($buckets as $bucket) {
      $key = (string) ($bucket['key'] ?? '');
      $count = (int) ($bucket['doc_count'] ?? 0);
      $activeClass = ($selected === $key) ? ' class="active"' : '';
      $url = '?q=' . urlencode($query) . '&facet_' . urlencode($field) . '=' . urlencode($key);
      $html .= '<li' . $activeClass . '><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
        . htmlspecialchars($key) . ' <span class="facet-count">(' . $count . ')</span></a></li>';
    }
    $html .= '</ul></div>';
    return $html;
  }
}
