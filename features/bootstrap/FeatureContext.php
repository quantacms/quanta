<?php

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\When;
use Behat\Step\Then;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

/** HTTP/DOM browser emulation, not JavaScript or a replacement application. */
final class FeatureContext implements Context
{
    private HttpBrowser $browser;
    private string $root;
    private string $url;

    #[BeforeScenario]
    public function prepare(): void
    {
        $this->root = (string) getenv('QUANTA_BEHAT_ROOT');
        $this->url = (string) getenv('QUANTA_BEHAT_URL');
        if (!is_file($this->root . '/.quanta-behat-owned') || $this->url !== 'http://127.0.0.1:8080') {
            throw new RuntimeException('Run composer test:behat; only its owned localhost fixture is supported.');
        }
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../tests/behat/seed.php', $this->root],
            [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Fixture preparation failed.');
        }
        $this->browser = new HttpBrowser(HttpClient::create(['timeout' => 10, 'max_redirects' => 5]));
    }

    private function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    #[When('I visit :path')]
    public function visit(string $path): void
    {
        $this->check(str_starts_with($path, '/') && !str_starts_with($path, '//'), 'Expected a local path.');
        $this->browser->request('GET', $this->url . $path);
        $this->check($this->browser->getResponse()->getStatusCode() === 200, 'Page returned a non-200 status.');
    }

    private function action(string $action, array $fields = []): void
    {
        $payload = ['action' => ['value' => [$action], 'type' => 'hidden', 'required' => true]];
        foreach ($fields as $name => $value) {
            $payload[$name] = ['value' => [$value], 'type' => 'string', 'required' => false];
        }
        // The same JSON-in-form envelope that Quanta's action() client sends.
        $this->browser->request('POST', $this->url . '/home/', ['json' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $this->check($this->browser->getResponse()->getStatusCode() === 200, 'Action returned a non-200 status.');
        $response = json_decode($this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->check(is_array($response), 'Action did not return a JSON object.');
    }

    #[When('I open the Quanta login form')]
    public function loginForm(): void
    {
        $shadow = ['module' => 'user', 'context' => 'user_login', 'widget' => 'single',
            'components' => ['user_login_form']];
        $this->visit('/home/?' . http_build_query(['shadow' => json_encode($shadow, JSON_THROW_ON_ERROR)]));
    }

    #[Then('the login form contains username and password fields')]
    public function formFields(): void
    {
        $page = $this->browser->getCrawler();
        $this->check($page->filter('input[name="username"]')->count() === 1, 'Missing actual username input.');
        $this->check($page->filter('input[name="password"][type="password"]')->count() === 1, 'Missing actual password input.');
    }

    #[Given('I log in as :username with password :password')]
    public function login(string $username, string $password): void
    {
        $this->action('login', ['username' => $username, 'password' => $password]);
        $this->visit('/home/');
    }

    #[When('I log out')]
    public function logout(): void
    {
        $this->action('logout');
        $this->visit('/home/');
    }

    #[Then('I am an anonymous visitor')]
    public function anonymous(): void
    {
        $page = $this->browser->getCrawler();
        $this->check($page->filter('body.user-anonymous')->count() === 1, 'Expected anonymous body state.');
        $this->check($page->filter('body.user-admin')->count() === 0, 'Unexpected administrator session.');
    }

    #[Then('I am a logged-in administrator')]
    public function administrator(): void
    {
        $this->check($this->browser->getCrawler()->filter('body.user-admin.user-logged')->count() === 1,
            'Normal-password login was not persisted across HTTP requests.');
    }

    private function userData(): array
    {
        return json_decode(file_get_contents($this->root . '/app/sites/127.0.0.1/db/_users/behat-editor/data.json'),
            true, 512, JSON_THROW_ON_ERROR);
    }

    #[Then('the user has a persisted login timestamp')]
    public function loginTimestamp(): void
    {
        $timestamps = $this->userData()['last_login'] ?? [];
        $this->check(count($timestamps) === 1 && abs(time() - (int) $timestamps[0]) < 60,
            'Normal login did not save its timestamp to the actual user JSON.');
    }

    #[Then('the user has no persisted login timestamp')]
    public function noLoginTimestamp(): void
    {
        $this->check(empty($this->userData()['last_login']), 'Failed login saved a successful-login timestamp.');
    }

    #[Then('no user named :name was created')]
    public function noUser(string $name): void
    {
        $this->check(!is_dir($this->root . '/app/sites/127.0.0.1/db/_users/' . $name), 'Unexpected user was created.');
    }

    #[When('I add a node titled :title with body :body')]
    public function addNode(string $title, string $body): void
    {
        $this->action('node_add', ['edit-title' => $title, 'edit-content' => $body, 'edit-path' => '',
            'edit-father' => 'pages', 'edit-status' => 'node-status-published',
            'node_view' => 'anonymous', 'node_add' => 'admin', 'node_edit' => 'admin', 'node_delete' => 'admin']);
    }

    #[When('I edit node :name to title :title and body :body')]
    public function editNode(string $name, string $title, string $body): void
    {
        $this->action('node_edit', ['edit-path' => $name, 'edit-title' => $title, 'edit-content' => $body]);
    }

    private function nodeData(string $name): array
    {
        $path = $this->root . '/app/sites/127.0.0.1/pages/' . $name . '/data.json';
        $this->check(is_file($path), 'Node JSON was not persisted: ' . $name);
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Then('the node :name is saved with title :title and body :body')]
    public function savedNode(string $name, string $title, string $body): void
    {
        $data = $this->nodeData($name);
        $this->check(($data['title'] ?? null) === $title && ($data['body'] ?? null) === $body,
            'Stored node fields differ from the HTTP action.');
    }

    #[Then('the node :name is authored by :author')]
    public function author(string $name, string $author): void
    {
        $this->check(($this->nodeData($name)['author'] ?? null) === $author, 'Node did not retain the logged-in author.');
    }

    #[Then('no node named :name was saved')]
    public function noNode(string $name): void
    {
        $this->check(!is_dir($this->root . '/app/sites/127.0.0.1/pages/' . $name), 'Denied node action persisted content.');
    }

    #[Then('the page contains :text')]
    public function pageContains(string $text): void
    {
        $this->check(str_contains($this->browser->getCrawler()->filter('body')->text(), $text),
            'Rendered page does not contain: ' . $text);
    }
}
