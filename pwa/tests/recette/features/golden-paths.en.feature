Feature: End-to-end golden paths
  As a cyclist,
  I want to run a full planning journey from import to sharing,
  so that I can confirm every building block works together for Komoot, Strava and GPX sources.

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — Komoot import and stage computation
    Given I am on the home page
    When I submit a valid Komoot link
    And the route_parsed event is received
    Then I am redirected to the trip page
    And the total distance shows "187km"
    And the total elevation shows "2850m"
    When the route_parsed and stages_computed events are received
    Then I see stage card 1
    And I see stage card 2
    And I see stage card 3

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — map and elevation profile for the Komoot trip
    Given I have created a trip with stages containing geometry data
    Then the map panel is visible
    And the elevation profile is visible

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — dates, touring profile and e-bike mode
    Given I have created a full trip with 3 stages
    When I open the date picker
    And I select June 19, 2026 as the departure date
    And I open the settings panel
    And I change the maximum distance to 70 km
    Then stages are recalculated respecting that limit
    When I enable e-bike mode
    Then computations account for a higher speed

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — rest day shifting the dates
    Given I have created a full trip with 3 stages
    When I set June 15, 2026 as the departure date
    And a rest day is added after stage 1
    Then I see a rest day indicator between stage 1 and stage 2

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — selecting an accommodation recalculates the endpoint
    Given I have created a full trip with 3 stages
    And accommodations have been found for stages 1 and 2
    When I select accommodation "Hotel du Pont" for stage 1
    Then accommodation "Hotel du Pont" is marked as selected for stage 1

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — text export and global GPX
    Given I have created a full trip with an active share link
    When I open the share modal
    And I click "Copy text"
    Then the summary text containing the trip title is copied
    When I click "Télécharger le GPX complet"
    Then a GET request to /trips/*.gpx is sent

  @desktop @critical @golden-path-a
  Scenario: Golden Path A — public sharing then link revocation
    Given I have created a full trip with an active share link
    When I click the share button
    Then the share modal is displayed
    And I see the short share link
    When I revoke the link
    Then the share link is no longer visible
    And the "Create share link" button is displayed

  @desktop @critical @connected @golden-path-a
  Scenario: Golden Path A — duplication then re-consultation of the trip
    Given I am logged in and have a saved trip
    When I duplicate that trip
    Then a new identical trip appears in my list

  @desktop @critical @golden-path-b
  Scenario: Golden Path B — Strava import and stage computation
    Given I am on the home page
    When I submit the link "https://www.strava.com/routes/12345"
    And the route_parsed event is received
    Then I am redirected to the trip page
    And the total distance shows "187km"
    And the total elevation shows "2850m"
    When the route_parsed and stages_computed events are received
    Then I see stage card 1
    And I see stage card 2
    And I see stage card 3

  @desktop @critical @golden-path-b
  Scenario: Golden Path B — configuration and elevation profile for the Strava trip
    Given I create a full trip from "https://www.strava.com/routes/12345"
    Then the map panel is visible
    And the elevation profile is visible
    When I open the settings panel
    And I change the average speed to 20 km/h
    Then travel times are recalculated

  @desktop @critical @golden-path-b
  Scenario: Golden Path B — global GPX export for the Strava trip
    Given I create a full trip from "https://www.strava.com/routes/12345"
    Then the "Télécharger le GPX complet" button is visible and enabled
    When I click "Télécharger le GPX complet"
    Then a GET request to /trips/*.gpx is sent

  @desktop @critical @golden-path-c
  Scenario: Golden Path C — GPX file import and stage computation
    Given I create a trip by importing a GPX file
    And the route_parsed and stages_computed events are received
    Then I see stage card 1
    And I see stage card 2
    And I see stage card 3

  @desktop @critical @golden-path-c
  Scenario: Golden Path C — map, profile and export for the GPX trip
    Given I create a trip by importing a GPX file
    And the route_parsed and stages_computed events are received
    Then the map panel is visible
    And the elevation profile is visible
    And the "Download GPX" button for stage 1 is enabled
