<?php

namespace Quanta\Qtags;

use Quanta\Common\ElasticSearch;

class Facets extends Qtag
{
    public function render()
    {
        $field = trim((string) $this->getTarget());
        if ($field === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $field)) {
            return '';
        }
        $query = (string) $this->getAttribute('query', $_GET['q'] ?? '');
        $selected = isset($_GET['facet']) && is_array($_GET['facet']) ? $_GET['facet'] : array();
        try {
            $buckets = (new ElasticSearch($this->env))->facet($field, $query, $selected);
        } catch (\Throwable $e) {
            return '';
        }
        if (!$buckets) {
            return '';
        }
        $html = '<ul class="search-facets search-facets-' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($buckets as $bucket) {
            $key = (string) ($bucket['key'] ?? '');
            $params = $_GET;
            $params['facet'][$field] = $key;
            $url = '?' . http_build_query($params);
            $label = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $count = (int) ($bucket['doc_count'] ?? 0);
            $html .= '<li><a href="' . $href . '">' . $label . '</a> (' . $count . ')</li>';
        }
        return $html . '</ul>';
    }
}
