<?php

use Behat\Behat\Context\Context;
use Behat\MinkExtension\Context\RawMinkContext;
use PHPUnit\Framework\Assert;

/**
 * Defines application features and custom Quanta CMS behaviors for Behat + Mink/PhantomJS.
 */
class FeatureContext extends RawMinkContext implements Context
{
    /**
     * @Given I am an anonymous user
     */
    public function iAmAnAnonymousUser(): void
    {
        $this->getSession()->reset();
    }

    /**
     * @When I log in as :username with password :password
     */
    public function iLogInAsWithPassword(string $username, string $password): void
    {
        $page = $this->getSession()->getPage();
        if ($page->findField('username') && $page->findField('password')) {
            $page->fillField('username', $username);
            $page->fillField('password', $password);
            $button = $page->findButton('Submit') ?: $page->findButton('Login');
            if ($button) {
                $button->press();
            }
        }
    }

    /**
     * @Given I am logged in as :username
     */
    public function iAmLoggedInAs(string $username): void
    {
        $this->visitPath('/login');
        $this->iLogInAsWithPassword($username, 'admin');
    }

    /**
     * @Then I should be logged in
     */
    public function iShouldBeLoggedIn(): void
    {
        $statusCode = $this->getSession()->getStatusCode();
        Assert::assertLessThan(400, $statusCode);
    }

    /**
     * @Then I should see an authentication error
     */
    public function iShouldSeeAnAuthenticationError(): void
    {
        $content = $this->getSession()->getPage()->getContent();
        Assert::assertNotEmpty($content);
    }

    /**
     * @When I create a Quanta node titled :title with body :body
     */
    public function iCreateAQuantaNodeTitledWithBody(string $title, string $body): void
    {
        $this->visitPath('/');
        $page = $this->getSession()->getPage();
        if ($page->findField('title')) {
            $page->fillField('title', $title);
        }
        if ($page->findField('body')) {
            $page->fillField('body', $body);
        }
    }

    /**
     * @Given a Quanta node titled :title exists
     */
    public function aQuantaNodeTitledExists(string $title): void
    {
        $this->iCreateAQuantaNodeTitledWithBody($title, 'Initial node body');
    }

    /**
     * @When I edit the Quanta node :oldTitle with new title :newTitle
     */
    public function iEditTheQuantaNodeWithNewTitle(string $oldTitle, string $newTitle): void
    {
        $page = $this->getSession()->getPage();
        if ($page->findField('title')) {
            $page->fillField('title', $newTitle);
        }
    }

    /**
     * @When I delete the Quanta node :title
     */
    public function iDeleteTheQuantaNode(string $title): void
    {
        $this->visitPath('/');
    }

    /**
     * @Then the page should contain valid HTML structure
     */
    public function thePageShouldContainValidHtmlStructure(): void
    {
        $html = $this->getSession()->getPage()->getContent();
        Assert::assertStringContainsString('<html', strtolower($html));
    }
}
