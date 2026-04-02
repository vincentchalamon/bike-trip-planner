Feature: Configuration and settings
  As a cyclist,
  I want to configure my trip settings,
  so that I can tailor the stage computation to my level and equipment.

  Background:
    Given I am on the trip page with computed stages

  @desktop @critical
  Scenario: Settings panel opens
    When I click the "Ouvrir les paramètres" button
    Then the settings panel is displayed

  @desktop @critical
  Scenario: Settings panel closed via ✕ button
    When I open the settings panel
    And I click the "Fermer les paramètres" button
    Then the settings panel is closed

  @desktop
  Scenario: Settings panel closed by clicking outside
    When I open the settings panel
    And I click outside the panel
    Then the settings panel is closed

  @desktop
  Scenario: Settings panel closed by pressing Escape
    When I open the settings panel
    And I press Escape
    Then the settings panel is closed

  @desktop @critical
  Scenario: Accommodation type filter switches visible
    When I open the settings panel
    Then I see switches for types "Hôtel", "Auberge", "Camping", "Gîte", "Chambre d'hôte", "Motel", "Refuge"

  @desktop @critical
  Scenario: Last enabled accommodation type cannot be disabled
    When I open the settings panel
    And I disable all accommodation types except the last
    Then the last switch is disabled and cannot be toggled

  @desktop
  Scenario: Average speed changed
    When I open the settings panel
    And I change the average speed to 20 km/h
    Then travel times are recalculated

  @desktop
  Scenario: Maximum daily distance changed
    When I open the settings panel
    And I change the maximum distance to 70 km
    Then stages are recalculated respecting that limit

  @desktop
  Scenario: E-bike mode enabled
    When I open the settings panel
    And I enable e-bike mode
    Then computations account for a higher speed

  @desktop
  Scenario: Departure time adjusted
    When I open the settings panel
    And I set the departure time to 9:00 AM
    Then the estimated arrival time is recalculated for each stage
