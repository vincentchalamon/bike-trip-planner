Feature: Map visualization
  As a cyclist,
  I want to visualize my itinerary on an interactive map with elevation profile,
  so that I can visually understand the route and elevation changes.

  Background:
    Given I have created a trip with stages containing geometry data

  @desktop @critical
  Scenario: Map panel not visible before a trip is loaded
    Given I am on the home page
    Then the map panel is not visible

  @desktop @critical
  Scenario: Map panel appears after stages are computed
    Then the map panel is visible

  @desktop @critical
  Scenario: Map view rendered inside map panel
    Then the MapLibre view is visible in the map panel

  @desktop @critical
  Scenario: Elevation profile displayed with geometry data
    Then the elevation profile is visible

  @desktop
  Scenario: Elevation profile hidden without geometry data
    Given stages have no geometry data
    Then the elevation profile is not visible

  @desktop
  Scenario: Crosshair and tooltip on elevation profile hover
    When I hover over the elevation profile
    Then the vertical crosshair is visible
    And the elevation tooltip is displayed

  @desktop
  Scenario: Reset view button absent in global view
    Then the "Reset view" button is not visible

  @desktop
  Scenario: Reset view button appears when a stage is focused
    When I select stage 1 on the map
    Then the "Reset view" button is visible

  @desktop @fixme
  Scenario: Return to global view via reset button
    When I select stage 1 on the map
    And I click "Reset view"
    Then the view returns to the full route

  @desktop
  Scenario: Toggle between map-only and split view
    When I click the "map only" view mode button
    Then I only see the map panel
    When I click the "split view" button
    Then I see both panels side by side

  @desktop
  Scenario: Different color per stage on the map
    Then each stage is represented with a distinct color on the map

  @mobile @critical
  Scenario: Responsive map on mobile
    When I view the trip on a mobile screen
    Then the map adapts to the screen size
