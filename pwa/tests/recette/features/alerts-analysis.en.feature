Feature: Alerts and analysis
  As a cyclist,
  I want to see relevant alerts on my stages,
  so that I can anticipate difficulties and adjust my planning.

  Background:
    Given I have created a full trip with 3 stages

  @desktop @critical
  Scenario: Excessive distance alert displayed
    When a stage exceeds the configured maximum distance
    Then I see an excessive distance alert on that stage

  @desktop @critical
  Scenario: High elevation gain alert displayed
    When a stage has more than 2000m elevation gain
    Then I see a high elevation alert on that stage

  @desktop @critical
  Scenario: Weather alert on a stage with adverse conditions
    When weather data indicates rain on stage 2
    Then I see a weather alert on stage card 2

  @desktop
  Scenario: Missing accommodation alert
    When no accommodation is found within 15 km for a stage
    Then I see an accommodation alert on that stage

  @desktop
  Scenario: Missing supply point alert
    When a long route section has no supply points
    Then I see a supply alert on the affected stage

  @desktop
  Scenario: Fatigue alert towards end of trip
    When the last stages accumulate too much elevation gain
    Then I see a progressive fatigue alert on the last stages

  @desktop
  Scenario: Cultural POI notification
    When a stage passes near a major tourist site
    Then I see a cultural POI notification on that stage

  @desktop
  Scenario: No alerts on a well-balanced trip
    Given all stages are within reasonable limits
    Then no critical alerts are displayed

  @desktop
  Scenario: Alerts sorted by priority
    When multiple alerts exist on the same stage
    Then they are displayed in descending order of severity

  @desktop
  Scenario Outline: Difficulty thresholds by alert type
    When the stage has a distance of <distance> km and elevation of <elevation> m
    Then the difficulty level is "<level>"

    Examples:
      | distance | elevation | level  |
      | 40       | 500       | Easy   |
      | 65       | 1000      | Medium |
      | 90       | 2000      | Hard   |
