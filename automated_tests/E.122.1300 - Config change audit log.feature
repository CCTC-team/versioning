Feature: E.122.1300 - The system shall record configuration changes for the Versioning external module (who, when, old->new) to the module's View Logs page.

  As a REDCap administrator
  I want every configuration change to be written to the module's External Module Logs
  So that there is an audit trail of who changed which setting, when, and from what value to what.

  Scenario: Enable external module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    And I should NOT see "Versioning - v1.1.0"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    Then I should see "Available Modules"
    And I click on the button labeled "Enable" in the row labeled "Versioning"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Versioning - v1.1.0"

  Scenario: First configuration save logs the initial values
    Given I create a new project named "E.122.1300" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/Project_redcap_val_nodata.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Project Module Manager"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Versioning - v1.1.0"
    Then I should see "Versioning - v1.1.0"

    # First save has no prior snapshot, so each setting the admin sets is logged as
    # (empty) -> value. versioning-field-suffix and current-project-version are
    # REQUIRED (Save is blocked until both are filled); the readonly checkbox is
    # optional and gives a deterministic (empty) -> 1 entry.
    Given I click on the button labeled "Configure"
    Then I should see "Configure Module"
    When I enter "crfver" into the input field labeled "Provide the suffix"
    And I enter "1" into the input field labeled "The current crf version"
    And I check the checkbox labeled "If checked, any fields identified as versioning fields will be automatically set as readonly"
    Then I click on the button labeled "Save"
    And I should see "Versioning - v1.1.0"

    #VERIFY - the audit trail on the module's own View Logs page
    When I click on the link labeled "View Logs"
    Then I should see "External Module Logs"
    And I should see a table header and row containing the following values in a table:
      | Module     | Message                         | UserName   |
      | versioning | Configuration changed (project) | Test_Admin |

    # The hook logs one entry per changed key in config.json order, so newest-first
    # the FIRST button is version-field-auto-set-as-readonly (logged last) and the
    # SECOND button is current-project-version. old->new live in params
    # 'old_value'/'new_value'; 'setting' names the changed key. The acting user is the
    # UserName column, not a param.
    When I click on the first button labeled "Show Parameters"
    Then I should see "Log Entry Parameters"
    And I should see a table header and row containing the following values in a table:
      | Name      | Value                              |
      | setting   | version-field-auto-set-as-readonly |
      | old_value | (empty)                            |
      | new_value | 1                                  |
    And I click on the button labeled "Close"
    Then I should see "External Module Logs"

    When I click on the second button labeled "Show Parameters"
    Then I should see "Log Entry Parameters"
    And I should see a table header and row containing the following values in a table:
      | Name      | Value                   |
      | setting   | current-project-version |
      | old_value | (empty)                 |
      | new_value | 1                       |

  Scenario: Changing a setting logs an old->new audit entry
    # rctf starts each scenario from a clean browser page, so re-navigate to the
    # project fresh (same pattern as the other continuation scenarios).
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.122.1300"
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Project Module Manager"
    And I should see "Versioning - v1.1.0"

    # Change the field suffix from "crfver" to "crfversion". The other required
    # fields keep their values, so only this key changes: a genuine value -> value
    # transition proving the snapshot/diff works across saves (not just first save).
    When I click on the button labeled "Configure"
    Then I should see "Configure Module"
    And I clear field and enter "crfversion" into the input field labeled "Provide the suffix"
    Then I click on the button labeled "Save"
    And I should see "Versioning - v1.1.0"

    #VERIFY - the audit trail on the module's own View Logs page
    When I click on the link labeled "View Logs"
    Then I should see "External Module Logs"
    And I should see a table header and row containing the following values in a table:
      | Module     | Message                         | UserName   |
      | versioning | Configuration changed (project) | Test_Admin |

    # old->new values live in admin-gated parameters. The most recent entry is the
    # versioning-field-suffix crfver -> crfversion change.
    When I click on the first button labeled "Show Parameters"
    Then I should see "Log Entry Parameters"
    And I should see a table header and row containing the following values in a table:
      | Name      | Value                   |
      | setting   | versioning-field-suffix |
      | old_value | crfver                  |
      | new_value | crfversion              |
    And I click on the button labeled "Close"
    Then I should see "External Module Logs"

    # Disable the external module from the Control Center
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Versioning - v1.1.0"

    # Verify no exceptions are thrown in the system
    Given I open Email
    Then I should NOT see an email with subject "REDCap External Module Hook Exception - versioning"
