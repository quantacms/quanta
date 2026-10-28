<?php

// Run API operations in a fresh process: Quanta's class map is site-specific.
if (PHP_SAPI !== 'cli' || !getenv('QUANTA_BEHAT_ROOT')) {
    exit(1);
}

$root = getenv('QUANTA_BEHAT_ROOT');
require $root . '/src/modules/environment/classes/Common/DataContainer.class.php';
require $root . '/src/modules/environment/classes/Common/Environment.class.php';
$_SERVER['HTTPS'] = '';
$env = new \Quanta\Common\Environment('behat.test', '/home/', $root);
require $root . '/src/autoload.php';
function t($text, $replace = array())
{
    return \Quanta\Common\Localization::t($text, $replace);
}
$env->load();
$env->hook('boot');
$env->setData('language', 'en');

if ($argv[1] === 'add') {
    \Quanta\Common\NodeFactory::buildNode($env, $argv[2], 'pages', array(
        'title' => $argv[3],
        'body' => $argv[4],
        'status' => 'node-status-published',
        'permissions' => array('node_view' => 'anonymous'),
    ), 'en');
} else {
    $node = \Quanta\Common\NodeFactory::load($env, $argv[2], 'en');
    echo json_encode(array('exists' => $node->exists, 'title' => $node->getTitle()), JSON_THROW_ON_ERROR);
}
