Feature: Cross-cutting UX
  As a user,
  I want a consistent and accessible user experience,
  so that I can navigate the application efficiently and comfortably.

  @desktop @critical
  Scenario: Ctrl+Z keyboard shortcut to undo
    Given I have made a stage modification
    When I press Ctrl+Z
    Then the modification is undone

  @desktop @critical
  Scenario: Ctrl+Y keyboard shortcut to redo
    Given I have undone a modification
    When I press Ctrl+Y
    Then the modification is redone

  @desktop
  Scenario: Locale switch from FR to EN
    When I change the language to "English"
    Then the interface is displayed in English

  @desktop
  Scenario: Locale switch from EN to FR
    Given the interface is in English
    When I change the language to "Français"
    Then the interface is displayed in French

  @desktop @dark
  Scenario: Switch to dark mode
    When I toggle to dark theme
    Then the interface is displayed with a dark background

  @desktop @dark
  Scenario: Switch to light mode
    Given dark theme is enabled
    When I toggle to light theme
    Then the interface is displayed with a light background

  @desktop @critical
  Scenario: Onboarding guide visible on first launch
    Given I am a new user
    When I navigate to the home page
    Then I see the getting started guide

  @desktop
  Scenario: Closing the onboarding guide
    Given the getting started guide is visible
    When I close it
    Then it is no longer visible

  @desktop
  Scenario: Keyboard navigation in forms
    When I navigate with Tab key in the form
    Then focus moves correctly between fields

  @desktop
  Scenario: Confirmation toast after a successful action
    When I perform an action that generates a notification
    Then a confirmation toast briefly appears

  @desktop @critical
  Scenario: Network error handled with user message
    When the backend API is unavailable
    Then a comprehensible error message is displayed to the user

  @desktop
  Scenario: Scroll-to-top button on long lists
    Given the stage list exceeds the screen height
    When I scroll down
    Then a scroll-to-top button appears

  @desktop @onboarding
  Scenario: Onboarding tour shows on first launch
    Given the onboarding tour is active on first launch
    Then the onboarding tour popover is visible

  @desktop @onboarding
  Scenario: First step targets the magic link field
    Given the onboarding tour is active on first launch
    Then the onboarding tour popover is visible
    And the tour step targets the magic link card

  @desktop @onboarding
  Scenario: Second step targets the GPX upload button
    Given the onboarding tour is active on first launch
    When I advance to the next tour step
    Then the tour step targets the GPX upload card

  @desktop @onboarding
  Scenario: Closing the onboarding tour with Escape
    Given the onboarding tour is active on first launch
    When I press Escape
    Then the onboarding tour popover is no longer visible

  @desktop @onboarding
  Scenario: Closing the onboarding tour by clicking the overlay
    Given the onboarding tour is active on first launch
    When I click the onboarding tour overlay
    Then the onboarding tour popover is no longer visible

  @desktop @onboarding
  Scenario: The tour no longer reappears after being dismissed
    Given the onboarding tour has already been seen
    When I reload the page
    Then the onboarding tour popover is no longer visible

  @desktop @dark
  Scenario: Theme button flips between light and dark
    When I toggle to light theme
    And I click the theme button
    Then the selected theme is "dark"
    When I click the theme button
    Then the selected theme is "light"

  @desktop @dark
  Scenario: By default the theme follows the operating system preference
    Given the operating system prefers dark mode
    When I reload the page
    Then the interface is displayed with a dark background

  @desktop @dark
  Scenario: Theme choice is persisted after reload
    When I toggle to dark theme
    And I reload the page
    Then the interface is displayed with a dark background

  @mobile
  Scenario: Compact language labels on a narrow screen
    Given I am displaying the app on a narrow screen
    Then the compact language label "FR" is visible

  @mobile
  Scenario: Language swap on mobile
    Given I am using the app on a mobile device
    When I change the language to "English"
    Then the interface is displayed in English

  @desktop
  Scenario: Language choice is persisted after reload
    When I change the language to "English"
    And I reload the page
    Then the interface is displayed in English
