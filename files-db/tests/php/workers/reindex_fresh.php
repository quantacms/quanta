<?php
/**
 * Worker: rebuild a brand-new index (QUANTA_DB_INDEX_PATH points to a fresh
 * file via env) and answer queries from it. Prints JSON.
 */
$r = QuantaDb::reindex();
echo json_encode([
    'reindex' => ['nodes' => $r['nodes'], 'links' => $r['links']],
    'children_sub' => QuantaDb::children('sub'),
    'links_ext3' => QuantaDb::links('ext3'),
    'stats_nodes' => QuantaDb::stats()['nodes'],
    'get_ext3' => QuantaDb::get('ext3'),
]);
