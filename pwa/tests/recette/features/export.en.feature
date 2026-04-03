Feature: GPX and FIT export
  As a cyclist,
  I want to export my stages in GPX and FIT format,
  so that I can load them on my GPS or navigation app.

  Background:
    Given I have created a full trip with 3 stages

  @desktop @critical
  Scenario: GPX button enabled after stages computed
    Then the "Download GPX" button for stage 1 is enabled

  @desktop @critical
  Scenario: Stage GPX download triggers API call
    When I click "Download GPX" for stage 1
    Then a GET request to /trips/*/stages/0.gpx is sent

  @desktop @critical
  Scenario: Global GPX download button visible after stages computed
    Then the "Télécharger le GPX complet" button is visible and enabled

  @desktop @critical
  Scenario: Global GPX download triggers trip API call
    When I click "Télécharger le GPX complet"
    Then a GET request to /trips/*.gpx is sent

  @desktop @critical @fixme
  Scenario: GPX file upload from computer
    When I click the "Import GPX" button
    And I select a valid GPX file
    Then the trip is created from the GPX file

  @desktop
  Scenario: Invalid GPX file rejected
    When I try to import a non-GPX file
    Then an error message is displayed

  @desktop @critical
  Scenario: Stage FIT download
    When I click "Download FIT" for stage 1
    Then a GET request to /trips/*/stages/0.fit is sent

  @desktop
  Scenario: FIT button disabled during computation
    When stage computation is in progress
    Then the FIT button for stage 1 is disabled

  @desktop
  Scenario Outline: Export by file format
    When I click the export button for format "<format>" on stage 1
    Then the downloaded file has extension ".<extension>"

    Examples:
      | format | extension |
      | GPX    | gpx       |
      | FIT    | fit       |
