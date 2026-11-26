<?php

namespace Quanta\Tests\Behat;

use FilesystemIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CmsFixture
{
    private string $root;
    private string $checkout;
    private $server = null;
    private string $baseUrl;

    public function __construct()
    {
        $this->checkout = dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir() . '/quanta-behat-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/sites');
        mkdir($this->root . '/sessions', 0700);
        mkdir($this->root . '/static/tmp', 0700, true);
        foreach (array('src', 'profiles', 'vendor') as $directory) {
            symlink($this->checkout . '/' . $directory, $this->root . '/' . $directory);
        }
        putenv('QUANTA_BEHAT_ROOT=' . $this->root);
        register_shutdown_function(array($this, 'close'));

        // Ask the OS for a free local port so concurrent suites do not collide.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate the test server port: ' . $message);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = $this->root . '/server.log';
        $this->server = proc_open(
            array(PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/sessions',
                '-d', 'display_errors=0', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED),
                '-S', $address, $this->checkout . '/tests/behat/router.php'),
            array(0 => array('file', '/dev/null', 'r'),
                1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')),
            $pipes,
            $this->root
        );
        if (!is_resource($this->server)) {
            throw new RuntimeException('Could not start the test server.');
        }
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            if (!proc_get_status($this->server)['running']) {
                throw new RuntimeException('Test server exited: ' . file_get_contents($log));
            }
            usleep(20000);
        }
        throw new RuntimeException('Timed out waiting for the test server.');
    }

    public function reset(): void
    {
        self::remove($this->root . '/sessions');
        mkdir($this->root . '/sessions', 0700);
        self::remove($this->root . '/sites/behat.test');
        self::remove($this->root . '/static/tmp/behat.test');
        $this->run(array(PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/sessions',
            '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), $this->checkout . '/doctor',
            'behat.test', 'setup', '--web-server-type=2', '--web-server-user=' . get_current_user(),
            '--profile=generic', '--admin-pass=behat-test-password'));

        // A small theme keeps assertions focused on CMS behavior, independent of sample CSS.
        file_put_contents(
            $this->root . '/sites/behat.test/index.html',
            '<!DOCTYPE html><html><head><title>[TITLE]</title></head><body>[MESSAGES]'
            . '<span id="current-user">[USER_ATTRIBUTE|name=username]</span>[MAIN]</body></html>'
        );
        file_put_contents($this->root . '/sites/behat.test/pages/tpl^.html', '<h1>[TITLE]</h1>[BODY]');
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    public function node(string ...$arguments): string
    {
        return $this->run(array_merge(array(PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/sessions',
            '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED),
            $this->checkout . '/tests/behat/node.php'), $arguments));
    }

    private function run(array $command): string
    {
        // Process runners can filter environment values added at runtime.
        $process = new Process($command, $this->root, array('QUANTA_BEHAT_ROOT' => $this->root));
        $process->setTimeout(30);
        $process->mustRun();
        return $process->getOutput();
    }

    public function close(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        self::remove($this->root);
        putenv('QUANTA_BEHAT_ROOT');
    }

    private static function remove(string $path): void
    {
        // Never follow the source/vendor symlinks when removing the temporary site.
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (new FilesystemIterator($path) as $entry) {
                self::remove($entry->getPathname());
            }
            rmdir($path);
        }
    }
}
