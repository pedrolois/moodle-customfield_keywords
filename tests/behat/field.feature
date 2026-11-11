@customfield @customfield_keywords @javascript
Feature: Keywords course custom field
  In order to classify courses with free keywords
  As a manager and a teacher
  I need to create a Keywords field and fill it in on courses

  Background:
    Given the following "custom field categories" exist:
      | name              | component   | area   | itemid |
      | Category for test | core_course | course | 0      |
    And the following "users" exist:
      | username | firstname | lastname  | email                |
      | teacher1 | Teacher   | Example 1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "custom fields" exist:
      | name   | category          | type     | shortname | configdata                             |
      | Topics | Category for test | keywords | topics    | {"visibility":"2","maxkeywords":"2"} |

  Scenario: Keywords are saved and shown as links to the courses with the same keyword
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Settings" in current page administration
    And I set the field "Topics" to "Leadership, Finance"
    And I press "Save and display"
    When I am on site homepage
    Then I should see "Topics" in the ".customfields-container .customfieldname" "css_element"
    And "Leadership" "link" should exist in the ".customfields-container .customfieldvalue" "css_element"
    And "Finance" "link" should exist in the ".customfields-container .customfieldvalue" "css_element"
    And I click on "Leadership" "link" in the ".customfields-container .customfieldvalue" "css_element"
    And I should see "Course 1"

  Scenario: More keywords than the field allows are refused
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Settings" in current page administration
    When I set the field "Topics" to "Leadership, Finance, Ethics"
    And I press "Save and display"
    Then I should see "Enter at most 2 keywords."
