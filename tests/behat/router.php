<?php

// This router is only for the private server started by the Behat fixture.
if (PHP_SAPI !== 'cli-server' || !getenv('QUANTA_BEHAT_ROOT')) {
    http_response_code(404);
    exit;
}

$host = 'behat.test';
$docroot = getenv('QUANTA_BEHAT_ROOT');
chdir($docroot . '/src');
require $docroot . '/src/boot.php';
