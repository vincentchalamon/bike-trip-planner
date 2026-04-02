Feature: Trip sharing
  As a cyclist,
  I want to share my trip with other people,
  so that they can view my itinerary without logging in.

  Background:
    Given I have created a full trip with an active share link

  @desktop @critical
  Scenario: Share button opens modal with link
    When I click the share button
    Then the share modal is displayed
    And I see the short share link

  @desktop @critical
  Scenario: Share link copied to clipboard
    When I open the share modal
    And I click "Copy link"
    Then the short link is copied to the clipboard

  @desktop @critical
  Scenario: Revoking link hides it and shows create button
    When I open the share modal
    And I click "Revoke link"
    Then the share link is no longer visible
    And the "Create share link" button is displayed

  @desktop @critical
  Scenario: Re-creating a link after revocation
    When I open the share modal
    And I revoke the link
    And I click "Create share link"
    Then a new share link is generated

  @desktop @critical
  Scenario: "Create link" button shown when no active link exists
    Given no share link is active
    When I open the share modal
    Then I see the "Create share link" button
    And the link is not yet visible

  @desktop
  Scenario: Downloading the PNG infographic
    When I open the share modal
    And I click "Download infographic"
    Then a PNG file is downloaded

  @desktop
  Scenario: Copying the trip text summary
    When I open the share modal
    And I click "Copy text"
    Then the summary text containing the trip title is copied

  @desktop
  Scenario: Public share page accessible without login
    Given I am not logged in
    When I navigate to /s/<short_code>
    Then I see the shared trip summary
