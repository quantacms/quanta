Feature: Quanta core acceptance smoke tests
  A maintainer should catch regressions in installation, rendering, authentication and node storage.

  Scenario: Generic profile installs into a usable site
    Given the Quanta Behat site is installed
    Then the Doctor log contains "Installing profile: generic"
    And the site file "index.html" exists
    And the site file "pages/home/data_en.json" exists

  Scenario: Homepage renders without unresolved Qtags
    When I render "/home/"
    Then the command succeeds
    And the output contains "Your Homepage"
    And the output has no unresolved Qtags

  Scenario: Blog page renders without unresolved Qtags
    When I render "/blog/"
    Then the command succeeds
    And the output contains "Blog"
    And the output has no unresolved Qtags

  Scenario: Administrator can log in with the configured password
    When I log in as administrator with the test password
    Then the command succeeds
    And the login is accepted

  Scenario: Administrator login rejects a wrong password
    When I log in as administrator with password "definitely-wrong"
    Then the command succeeds
    And the login is rejected

  Scenario: A node can be created and loaded
    When I create node "behat-created-node" titled "Behat Created Node"
    Then the command succeeds
    And node "behat-created-node" has title "Behat Created Node"

  Scenario: A newly created node renders as a page
    When I create node "behat-render-node" titled "Behat Render Node"
    Then the command succeeds
    When I render "/behat-render-node/"
    Then the command succeeds
    And the output contains "Behat Render Node"
    And the output has no unresolved Qtags
