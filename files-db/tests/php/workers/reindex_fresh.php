<?php
/**
 * Worker: a fresh process (empty per-process caches) triggers a full rebuild
 * of the derived data from the files alone and answers queries from it.
 * Prints JSON.
 */
$r = QuantaDb::reindex();
echo json_encode([
    'reindex' => ['nodes' => $r['nodes'], 'links' => $r['links']],
    'children_sub' => QuantaDb::children('sub'),
    'links_ext3' => QuantaDb::links('ext3'),
    'stats_nodes' => QuantaDb::stats()['nodes'],
    'get_ext3' => QuantaDb::get('ext3'),
]);
