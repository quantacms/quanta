<?php

namespace Quanta\Qtags;

use Quanta\Common\ElasticSearch;

class Results extends Qtag
{
    public function render()
    {
        $query = (string) $this->getAttribute('query', $_GET['q'] ?? '');
        $size = max(1, min(100, (int) $this->getAttribute('limit', 20)));
        $facets = isset($_GET['facet']) && is_array($_GET['facet']) ? $_GET['facet'] : array();
        try {
            $result = (new ElasticSearch($this->env))->search($query, $facets, $size);
        } catch (\Throwable $e) {
            return '';
        }
        $hits = $result['hits']['hits'] ?? array();
        if (!$hits) {
            return '';
        }
        $html = '<ul class="search-results">';
        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? array();
            $name = $source['name'] ?? ($hit['_id'] ?? '');
            $title = $source['title'] ?? $name;
            $html .= '<li><a href="/' . rawurlencode($name) . '/">' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . '</a>';
            if (!empty($source['teaser'])) {
                $html .= '<p>' . htmlspecialchars((string) $source['teaser'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $html .= '</li>';
        }
        return $html . '</ul>';
    }
}
