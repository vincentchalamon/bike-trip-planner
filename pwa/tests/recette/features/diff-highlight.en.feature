Feature: Diff highlight after recomputation
  As a cyclist,
  I want to see what changed on a stage after a recomputation,
  so that I can immediately spot the impact of my changes.

  @desktop @critical
  Scenario: Changed distance highlighted after recomputation
    Given I have created a full trip with 3 stages
    When stage 1 is recomputed with a changed distance
    Then the distance diff highlight of stage 1 is visible

  @desktop
  Scenario: Distance diff highlight disappears after about 3 seconds
    Given I have created a full trip with 3 stages
    When stage 1 is recomputed with a changed distance
    Then the distance diff highlight of stage 1 is visible
    And the distance diff highlight of stage 1 disappears after 3 seconds

  @desktop
  Scenario: Added alert highlighted after recomputation
    Given I have created a full trip with 3 stages
    When stage 1 is recomputed with a new alert
    Then the alerts diff highlight of stage 1 is visible
