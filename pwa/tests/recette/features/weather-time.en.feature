Feature: Weather and travel time
  As a cyclist,
  I want to see forecast weather and travel times per stage,
  so that I can realistically plan my cycling days.

  Background:
    Given I have created a full trip with 3 stages

  @desktop @critical
  Scenario: Weather displayed on stage cards
    Then stage card 1 shows weather conditions
    And stage card 2 shows weather conditions

  @desktop @critical
  Scenario: Min-max temperature range displayed
    Then I see the temperature range "14-26°C" on stage 1

  @desktop @critical
  Scenario: Estimated travel time displayed
    Then each stage card shows an estimated travel time

  @desktop
  Scenario: Estimated arrival time displayed
    When the departure time is set to 8:00 AM
    Then I see the estimated arrival time on each stage

  @desktop
  Scenario: Cold weather alert when temperature below 5°C
    When stage 1 weather forecasts temperatures below 5°C
    Then I see a cold weather alert on stage 1

  @desktop
  Scenario: Rain alert when heavy precipitation forecast
    When stage 2 weather forecasts more than 10mm of rain
    Then I see a rain alert on stage 2

  @desktop
  Scenario: Correct weather icon displayed
    Then each stage shows a weather icon matching its conditions

  @desktop
  Scenario: Travel time recalculated when speed changes
    When I change the average speed to 20 km/h in settings
    Then the travel times of all stages are updated

  @desktop
  Scenario: Fatigue factor applied to pacing
    When the fatigue factor is enabled
    Then the target distance decreases progressively across stages

  @desktop
  Scenario: E-bike mode — higher speed
    When e-bike mode is enabled
    Then travel times are recalculated with a higher speed
