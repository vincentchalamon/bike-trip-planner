Feature: Edge cases and robustness
  As a developer and tester,
  I want to verify the application handles edge cases correctly,
  so that it is maximally robust in production.

  @desktop @critical
  Scenario: API 500 error handled
    When the API returns a 500 error during trip creation
    Then a comprehensible error message is displayed
    And the application remains usable

  @desktop @critical
  Scenario: Network error during trip creation
    When the network connection is cut during link submission
    Then an error message is displayed
    And the application remains usable

  @desktop @critical
  Scenario: Unsupported source URL
    When I submit "https://www.example.com/route/12345"
    Then I see a message indicating the source is not supported

  @desktop
  Scenario: Empty or corrupted GPX file
    When I import an empty GPX file
    Then an appropriate error message is displayed

  @desktop
  Scenario: GPX file with a single track point
    When I import a GPX file with a single waypoint
    Then a message explaining the file is insufficient is displayed

  @desktop
  Scenario: Trip with a single stage
    Given a trip has only one stage
    When I view that trip
    Then stage card 1 is displayed correctly
    And stage merge buttons are disabled

  @desktop
  Scenario: Very long trip title correctly truncated
    When I enter a trip title of 200 characters
    Then the title is correctly truncated in the interface

  @desktop
  Scenario: Page reload during active computation
    When I reload the page while stage computation is in progress
    Then the computation state is correctly recovered

  @desktop
  Scenario: Navigation to a non-existent trip
    Given the trip detail endpoint returns 404
    When I navigate to "/trips/non-existent-trip"
    Then I see a 404 page or error message

  @desktop
  Scenario: Multiple tabs open on the same trip
    Given I have the trip open in two tabs
    When I modify the trip in tab 1
    Then tab 2 reflects the change or shows a warning

  @desktop @performance
  Scenario: Large GPX file import (15MB)
    When I import a 15MB GPX file
    Then the import is processed in under 30 seconds
    And no memory error occurs

  @desktop
  Scenario: Stable behaviour with missing weather data
    When weather data is not available for a stage
    Then stage cards are displayed correctly without weather data
