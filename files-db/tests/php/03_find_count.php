<?php
/** Contract §10 scenario 9: find/count criteria matrix. */
require __DIR__ . '/_harness.php';

$root = fresh_env();
seed_node($root, 'home', []);
seed_node($root, 'home/biz', []);

QuantaDb::put('b1', ['t' => 'B1'], ['father' => 'biz']);
QuantaDb::put('b1-bookings', [], ['father' => 'b1']);
QuantaDb::put('b1-paid', [], ['father' => 'b1']);
QuantaDb::put('b1-unpaid', [], ['father' => 'b1']);

$bookings = [
    'bk-1' => ['status' => 'paid', 'amount' => 10, 'customer' => ['country' => 'IT']],
    'bk-2' => ['status' => 'paid', 'amount' => 30, 'customer' => ['country' => 'DE']],
    'bk-3' => ['status' => 'unpaid', 'amount' => 20, 'customer' => ['country' => 'IT']],
    'bk-4' => ['status' => 'paid', 'amount' => 5, 'customer' => ['country' => 'IT']],
];
foreach ($bookings as $name => $data) {
    QuantaDb::put($name, $data, ['father' => 'b1-bookings']);
    QuantaDb::link($name, $data['status'] === 'paid' ? 'b1-paid' : 'b1-unpaid');
}

// father criterion (filesystem is the truth for direct children).
eq(QuantaDb::find(['father' => 'b1-bookings']), ['bk-1', 'bk-2', 'bk-3', 'bk-4'], 'find by father');

// where equality on top-level and dot-path fields.
eq(
    QuantaDb::find(['father' => 'b1-bookings', 'where' => ['status' => 'paid']]),
    ['bk-1', 'bk-2', 'bk-4'],
    'where top-level'
);
eq(
    QuantaDb::find(['father' => 'b1-bookings', 'where' => ['customer.country' => 'IT', 'status' => 'paid']]),
    ['bk-1', 'bk-4'],
    'where dot-path AND'
);
eq(
    QuantaDb::find(['father' => 'b1-bookings', 'where' => ['amount' => 20]]),
    ['bk-3'],
    'where numeric equality'
);

// in-container criterion (symlinked members) — the BusinessBookingsTotalCount pattern.
eq(QuantaDb::find(['in' => 'b1-paid']), ['bk-1', 'bk-2', 'bk-4'], 'find in container');
eq(QuantaDb::count(['in' => 'b1-paid']), 3, 'count in container');
eq(QuantaDb::count(['father' => 'b1-bookings', 'where' => ['status' => 'unpaid']]), 1, 'count where');
eq(QuantaDb::count(['in' => 'b1-paid', 'where' => ['customer.country' => 'IT']]), 2, 'count in+where');

// name_prefix and lineage (index-backed).
eq(QuantaDb::find(['name_prefix' => 'bk-']), ['bk-1', 'bk-2', 'bk-3', 'bk-4'], 'find by name_prefix');
eq(
    QuantaDb::find(['lineage' => 'b1', 'name_prefix' => 'bk-']),
    ['bk-1', 'bk-2', 'bk-3', 'bk-4'],
    'lineage + prefix intersection'
);
eq(QuantaDb::find(['lineage' => 'b1-paid']), [], 'lineage of leaf (symlinks are not children)');

// Ordering + limit/offset. Amounts: bk-4=5, bk-1=10, bk-3=20, bk-2=30.
eq(
    QuantaDb::find(['father' => 'b1-bookings'], ['order_by' => 'json:amount', 'order' => 'desc', 'limit' => 2]),
    ['bk-2', 'bk-3'],
    'order json desc + limit'
);
eq(
    QuantaDb::find(['father' => 'b1-bookings'], ['order_by' => 'json:amount', 'order' => 'asc', 'offset' => 1, 'limit' => 1]),
    ['bk-1'],
    'order json asc + offset'
);

// return=data / return=meta.
$d = QuantaDb::find(['in' => 'b1-paid', 'where' => ['customer.country' => 'IT']], ['return' => 'data']);
eq(array_keys($d), ['bk-1', 'bk-4'], 'return data keys');
eq($d['bk-1']['amount'], 10, 'return data values');
$m = QuantaDb::find(['father' => 'b1-bookings'], ['return' => 'meta', 'limit' => 1]);
eq(array_keys($m), ['bk-1'], 'return meta keys');
eq($m['bk-1']['father'], 'b1-bookings', 'return meta father');

// Edge and error cases.
eq(QuantaDb::find(['father' => 'missing-node']), [], 'find under missing father -> []');
eq(QuantaDb::find(['father' => 'b1-bookings', 'where' => ['status' => 'refunded']]), [], 'where without matches');
throws(fn() => QuantaDb::find(['bogus' => 1]), QuantaDbException::BAD_ARGS, 'unknown criteria key');
throws(
    fn() => QuantaDb::find(['where' => ['a' => [1, 2]]]),
    QuantaDbException::BAD_ARGS,
    'non-scalar where value'
);
throws(
    fn() => QuantaDb::find([], ['return' => 'bogus']),
    QuantaDbException::BAD_ARGS,
    'invalid return mode'
);

finish();
