Feature: Cross-cutting quality — performance, accessibility, SEO
  As a user and webmaster,
  I want the application to be fast, accessible and indexable,
  so that it provides an optimal experience and good search ranking.

  @desktop @performance @critical
  Scenario: Lighthouse performance score above 80
    When I run a Lighthouse audit on the home page
    Then the performance score is greater than or equal to 80

  @desktop @performance @critical
  Scenario: LCP under 2.5 seconds
    When I run a Lighthouse audit on the home page
    Then the LCP (Largest Contentful Paint) is less than 2500ms

  @desktop @performance @critical
  Scenario: CLS under 0.1
    When I run a Lighthouse audit on the home page
    Then the CLS (Cumulative Layout Shift) is less than 0.1

  @desktop @a11y @critical
  Scenario: Lighthouse accessibility score above 90
    When I run a Lighthouse audit on the home page
    Then the accessibility score is greater than or equal to 90

  @desktop @a11y @critical
  Scenario: No critical axe-core violations on home page
    When I run an axe-core analysis on the home page
    Then no critical accessibility violations are detected

  @desktop @a11y @critical
  Scenario: No critical axe-core violations on trip page
    Given I have created a full trip
    When I run an axe-core analysis on the trip page
    Then no critical accessibility violations are detected

  @desktop @seo @critical
  Scenario: Lighthouse SEO score above 90
    When I run a Lighthouse audit on the home page
    Then the SEO score is greater than or equal to 90

  @desktop @seo
  Scenario: Meta tags present and valid
    When I load the home page
    Then the <title> tag is present and non-empty
    And the meta description tag is present

  @desktop @a11y
  Scenario: All interactive elements have an accessible label
    When I load the trip page
    Then all buttons have an aria-label or visible text

  @desktop @performance
  Scenario: Home page loads in under 3 seconds
    When I load the home page
    Then the page is interactive within 3000ms

  @desktop @performance
  Scenario: Trip creation completed in under 10 seconds
    When I submit a link and computation is simulated
    Then all 3 stages are displayed within 10 seconds
