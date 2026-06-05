Feature: Stage management
  As a cyclist,
  I want to manage my trip stages,
  so that I can customize my day-by-day planning.

  Background:
    Given I have created a full trip with 3 stages

  @desktop @critical
  Scenario: Difficulty badge displayed on each stage
    Then stage card 1 shows a difficulty badge
    And stage card 2 shows a difficulty badge
    And stage card 3 shows a difficulty badge

  @desktop @critical
  Scenario: Trip title editing
    When I click on the trip title
    And I type "My Ardèche trip"
    And I press Enter
    Then the displayed title is "My Ardèche trip"

  @desktop
  Scenario: Title editing cancelled by Escape
    When I click on the trip title
    And I type "Temporary title"
    And I press Escape
    Then the title has not changed

  @desktop
  Scenario: Merging two stages (drag and drop)
    When I merge stage 1 with stage 2
    Then I only see 2 stage cards

  @desktop
  Scenario: Splitting a stage
    When I split stage 1 at mid-route
    Then I see 4 stage cards

  @desktop
  Scenario: Moving a stage endpoint on the map
    When I drag the end point of stage 1 on the map
    Then the distance of stage 1 is recalculated

  @desktop
  Scenario: Adding a rest day between two stages
    When I add a rest day after stage 1
    Then I see a rest day indicator between stage 1 and stage 2

  @desktop
  Scenario: Removing a rest day
    Given a rest day exists after stage 1
    When I remove the rest day
    Then there is no longer a rest day indicator between stage 1 and stage 2

  @desktop
  Scenario: Estimated trip duration displayed
    Then I see the total trip duration in days

  @desktop
  Scenario: Correct difficulty badge based on distance and elevation
    Then all stage difficulty badges are consistent with their values

  @desktop
  Scenario: Undo/Redo a stage modification
    When I modify a stage
    And I undo with Ctrl+Z
    Then the stage has reverted to its previous state
    When I redo with Ctrl+Y
    Then the stage is modified again

  @desktop
  Scenario: Progress bar during computation
    Given I am on the home page
    When I submit a valid Komoot link
    Then I see a progress bar while stages are being computed

  @desktop @supply
  Scenario: Supply timeline displayed on a stage
    Given supply data is available for stage 1
    Then the supply timeline of stage 1 is visible

  @desktop @supply
  Scenario: Supply markers with water, food and mixed icons
    Given supply data is available for stage 1
    Then the supply marker at 15 km shows the water icon
    And the supply marker at 42 km shows the food icon
    And the supply marker at 59 km shows the mixed icon

  @desktop @supply
  Scenario: Hovering a marker shows the name and distance
    Given supply data is available for stage 1
    When I open the supply marker at 15 km
    Then the supply tooltip shows "Cimetière de Vals"
    And the supply tooltip shows "15 km"

  @mobile @supply
  Scenario: Horizontal scroll of the supply timeline on mobile
    Given supply data is available for stage 1 on mobile
    Then the supply timeline of stage 1 is visible

  @desktop @timeline
  Scenario: Split map + timeline view by default on desktop
    Then I see both panels side by side
    And the "split view" view mode button is active

  @desktop @timeline
  Scenario: Switch to timeline-only view
    When I click the "timeline" view mode button
    Then the timeline is visible
    And the map panel is not visible

  @desktop @timeline
  Scenario: Back to split view from timeline-only view
    When I click the "timeline" view mode button
    And I click the "split view" view mode button
    Then I see both panels side by side

  @mobile @timeline
  Scenario: Full-screen timeline on mobile with toggle to map
    Given I switch to a mobile screen
    When I click the "timeline" view mode button
    Then the timeline is visible
    And the map panel is not visible
    When I click the "map only" view mode button
    Then the map panel is visible
