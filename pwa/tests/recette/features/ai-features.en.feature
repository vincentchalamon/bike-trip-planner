Feature: AI features
  As a cyclist,
  I want AI assistance on my trip and stages,
  so that I can understand my route and refine my plan in natural language.

  @desktop @critical
  Scenario: Trip AI overview card displayed
    Given I have created a trip with an AI overview
    Then the trip AI overview card is visible
    And the trip AI narrative summary is visible

  @desktop
  Scenario: Global patterns visible in the AI overview
    Given I have created a trip with an AI overview
    Then the AI global patterns are visible

  @desktop
  Scenario: Cross-stage recommendations visible in the AI overview
    Given I have created a trip with an AI overview
    Then the AI cross-stage recommendations are visible
    And the AI cross-stage alerts are visible

  @mobile
  Scenario: AI overview details collapsed behind a toggle on mobile
    Given I have created a trip with an AI overview on mobile
    Then the AI overview details are collapsed
    When I expand the AI overview details
    Then the AI overview details are visible

  @desktop @critical
  Scenario: Trip AI overview card hidden when the LLM is absent
    Given I have created a trip without an AI overview
    Then the trip AI overview card is not visible

  @desktop @critical
  Scenario: "AI analysis" card present on a stage
    Given I have created a trip with per-stage AI analysis
    Then the AI analysis card for stage 1 is visible
    And the AI description of stage 1 is displayed

  @desktop
  Scenario: AI insights and suggestions displayed on the stage
    Given I have created a trip with per-stage AI analysis
    Then the AI insights of stage 1 are displayed
    And the AI suggestions of stage 1 are displayed

  @desktop
  Scenario: Full alert analysis expanded on click
    Given I have created a trip with per-stage AI analysis
    When I expand the full alerts of the AI analysis of stage 1
    Then the full AI alert list of stage 1 is visible

  @desktop
  Scenario: Applying AI suggestions enqueues a modification
    Given I have created a trip with per-stage AI analysis
    When I apply the AI suggestions of stage 1
    Then the modification queue is visible

  @desktop @critical
  Scenario: AI chat panel accessible after computation
    Given I have created a full trip with 3 stages
    When I open the AI assistant bubble
    Then the AI chat panel is visible

  @desktop @critical
  Scenario: Message sent to the backend and reply shown in history
    Given I have created a full trip with 3 stages
    And the AI assistant replies "Here is my analysis of your stage."
    When I open the AI assistant bubble
    And I send the message "What do you think of this stage?" in the AI chat
    Then a POST request to /trips/*/chat is sent
    And the reply "Here is my analysis of your stage." appears in the chat history

  @desktop
  Scenario: Loading indicator while the assistant replies
    Given I have created a full trip with 3 stages
    And the AI assistant replies with a delay
    When I open the AI assistant bubble
    And I send the message "Any suggestion?" in the AI chat
    Then the assistant typing indicator is visible

  @desktop
  Scenario: "In-ride" mode with geolocation suggests nearby POIs
    Given I have created a full trip with 3 stages
    And my position is shared at 48.8566, 2.3522
    And the AI assistant replies with nearby POIs
    When I open the AI assistant bubble
    And I enable geolocation in the AI chat
    And I send the message "A bakery not too far?" in the AI chat
    Then a POI card is displayed in the AI chat

  @desktop
  Scenario: Safety disclaimer shown below in-ride POIs
    Given I have created a full trip with 3 stages
    And my position is shared at 48.8566, 2.3522
    And the AI assistant replies with nearby POIs
    When I open the AI assistant bubble
    And I enable geolocation in the AI chat
    And I send the message "A water point?" in the AI chat
    Then the in-ride safety disclaimer is shown

  @desktop @critical
  Scenario: AI refinement card visible on the preview step
    Given I am on the trip preview with the AI refinement card
    Then the AI refinement card is visible
    And the AI refinement character counter is displayed

  @desktop
  Scenario: "Apply" button disabled when the suggestion is empty
    Given I am on the trip preview with the AI refinement card
    Then the AI refinement "Apply" button is disabled

  @desktop
  Scenario: "Apply" button enabled when a suggestion is entered
    Given I am on the trip preview with the AI refinement card
    When I type "Add a stage in Aubenas" in the AI refinement
    Then the AI refinement "Apply" button is enabled

  @desktop
  Scenario: 500-character limit enforced in the AI refinement
    Given I am on the trip preview with the AI refinement card
    When I type 600 characters in the AI refinement
    Then the AI refinement suggestion is limited to 500 characters

  @desktop
  Scenario: Character counter decrements as I type
    Given I am on the trip preview with the AI refinement card
    When I type "Go along the coast" in the AI refinement
    Then the AI refinement counter shows the number of remaining characters

  @desktop
  Scenario: Clear empties the AI refinement suggestion
    Given I am on the trip preview with the AI refinement card
    When I type "A suggestion to clear" in the AI refinement
    And I click the AI refinement "Clear" button
    Then the AI refinement textarea is empty

  @desktop
  Scenario: AI refinement card disabled when the LLM is unreachable
    Given I am on the trip preview with the AI refinement unavailable
    Then the AI refinement textarea is disabled
    And an AI unavailable notice is displayed

  @desktop @critical
  Scenario: Changed distance highlighted after recomputation
    Given I have created a full trip with 3 stages
    When stage 1 is recomputed with a changed distance
    Then the distance diff highlight of stage 1 is visible

  @desktop
  Scenario: Distance diff highlight disappears after about 3 seconds
    Given I have created a full trip with 3 stages
    When stage 1 is recomputed with a changed distance
    Then the distance diff highlight of stage 1 is visible
    And the distance diff highlight of stage 1 disappears after 3 seconds

  @desktop
  Scenario: Added alert highlighted after recomputation
    Given I have created a full trip with 3 stages
    When stage 1 is recomputed with a new alert
    Then the alerts diff highlight of stage 1 is visible
