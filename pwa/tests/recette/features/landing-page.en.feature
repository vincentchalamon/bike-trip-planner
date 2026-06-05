Feature: Landing page
  As an unauthenticated visitor,
  I want to discover the product on the public home page,
  so that I understand the value proposition and can request access.

  Background:
    Given I am viewing the public landing page

  @desktop @critical
  Scenario: Hero section with title and calls to action
    Then the hero section is visible
    And the "Créer un itinéraire" call to action is visible
    And the demo button is visible

  @desktop
  Scenario: "How it works" section
    Then the "how it works" section is visible

  @desktop
  Scenario: Benefits bento
    Then the features section is visible
    And the bento grid is visible

  @desktop @critical
  Scenario: Supported sources displayed
    Then the sources section is visible
    And the "komoot" source is displayed
    And the "strava" source is displayed
    And the "ridewithgps" source is displayed
    And the "gpx" source is displayed

  @desktop
  Scenario: Platforms section
    Then the platforms section is visible

  @desktop
  Scenario: Testimonials and use cases
    Then the testimonials section is visible

  @desktop @critical
  Scenario: Footer with legal links
    Then the footer is visible
    And the footer GitHub link is visible
    And the footer legal link is visible
    And the footer privacy link is visible

  @desktop
  Scenario: Call to action redirects to login when unauthenticated
    Then the "Créer un itinéraire" button points to "/login"

  @mobile @critical
  Scenario: Landing page responsive on mobile
    Given I am viewing the public landing page on mobile
    Then the landing page is visible
    And the hero section is visible
    And the "Créer un itinéraire" call to action is visible
