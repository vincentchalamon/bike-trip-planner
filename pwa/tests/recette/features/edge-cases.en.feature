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
  Scenario: Large GPX file import (30MB)
    When I import a 30MB GPX file
    Then the import is processed in under 30 seconds
    And no memory error occurs

  @desktop
  Scenario: Stable behaviour with missing weather data
    When weather data is not available for a stage
    Then stage cards are displayed correctly without weather data

  @desktop @critical
  Scenario: Private Strava route URL handled gracefully
    Given I am on the home page
    When I submit a private Strava route URL
    Then I see an error message indicating the source is inaccessible
    And the application remains usable

  @desktop
  Scenario: Very distant departure date (~2 years) without crashing
    Given I have created a full trip with 3 stages
    When I open the date picker
    And I set a departure date about two years out
    Then I see stage card 1
    And the map panel is visible

  @desktop
  Scenario: Automatic Mercure SSE reconnection resumes updates
    Given I have created a full trip with 3 stages
    When the internet connection is lost
    And the connection is restored
    And a real-time update for stage 1 is received
    Then stage card 1 shows "55"

  @desktop
  Scenario: No accommodation found across the whole trip shows an informative message
    Given I have created a full trip with 3 stages
    When no accommodation is found for the entire trip
    Then stage card 1 shows "No accommodation"
    And stage card 2 shows "No accommodation"

  @desktop
  Scenario: Undo to the beginning disables the button without crashing
    Given I have created a full trip with 3 stages
    When I modify a stage
    And I press Ctrl+Z
    Then I see 3 stage cards
    And the undo button is disabled
