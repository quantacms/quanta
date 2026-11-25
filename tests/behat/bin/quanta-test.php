<?php

declare(strict_types=1);

use Quanta\Common\Environment;
use Quanta\Common\NodeFactory;
use Quanta\Common\User;
use Quanta\Common\UserFactory;

$repoRoot = realpath(__DIR__ . '/../../..');
$site = getenv('QUANTA_BEHAT_SITE') ?: 'behat.local';
$command = $argv[1] ?? '';

if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(2);
}

function bootEnvironment(string $repoRoot, string $site, string $request = '/home/'): Environment
{
    if (!function_exists('t')) {
        function t(string $string, array $replace = []): string
        {
            return \Quanta\Common\Localization::t($string, $replace);
        }
    }
    $_SERVER['HTTP_HOST'] = $site;
    $_SERVER['REQUEST_URI'] = $request;
    $_SERVER['DOCUMENT_ROOT'] = $repoRoot;

    require_once $repoRoot . '/src/modules/environment/classes/Common/DataContainer.class.php';
    require_once $repoRoot . '/src/modules/environment/classes/Common/Environment.class.php';
    require_once $repoRoot . '/src/modules/user/classes/Common/UserFactory.class.php';

    $env = new Environment($site, $request, $repoRoot);
    require_once $repoRoot . '/src/autoload.php';

    $env->load();
    if (!file_exists(CLASS_MAP_FILE)) {
        $env->mapClasses();
    }

    $env->startSession();
    $vars = [];
    $env->hook('boot', $vars);

    return $env;
}

switch ($command) {
    case 'render':
        $host = $site;
        $request_uri = $argv[2] ?? '/home/';
        $docroot = $repoRoot;
        include $repoRoot . '/src/boot.php';
        break;

    case 'login':
        $password = $argv[2] ?? '';
        $env = bootEnvironment($repoRoot, $site);
        $user = UserFactory::load($env, 'administrator');
        unset($_SESSION['user']);
        $user->logIn($password);
        echo isset($_SESSION['user']) ? "LOGIN_OK\n" : "LOGIN_FAIL\n";
        break;

    case 'create-node':
        $name = $argv[2] ?? 'behat-node';
        $title = $argv[3] ?? 'Behat Node';
        $env = bootEnvironment($repoRoot, $site);
        $node = NodeFactory::buildNode($env, $name, 'pages', [
            'title' => $title,
            'body' => '<p>Created by the Behat acceptance suite.</p>',
            'teaser' => 'Acceptance-test content',
            'author' => 'administrator',
            'status' => 'node-status-published',
            'permissions' => [
                'node_add' => 'admin',
                'node_edit' => 'admin',
                'node_delete' => 'admin',
                'node_view' => 'anonymous',
            ],
        ]);
        echo $node->getTitle() . "\n";
        break;

    case 'node-title':
        $name = $argv[2] ?? '';
        $env = bootEnvironment($repoRoot, $site);
        $node = NodeFactory::load($env, $name);
        if (!$node->exists) {
            fwrite(STDERR, "NODE_NOT_FOUND\n");
            exit(1);
        }
        echo $node->getTitle() . "\n";
        break;

    default:
        fwrite(STDERR, "Unknown test command: $command\n");
        exit(2);
}
