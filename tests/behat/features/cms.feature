Feature: Core CMS workflows
  Quanta's public pages and content should keep working after a change.

  Background:
    Given a fresh Quanta site

  Scenario: Doctor installs the generic profile
    When I visit "/home/"
    Then the page should contain "Your Homepage"
    And the page should contain "brand new QUANTA Homepage"

  Scenario: Qtags render into HTML
    When I visit "/home/"
    Then the page should have no unresolved Qtags
    And the page should contain a link to "/en/animals"

  Scenario: The missing-content page has a useful message
    When I visit "/404/"
    Then the page should contain "Apparently the content you were looking for, is not here!"

  Scenario: A user can sign in with the correct password
    When I sign in as "administrator" with password "behat-test-password"
    Then the current username should be "administrator"

  Scenario: A wrong password does not sign the user in
    When I sign in as "administrator" with password "incorrect-password"
    Then the current username should be "anonymous"

  Scenario: Signing out clears the authenticated session
    Given I sign in as "administrator" with password "behat-test-password"
    When I sign out
    Then the current username should be "anonymous"

  Scenario: A new node is saved and loaded through the CMS API
    When I add a page "behat-article" titled "A new article" with body "Hello from Behat"
    Then loading "behat-article" should return title "A new article"

  Scenario: A newly created page is rendered over HTTP
    Given I add a page "behat-article" titled "A new article" with body "Hello from Behat"
    When I visit "/behat-article/"
    Then the page should contain "A new article"
    And the page should contain "Hello from Behat"

  Scenario: Each scenario starts with clean content
    Then the page "behat-article" should not exist

  @javascript
  Scenario: Unknown pages redirect in a real browser
    When I visit "/no-such-page/"
    Then the browser should redirect to the missing-content page
