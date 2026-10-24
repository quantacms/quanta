<?php

require __DIR__ . '/fixture.php';
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/.quanta-behat-owned')) {
    throw new RuntimeException('The disposable fixture marker is required.');
}
$app = $root . '/app';
$site = $app . '/sites/127.0.0.1';
$tmp = $app . '/static/tmp/127.0.0.1';
fixtureRemove($site, $root);
fixtureRemove($tmp, $root);
foreach (['db/_users', 'db/_roles', 'db/_languages', 'db/_translations', 'db/_statuses',
    '_modules', '_tpl', '_system', '_mailqueue', 'pages'] as $directory) {
    mkdir($site . '/' . $directory, 0770, true);
}
foreach (['', '/cache', '/files', '/log', '/thumbs', '/trashbin'] as $directory) {
    mkdir($tmp . $directory, 0770, true);
}
$permissions = ['node_view' => 'anonymous', 'node_add' => 'admin', 'node_edit' => 'admin', 'node_delete' => 'admin'];
fixtureJson($site . '/pages', ['title' => 'Pages', 'permissions' => $permissions]);
fixtureJson($site . '/pages/home', ['title' => 'Quanta behavior fixture', 'body' => 'Welcome to the disposable site',
    'status' => 'node-status-published', 'permissions' => $permissions]);
fixtureWrite($site . '/pages/tpl^.html', '<h1>[TITLE]</h1><div class="content">[BODY]</div>');
fixtureWrite($site . '/index.html', '<!doctype html><html><head><title>[TITLE]</title></head>' .
    '<body class="[BODY_CLASSES]"><nav><a href="/home/">Home</a></nav>[MESSAGES][MAIN]</body></html>');
foreach (['anonymous', 'logged', 'admin'] as $role) {
    fixtureJson($site . '/db/_roles/' . $role, ['title' => ucfirst($role)]);
}
foreach (['403' => 'Forbidden', '404' => 'Not found'] as $code => $title) {
    fixtureJson($site . '/_system/' . $code, ['title' => $title, 'body' => $title, 'permissions' => $permissions]);
}

// Seed through Quanta's actual user factory/password encoder/save path. Requests
// under test use the normal HTTP login action, never forced login or this seed.
require $app . '/src/modules/environment/classes/Common/DataContainer.class.php';
require $app . '/src/modules/environment/classes/Common/Environment.class.php';
require $app . '/src/modules/environment/classes/Common/Logger.class.php';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/home/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
$env = new \Quanta\Common\Environment('127.0.0.1', '/home/', $app);
require $app . '/src/autoload.php';
function t($string, $replace = [])
{
    return \Quanta\Common\Localization::t($string, $replace);
}
$env->load();
$env->mapClasses();
$env->hook('boot');
$env->setData('language', \Quanta\Common\Localization::LANGUAGE_NEUTRAL);
\Quanta\Common\UserFactory::buildUser($env, 'behat-editor', [
    'title' => 'Synthetic editor', 'password' => 'fixture-password-149', 'roles' => ['admin'],
    'email' => 'editor@example.invalid',
]);
