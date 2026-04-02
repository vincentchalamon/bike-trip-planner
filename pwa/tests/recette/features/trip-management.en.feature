Feature: Trip management
  As a cyclist,
  I want to manage my saved trips,
  so that I can retrieve, duplicate, or delete my plans.

  @desktop @authenticated @critical
  Scenario: Trip list displayed
    Given I am logged in and have 3 saved trips
    When I navigate to the home page
    Then I see my list of 3 trips

  @desktop @authenticated @critical
  Scenario: Accessing a trip from the list
    Given I am logged in and have a saved trip
    When I click on that trip in the list
    Then I am redirected to the trip detail page

  @desktop @authenticated @critical
  Scenario: Duplicating a trip
    Given I am logged in and have a saved trip
    When I duplicate that trip
    Then a new identical trip appears in my list

  @desktop @authenticated @critical
  Scenario: Deleting a trip
    Given I am logged in and have a saved trip
    When I delete that trip
    Then it no longer appears in my list

  @desktop @authenticated
  Scenario: Recent trip shown on home page
    Given I have recently viewed a trip
    When I navigate to the home page
    Then I see the recent trip in the "Recent" section

  @desktop @authenticated
  Scenario: Trip locked after modification by another user
    Given a trip has been locked by another user
    When I open that trip
    Then I see a lock indicator
    And edit buttons are disabled

  @desktop @authenticated
  Scenario: Empty state when no trips exist
    Given I am logged in with no trips
    When I navigate to the home page
    Then I see an empty state prompting me to create a trip

  @desktop @authenticated
  Scenario: Loading a trip without dates
    Given I have a trip with no start or end dates
    When I open that trip
    Then stages are displayed correctly without dates

  @desktop
  Scenario: Loading indicator while fetching trips
    When the trip list is loading
    Then I see a loading indicator
