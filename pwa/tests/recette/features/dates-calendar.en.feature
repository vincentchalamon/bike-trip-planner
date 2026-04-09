Feature: Dates and calendar
  As a cyclist,
  I want to set and visualize my trip dates on a calendar,
  so that I can plan my departures and arrivals on specific dates.

  Background:
    Given I have created a full trip with 3 stages

  @desktop @critical
  Scenario: Departure date selection
    When I open the date picker
    And I select June 15, 2026 as the departure date
    Then the displayed departure date is "June 15, 2026"

  @desktop @critical
  Scenario: Stage dates automatically computed
    When I set June 15, 2026 as the departure date
    Then stage 1 is scheduled for June 15, 2026
    And stage 2 is scheduled for June 16, 2026
    And stage 3 is scheduled for June 17, 2026

  @desktop @critical
  Scenario: Arrival date shifted by rest day
    When I set June 15, 2026 as the departure date
    And a rest day is added after stage 1
    Then stage 2 is scheduled for June 17, 2026

  @desktop
  Scenario: Stage calendar displayed
    When I set a departure date
    Then the calendar shows all stages with their dates

  @desktop
  Scenario: Weather matched to trip dates
    When I set a departure date within the next 7 days
    Then weather forecasts are associated with stage dates

  @desktop
  Scenario: Date reset
    When I set a departure date
    And I remove the departure date
    Then stages no longer show dates

  @desktop
  Scenario: No dates shown on trip without dates
    Given the trip has no departure date
    Then stage cards do not show dates

  @desktop
  Scenario: Calendar navigation to next month
    When I open the calendar
    And I navigate to the next month
    Then the next month is displayed
