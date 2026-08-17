<?php
/** Bootstrap sanity: a real Environment boots and $env->db() answers. */
require __DIR__ . '/_bootstrap.php';

list($env, $site, $db) = quanta_env();

ok($env instanceof \Quanta\Common\Environment, 'Environment booted');
eq($env->dir['docroot'], $site, 'docroot points at the throwaway site');
ok($env->db() instanceof \Quanta\Common\FilesDb, 'env->db() is the FilesDb shim');
eq($env->db()->available(), qdb_ext(), 'available() tracks the extension');
eq($env->db()->coherent(), qdb_daemon_mode(), 'coherent() tracks the daemon');
ok_ext(fn() => is_string(\QuantaDb::version()), 'extension reports a version');

finish();
