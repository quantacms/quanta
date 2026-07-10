<?php
/**
 * Worker: rapid full-document rewrites where two fields must always agree.
 * A torn read would surface as a !== b (or invalid JSON) on the reader side.
 * Usage: writer_pairs.php <node> <iterations>
 */
[, $node, $n] = $argv;
for ($i = 0; $i < (int) $n; $i++) {
    QuantaDb::put($node, [
        'a' => $i,
        'b' => $i,
        'pad' => str_repeat('x', ($i % 7) * 64),
    ]);
}
