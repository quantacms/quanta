Feature: Basic Quanta content and account workflows
  These scenarios use a disposable local site, synthetic accounts and real HTTP actions.

  Scenario: A visitor reads the fixture homepage
    When I visit "/home/"
    Then the page contains "Quanta behavior fixture"
    And the page contains "Welcome to the disposable site"
    And I am an anonymous visitor

  Scenario: Quanta renders its login form
    When I open the Quanta login form
    Then the login form contains username and password fields

  Scenario: Normal password login survives the next request
    When I log in as "behat-editor" with password "fixture-password-149"
    Then I am a logged-in administrator
    And the user has a persisted login timestamp

  Scenario: Wrong password does not create an authenticated session
    When I log in as "behat-editor" with password "incorrect-password"
    Then I am an anonymous visitor
    And the user has no persisted login timestamp

  Scenario: Unknown username remains anonymous
    When I log in as "missing-user" with password "fixture-password-149"
    Then I am an anonymous visitor
    And no user named "missing-user" was created

  Scenario: Logout clears the authenticated session
    Given I log in as "behat-editor" with password "fixture-password-149"
    When I log out
    Then I am an anonymous visitor

  Scenario: An administrator adds a persistent rendered node
    Given I log in as "behat-editor" with password "fixture-password-149"
    When I add a node titled "Behat article" with body "Created through the real node action"
    Then the node "behat-article" is saved with title "Behat article" and body "Created through the real node action"
    And the node "behat-article" is authored by "behat-editor"
    When I visit "/behat-article/"
    Then the page contains "Behat article"
    And the page contains "Created through the real node action"

  Scenario: An administrator edits a node through the HTTP action
    Given I log in as "behat-editor" with password "fixture-password-149"
    When I add a node titled "Behat article" with body "Initial content"
    And I edit node "behat-article" to title "Updated article" and body "Persisted replacement"
    Then the node "behat-article" is saved with title "Updated article" and body "Persisted replacement"
    When I visit "/behat-article/"
    Then the page contains "Updated article"
    And the page contains "Persisted replacement"

  Scenario: An anonymous visitor cannot add an administrator-only node
    When I add a node titled "Behat article" with body "This must not persist"
    Then no node named "behat-article" was saved
