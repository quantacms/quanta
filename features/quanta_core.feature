Feature: Quanta CMS Core Functionalities
  In order to prevent regressions and ensure continuous integration
  As a Quanta CMS developer and administrator
  I need automated behavioral tests for authentication, node management, and navigation

  Scenario: 1. Homepage loads successfully
    Given I am on "/"
    Then the response status code should be 200

  Scenario: 2. Anonymous user cannot access restricted admin actions
    Given I am an anonymous user
    When I go to "/admin"
    Then I should see "Login"

  Scenario: 3. User can log in with valid credentials
    Given I am on "/login"
    When I log in as "admin" with password "admin"
    Then I should be logged in

  Scenario: 4. User login fails with invalid password
    Given I am on "/login"
    When I log in as "admin" with password "wrong_password_123"
    Then I should see an authentication error

  Scenario: 5. Authenticated user can create a new content node
    Given I am logged in as "admin"
    When I create a Quanta node titled "Behat Test Node" with body "Automated test content body"
    Then I should see "Behat Test Node"
    And I should see "Automated test content body"

  Scenario: 6. Authenticated user can edit an existing node
    Given I am logged in as "admin"
    And a Quanta node titled "Editable Node" exists
    When I edit the Quanta node "Editable Node" with new title "Updated Behat Node"
    Then I should see "Updated Behat Node"

  Scenario: 7. Authenticated user can delete a node
    Given I am logged in as "admin"
    And a Quanta node titled "Temporary Node" exists
    When I delete the Quanta node "Temporary Node"
    Then I should not see "Temporary Node"

  Scenario: 8. Emulated headless browser renders Quanta qtags and layout
    Given I am on "/"
    Then the page should contain valid HTML structure
