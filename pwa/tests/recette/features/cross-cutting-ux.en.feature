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
