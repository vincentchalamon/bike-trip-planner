Feature: Mobile and offline mode
  As a cyclist on the go,
  I want to use the app on mobile and without internet connection,
  so that I can view my itinerary even in areas without network coverage.

  Background:
    Given I am using the app on a mobile device

  @mobile @critical
  Scenario: Offline banner not visible when connected
    Then the offline banner is not visible

  @mobile @critical
  Scenario: Offline banner visible when connection is lost
    When the internet connection is lost
    Then the offline banner is visible
    And it contains the text "Hors ligne"

  @mobile @critical
  Scenario: ARIA attributes on offline banner
    When the internet connection is lost
    Then the offline banner has role="status" and aria-live="polite"

  @mobile @critical
  Scenario: Reconnection banner after coming back online
    When the internet connection is lost
    And the connection is restored
    Then the banner shows "Connexion rétablie"

  @mobile
  Scenario: Reconnection banner auto-dismisses after 3s
    When the internet connection is lost
    And the connection is restored
    And 3 seconds pass
    Then the offline banner is no longer visible

  @mobile @critical
  Scenario: Magic link input disabled when offline
    When the internet connection is lost
    Then the magic link input field is disabled

  @mobile @critical
  Scenario: GPX upload button disabled when offline
    When the internet connection is lost
    Then the GPX upload button is disabled

  @mobile @critical
  Scenario: Input re-enabled after coming back online
    When the internet connection is lost
    And the connection is restored
    Then the input field is enabled again

  @mobile @critical
  Scenario: Trip saved to IndexedDB after trip_complete
    When a full trip is created
    Then the trip is saved locally in IndexedDB

  @mobile @critical
  Scenario: Offline consultation of a previously saved trip
    Given a trip has been previously saved locally
    When the internet connection is lost
    And I open that trip
    Then I can view the trip stages

  @mobile
  Scenario: Responsive layout at 390px width
    When I resize the window to 390px width
    Then the interface adapts correctly without horizontal scrolling

  @mobile
  Scenario: Swipe gestures on the map
    When I drag the map with one finger
    Then the map moves following the gesture
