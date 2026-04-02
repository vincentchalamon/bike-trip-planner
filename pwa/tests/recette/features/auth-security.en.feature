Feature: Authentication and security
  As a user,
  I want to authenticate securely,
  so that I can access my personal trips.

  @desktop @critical
  Scenario: Login page shows email form
    Given I am not logged in
    When I navigate to the login page
    Then I see an email field
    And I see the "Recevoir un lien de connexion" button

  @desktop @critical
  Scenario: Confirmation shown after form submission
    Given I am not logged in
    When I navigate to the login page
    And I enter "test@example.com" in the email field
    And I click "Recevoir un lien de connexion"
    Then I see the email confirmation message

  @desktop @critical
  Scenario: Unauthenticated user redirected to login
    Given I am not logged in
    When I navigate to the home page
    Then I am redirected to /login

  @desktop @critical
  Scenario: Redirect to home after token verification
    Given I am not logged in
    When I navigate to /auth/verify/valid-token
    Then I am redirected to the home page

  @desktop
  Scenario: Logout
    Given I am logged in
    When I click the logout button
    Then I am redirected to the login page

  @desktop
  Scenario: Expired JWT token — redirected to login
    Given my session has expired
    When I try to access my trips
    Then I am redirected to /login

  @desktop
  Scenario: No stack traces visible on error
    When a server error occurs
    Then no PHP stack trace is shown to the user

  @desktop
  Scenario: Security headers present on responses
    When I load the home page
    Then the CSP, HSTS and X-Frame-Options headers are present

  @desktop
  Scenario: HTTPS only
    Then all loaded resources use HTTPS

  @desktop @authenticated
  Scenario: Trip isolation between users
    Given I am logged in as user A
    When I try to access user B's trip
    Then I get a 403 error or a not found page
