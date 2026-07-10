<?php
/** Worker: N locked increments on a node. Usage: increment.php <node> <n> */
[, $node, $n] = $argv;
for ($i = 0; $i < (int) $n; $i++) {
    QuantaDb::update($node, function ($cur) {
        $cur['n']++;
        return $cur;
    });
}
