<?php
/**
 * Worker: shuttle a node back and forth between two fathers.
 * Usage: move_loop.php <node> <father_a> <father_b> <iterations>
 *
 * Exits non-zero if any move is rejected or if the node is ever unresolvable
 * afterwards — the point is that a relocation is never observed half-applied,
 * however many readers and compactions are running alongside it.
 */
[$bin, $node, $fa, $fb, $iters] = $argv;

for ($i = 0; $i < (int) $iters; $i++) {
    $to = ($i % 2 === 0) ? $fb : $fa;
    try {
        if (QuantaDb::move($node, $to) !== true) {
            fwrite(STDERR, "move #$i to $to returned false\n");
            exit(1);
        }
    } catch (QuantaDbException $e) {
        // Another worker holding the node's lock is the only legitimate reason
        // to fail here, and the contract gives that its own code.
        if ($e->getCode() !== QuantaDbException::LOCK_TIMEOUT) {
            fwrite(STDERR, "move #$i to $to: " . $e->getMessage() . "\n");
            exit(1);
        }
        continue;
    }
    $path = QuantaDb::path($node);
    if ($path === null || !str_ends_with($path, "/$to/$node")) {
        fwrite(STDERR, "move #$i: path is " . var_export($path, true) . ", expected under $to\n");
        exit(1);
    }
}
exit(0);
