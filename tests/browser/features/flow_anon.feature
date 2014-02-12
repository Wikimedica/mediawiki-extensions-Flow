@test2.wikipedia.org @en.wikipedia.beta.wmflabs.org @ee-prototype.wmflabs.org

Feature: Create new topic anonymous

  @clean
  Scenario: Add new Flow topic
    Given I am on Flow page
    When I create a Title of Flow Topic in Flow new topic
      And I create a Body of Flow Topic into Flow body
      And I click New topic save
    Then the Flow page should contain Title of Flow Topic
      And the Flow page should contain Body of Flow Topic

  @clean
  Scenario: Anon does not see block or actions
    Given I am on Flow page
    When I see a flow creator element
    Then I do not see a block user link
