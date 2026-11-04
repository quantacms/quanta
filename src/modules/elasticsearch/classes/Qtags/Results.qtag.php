<?php
namespace Quanta\Qtags;
use Quanta\Common\ElasticSearchEngine;

/**
 * Class Results
 * Renders ElasticSearch query results as [RESULTS] qtag (#58).
 */
class Results extends Qtag {
  public function render() {
    $engine = new ElasticSearchEngine($this->env);
    $query = isset($_GET['q']) ? (string) $_GET['q'] : (string) $this->getTarget();
    $limit = (int) ($this->getAttribute('limit') ?: 20);

    $filters = [];
    foreach ($_GET as $k => $v) {
      if (strpos($k, 'facet_') === 0 && $v !== '') {
        $filters[substr($k, 6)] = (string) $v;
      }
    }

    $res = $engine->search($query, $filters, [], $limit);
    $hits = $res['hits']['hits'] ?? [];

    if (empty($hits)) {
      return '<div class="quanta-es-results empty">No matching results found.</div>';
    }

    $html = '<div class="quanta-es-results">';
    foreach ($hits as $hit) {
      $src = $hit['_source'] ?? [];
      $name = htmlspecialchars((string) ($src['name'] ?? ''), ENT_QUOTES);
      $title = htmlspecialchars((string) ($src['title'] ?? $name));
      $teaser = htmlspecialchars((string) ($src['teaser'] ?? ''));
      $html .= '<article class="es-result-item">';
      $html .= '<h3><a href="/' . $name . '/">' . $title . '</a></h3>';
      if (!empty($teaser)) {
        $html .= '<p class="es-teaser">' . $teaser . '</p>';
      }
      $html .= '</article>';
    }
    $html .= '</div>';
    return $html;
  }
}
