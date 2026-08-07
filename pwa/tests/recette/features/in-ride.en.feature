Feature: Guided in-ride search
  As a cyclist on the road,
  I want to tap a predefined question and read a few nearby results,
  so that I can find water, shelter or a bike shop without typing.

  Background:
    Given I have created a full trip with 3 stages

  @desktop @critical
  Scenario: Opening the in-ride panel shows the eight question chips
    When I open the in-ride panel
    Then the eight in-ride question chips are visible

  @desktop @critical
  Scenario: Finding water nearby
    Given I have shared my location
    When I open the in-ride panel
    And I tap the "water" in-ride question chip
    Then an in-ride recap is shown
    And nearby in-ride POI cards are shown

  @desktop
  Scenario: Widening the search
    Given I have shared my location
    When I open the in-ride panel
    And I tap the "water" in-ride question chip
    And I widen the in-ride search
    Then an in-ride recap is shown

  @desktop
  Scenario: The bubble is disabled when offline
    When the app goes offline
    Then the in-ride bubble is disabled
    And the in-ride offline badge is visible
