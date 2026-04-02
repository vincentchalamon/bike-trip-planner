Feature: Trip creation
  As a cyclist,
  I want to create a trip from a Komoot, Strava or RideWithGPS link,
  so that I get an automatically computed stage plan.

  Background:
    Given I am on the home page

  @desktop @critical
  Scenario: Magic link input field is displayed
    Then I see an input field with placeholder "Collez votre lien Komoot, Strava ou RideWithGPS ici..."

  @desktop @critical
  Scenario: Validation error for an invalid URL
    When I type "not-a-url" in the magic link field
    And I press Enter
    Then I see the error message "Veuillez entrer une URL valide."

  @desktop
  Scenario: Error clears when I start typing again
    When I type "not-a-url" in the magic link field
    And I press Enter
    And I type "https://" in the magic link field
    Then I no longer see the error message

  @desktop @critical
  Scenario: Trip created on a valid Komoot URL
    When I submit a valid Komoot link
    Then I am redirected to the trip page
    And I see the trip title or its loading skeleton

  @desktop @critical
  Scenario: Total distance displayed after route parsed
    When I submit a valid Komoot link
    And the route_parsed event is received
    Then the total distance shows "187km"

  @desktop @critical
  Scenario: Total elevation displayed after route parsed
    When I submit a valid Komoot link
    And the route_parsed event is received
    Then the total elevation shows "2850m"

  @desktop @critical
  Scenario: 3 stage cards shown after stages_computed
    When I submit a valid Komoot link
    And the route_parsed and stages_computed events are received
    Then I see stage card 1
    And I see stage card 2
    And I see stage card 3

  @desktop
  Scenario: Auto-submit when pasting a valid URL
    When I paste "https://www.komoot.com/fr-fr/tour/12345" into the magic link field
    Then I am redirected to the trip page

  @desktop
  Scenario: Auto-creation via ?link= query parameter
    When I navigate to "/?link=https%3A%2F%2Fwww.komoot.com%2Ffr-fr%2Ftour%2F2795080048"
    Then I am redirected to the trip page

  @desktop
  Scenario: Invalid ?link= parameter silently ignored
    When I navigate to "/?link=invalid-url"
    Then I stay on the home page
    And I see the magic link input field

  @desktop
  Scenario: Departure and arrival cities shown after geocoding
    When I submit a valid Komoot link
    And the route_parsed and stages_computed events are received
    Then stage 1 shows "Aubenas" as departure
    And stage 1 shows "Vals-les-Bains" as arrival

  @desktop
  Scenario Outline: Trip creation from different sources
    When I submit the link "<link>"
    Then I am redirected to the trip page

    Examples:
      | link                                         |
      | https://www.komoot.com/fr-fr/tour/2795080048 |
      | https://www.strava.com/routes/12345          |
      | https://ridewithgps.com/routes/12345         |
