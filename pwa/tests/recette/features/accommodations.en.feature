Feature: Accommodations
  As a cyclist,
  I want to see and manage available accommodations at each stage,
  so that I can plan my nights without having to search manually.

  Background:
    Given I have created a full trip with 3 stages
    And accommodations have been found for stages 1 and 2

  @desktop @critical
  Scenario: Suggested accommodations displayed on a stage card
    Then stage card 1 shows "Camping Les Oliviers"
    And stage card 1 shows "Hotel du Pont"

  @desktop @critical
  Scenario: Accommodation type label displayed
    Then stage card 1 shows the label "Camping"
    And stage card 1 shows the label "Hôtel"

  @desktop @critical
  Scenario: Distance to accommodation displayed
    Then stage card 1 shows the distance "1.2 km"
    And stage card 1 shows the distance "0.5 km"

  @desktop @critical
  Scenario: Manual accommodation added
    When I click "Ajouter un hébergement" on stage card 1
    Then the add accommodation form appears

  @desktop @critical
  Scenario: Accommodation removed
    When I remove "Hotel du Pont" from stage card 1
    Then "Hotel du Pont" no longer appears on stage card 1

  @desktop
  Scenario: No accommodation panel on last stage
    Then the last stage card does not show the "Ajouter un hébergement" button

  @desktop
  Scenario: "No accommodation found" message with search radius
    When no accommodation is found within 5 km for stage 1
    Then I see a message indicating a 5 km radius
    And I see a button to expand to 7 km

  @desktop
  Scenario: Expand radius button visible when accommodations found
    When accommodations are found within a 5 km radius
    Then I see a button to expand to 7 km

  @desktop
  Scenario: Expand button hidden at maximum radius
    When accommodations are found within a 15 km radius
    Then the button to expand to 17 km is not visible

  @desktop
  Scenario: Expanding radius triggers a new scan
    When no accommodation is found within 5 km
    And I click "Expand to 7 km"
    Then a scan request with radiusKm=7 is sent

  @desktop
  Scenario: Distance badge hidden for on-site accommodations
    When an accommodation is exactly at the endpoint
    Then no distance badge is displayed for that accommodation
