<?php

namespace Quanta\Tests\Behat;

use Behat\Behat\Context\Context;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\Mink\Session;
use RuntimeException;
use LogicException;
use SilverStripe\MinkFacebookWebDriver\FacebookWebDriver;
use Symfony\Component\BrowserKit\HttpBrowser;

require_once __DIR__ . '/CmsFixture.php';

final class CmsContext implements Context
{
    private static ?CmsFixture $fixture = null;
    private Session $session;

    public function __construct(private string $browser = 'browserkit')
    {
        $driver = $browser === 'chrome'
            ? new FacebookWebDriver(
                'chrome',
                array(
                    'browserName' => 'chrome',
                    'goog:chromeOptions' => array('args' => array('--headless=new', '--no-sandbox')),
                ),
                getenv('SELENIUM_URL') ?: 'http://127.0.0.1:4444/wd/hub'
            )
            : new BrowserKitDriver(new HttpBrowser());
        $this->session = new Session($driver);
    }

    /** @BeforeSuite */
    public static function start(): void
    {
        self::$fixture = new CmsFixture();
    }

    /** @AfterSuite */
    public static function stop(): void
    {
        self::$fixture?->close();
        self::$fixture = null;
    }

    /** @AfterScenario */
    public function closeBrowser(): void
    {
        $driver = $this->session->getDriver();
        if ($driver instanceof FacebookWebDriver) {
            // Release the entire WebDriver session, including any extra windows.
            $driver->getWebDriver()?->quit();
        } elseif ($this->session->isStarted()) {
            $this->session->stop();
        }
    }

    /** @Given a fresh Quanta site */
    public function freshSite(): void
    {
        self::$fixture->reset();
    }

    /** @When I visit :path */
    public function visit(string $path): void
    {
        $this->session->visit(self::$fixture->url($path));
        $status = $this->browser === 'browserkit' ? $this->session->getStatusCode() : 200;
        if ($status >= 500) {
            throw new RuntimeException('The CMS returned HTTP ' . $status);
        }
    }

    /** @Then the page should contain :text */
    public function contains(string $text): void
    {
        if (!str_contains($this->session->getPage()->getText(), $text)) {
            throw new RuntimeException('Page did not contain: ' . $text
                . "\nResponse: " . $this->session->getPage()->getText());
        }
    }

    /** @Then the page should have no unresolved Qtags */
    public function resolvedQtags(): void
    {
        if (preg_match('/\[[A-Z_]+(?:[|:\]])/', $this->session->getPage()->getContent(), $matches)) {
            throw new RuntimeException('Unresolved Qtag: ' . $matches[0]);
        }
    }

    /** @Then the page should contain a link to :path */
    public function hasLink(string $path): void
    {
        foreach ($this->session->getPage()->findAll('css', 'a[href]') as $link) {
            if (parse_url($link->getAttribute('href'), PHP_URL_PATH) === $path) {
                return;
            }
        }
        throw new RuntimeException('No link to ' . $path . "\n" . $this->session->getPage()->getContent());
    }

    /** @When I sign in as :username with password :password */
    public function signIn(string $username, string $password): void
    {
        $this->action(array('action' => array('value' => array('login')),
            'username' => array('value' => array($username)),
            'password' => array('value' => array($password))));
    }

    /** @When I sign out */
    public function signOut(): void
    {
        $this->action(array('action' => array('value' => array('logout'))));
    }

    private function action(array $data): void
    {
        $driver = $this->session->getDriver();
        if (!$driver instanceof BrowserKitDriver) {
            throw new LogicException('Authentication API steps require the default BrowserKit profile.');
        }
        if (!$this->session->isStarted()) {
            $this->session->start();
        }
        // Match the UI's POST envelope and retain the browser's normal session cookie.
        $driver->getClient()->request('POST', self::$fixture->url('/'), array(
            'json' => json_encode($data, JSON_THROW_ON_ERROR),
        ));
        json_decode($driver->getClient()->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->visit('/home/');
    }

    /** @Then the current username should be :username */
    public function currentUser(string $username): void
    {
        $element = $this->session->getPage()->find('css', '#current-user');
        if ($element === null || trim($element->getText()) !== $username) {
            throw new RuntimeException('Expected current user ' . $username . ', got '
                . ($element?->getText() ?? '(missing user element)'));
        }
    }

    /** @Given I add a page :name titled :title with body :body */
    public function addPage(string $name, string $title, string $body): void
    {
        self::$fixture->node('add', $name, $title, $body);
    }

    /** @Then loading :name should return title :title */
    public function checkTitle(string $name, string $title): void
    {
        $node = json_decode(self::$fixture->node('load', $name), true, 512, JSON_THROW_ON_ERROR);
        if (!$node['exists'] || $node['title'] !== $title) {
            throw new RuntimeException('Node was not saved correctly: ' . $name);
        }
    }

    /** @Then the browser should redirect to the missing-content page */
    public function missingPageRedirect(): void
    {
        $this->session->wait(3000, 'window.location.pathname === "/404/"');
        if (parse_url($this->session->getCurrentUrl(), PHP_URL_PATH) !== '/404/') {
            throw new RuntimeException('The browser did not follow the missing-page redirect.');
        }
        $this->contains('Apparently the content you were looking for, is not here!');
    }

    /** @Then the page :name should not exist */
    public function noPage(string $name): void
    {
        $node = json_decode(self::$fixture->node('load', $name), true, 512, JSON_THROW_ON_ERROR);
        if ($node['exists']) {
            throw new RuntimeException('Unexpected content left by a previous scenario: ' . $name);
        }
    }
}
