# Versioning External Module - Technical Documentation

## Overview

The Versioning module is a REDCap External Module that enables project designers to track CRF (Case Report Form) versions across their instruments. This is particularly useful for managing protocol amendments where validation rules or data requirements may differ between versions.

## Module Information

| Property | Value |
|----------|-------|
| **Namespace** | `CCTC\VersioningModule` |
| **Framework Version** | 14 |
| **PHP Compatibility** | 8.0.27 - 8.2.29 |
| **REDCap Compatibility** | 13.8.1 - 15.9.1 |

## Authors

- **Richard Hardy** - University of Cambridge, Cambridge Cancer Trials Centre (rmh54@cam.ac.uk)
- **Mintoo Xavier** - Cambridge University Hospital, Cambridge Cancer Trials Centre (mintoo.xavier1@nhs.net)

## File Structure

```
versioning_v1.1.1/
├── VersioningModule.php    # Main module class
├── config.json             # Module configuration
├── index.php               # Project link page for viewing/updating version
├── README.md               # User documentation
├── DOCUMENTATION.md        # This file
└── automated_tests/        # Cypress test scenarios
    ├── E.122.100 - Versioning Configurations.feature
    ├── E.122.200 - Enable module on all projects.feature
    ├── E.122.300 - Make module discoverable.feature
    ├── E.122.400 - Allow non-admins to enable this module.feature
    ├── E.122.500 - Hide this module from non-admins.feature
    ├── E.122.700 - CRF Versioning.feature
    ├── E.122.1200 - Module configuration permissions in projects.feature
    ├── E.122.600 - REDUNDANT.feature   
    ├── E.122.800 - REDUNDANT.feature   
    ├── E.122.900 - REDUNDANT.feature   
    ├── E.122.1000 - REDUNDANT.feature  
    ├── E.122.1100 - REDUNDANT.feature  
    ├── fixtures/
    │   └── cdisc_files/                # CDISC XML test data
    ├── step_definitions/
    │   ├── external_module.js          # External module & versioning step definitions
    │   └── noncore.js                  # General custom Cypress step definitions
    └── urs/
        └── User Requirement Specification.spec
```

### Target Directory Structure (Planned)

The following directory structure is planned for future versions to better separate concerns:

```
versioning_v1.1.1/
├── classes/
│   ├── VersionManager.php       # Version CRUD operations
│   ├── FieldIdentifier.php      # Field suffix matching logic
│   └── AuditLogger.php          # Audit logging functionality
├── pages/
│   └── index.php                # UI moved here
├── js/
│   └── versioning.js            # Client-side logic
├── css/
│   └── versioning.css           # Styles
├── config.json
├── VersioningModule.php         # Main class (hooks only)
├── README.md
├── DOCUMENTATION.md
└── automated_tests/
```

## Core Functionality

### 1. The `@VERSION` Action Tag

The module declares the `@VERSION` action tag in `config.json`, which registers it in the Online Designer's
**@ Action Tags** popup. Registration is descriptive only — per the framework's `ExternalModules::getActionTags()`,
`config.json` supplies the tag name and description for display, and the module itself implements the behaviour in
its render hooks.

```
@VERSION
```

> **Resolved (was P0 - Critical):** Earlier versions injected a `case "em-project-setting-value"` handler into the
> core file `Classes/Piping.php`, which was lost on every REDCap upgrade and required the file to be writable. The
> module no longer modifies any REDCap source file.

### 2. Automatic Version Field Population

A field is treated as a version field if it carries `@VERSION`, or — when no field on the instrument is tagged — if
it is the only field whose name ends with the configured suffix (e.g., `_crfver`). On form load the module:
- Writes the current project version into the field, but only while the instrument instance has never been saved
- Hides the "Mark as Missing" icon for the version field
- Optionally sets the field as readonly (data entry forms only)

The write mirrors core's `@DEFAULT` condition in `Classes/DataEntry.php`, which applies a default only when the
field is empty **and** the form has no saved data. A form therefore permanently retains the version it was first
completed under. The value is assigned directly in JavaScript without dispatching change events, so REDCap's
`dataEntryFormValuesChanged` flag stays `false` and a form that is merely opened does not raise a save prompt.

> **Do not remove the `retriggerLogic()` call.** Because the version is written after REDCap has initialised, and
> without firing change events, REDCap's branching logic and calculations would otherwise still evaluate against an
> empty field. In JavaScript an empty value coerces to `0`, which silently flips comparisons — branching logic such
> as `[version] > 2` evaluates false on a form whose version is 3, hiding a field that should be shown, with no
> error anywhere. The module therefore calls REDCap's own `calculate()` and `doBranching()` for each version field
> once the page is ready, which is exactly what core does after it changes a value programmatically (see the
> reset-value link in `Classes/DataEntry.php`). `setDataEntryFormValuesChanged()` is deliberately *not* called, so
> the form is still not marked as edited. This is covered by the branching assertions in
> `E.122.700 - CRF Versioning.feature`.

### 3. Version Management Interface

Superusers can update the project version via:
- Standard module project settings
- Dedicated index page with validation (requires new version > current version, 1-999 range)

Version changes are recorded in the module's audit log with the user ID and timestamp.

## Configuration Settings

### Project-Level Settings

| Setting Key | Description | Type | Required | Access |
|-------------|-------------|------|----------|--------|
| `versioning-field-suffix` | Suffix identifying version fields (e.g., `_crfver`) | text | Yes | Superusers only |
| `current-project-version` | Current CRF version number | text | Yes | Superusers only |
| `version-field-auto-set-as-readonly` | Auto-apply @READONLY to version fields | checkbox | No | Superusers only |

**Branching Logic:** The `current-project-version` and `version-field-auto-set-as-readonly` settings are only visible when `versioning-field-suffix` is non-empty.

### Configuration audit log

`redcap_module_save_configuration($project_id)` records every configuration change to the module's **View Logs**
page. On save it diffs the submitted settings against the values held beforehand and writes one
`Configuration changed (project)` entry per changed key, carrying the setting name and its old and new values as
log parameters — REDCap shows these to super-users via the **Show Parameters** button. The first save diffs against
an empty baseline, so initial values are logged as `(empty) -> value`; settings left blank are not logged. This
module has no system-level settings, so the hook's system-scope branch never logs.

This is distinct from `logVersionChange()` below, which records changes to a project's *version value* rather than
to the module's settings.

## Class Methods

### VersioningModule.php

#### Constants

| Constant | Description |
|----------|-------------|
| `VersionActionTag` | The action tag (`@VERSION`) marking a field as a version field |
| `StalePipePrefix` | `[em-project-setting-value:` — an unresolved legacy pipe, treated as an empty value |

#### Hook Methods

| Method | Description |
|--------|-------------|
| `redcap_module_system_enable($version)` | Logs the event. No REDCap source files are modified. |
| `redcap_module_system_disable($version)` | Logs the event. |
| `redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)` | Applies versioning to the form, with the readonly setting permitted. |
| `redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)` | Applies versioning to a survey page. The readonly setting has never applied on surveys and remains off. |
| `redcap_module_link_check_display($project_id, $link)` | Returns the project link for all users. |
| `validateSettings($settings)` | Validates that `current-project-version` is numeric and `versioning-field-suffix` is non-empty. |

#### Utility Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `versionFields($project_id, $instrument)` | `array` | Returns the instrument's version fields: those annotated `@VERSION`, else the single field matching the configured suffix. Reads annotations via `REDCap::getDataDictionary()` and matches tags with core's `ActionTags::containsActionTags()`. |
| `isUnsavedForm($record, $instrument, $event_id, $repeat_instance)` | `bool` | True when the instrument instance has no saved data, via `Records::formHasData()`. Mirrors core's `@DEFAULT` condition. |
| `applyVersioning($project_id, $record, $instrument, $event_id, $repeat_instance, $allowReadonly)` | `void` | Shared entry point for both render hooks. Returns early if the project ID is empty, required settings are unconfigured, or the instrument has no version field. |
| `emitVersionScript($fields, $version, $setValue, $setReadonly)` | `void` | Emits the client-side script that sets the value, applies readonly, and hides the mark-as-missing icon. Configuration is passed as JSON encoded with `JSON_HEX_*` flags. |
| `logVersionChange($oldVersion, $newVersion)` | `void` | Records a version change in the module's audit log with project ID, old/new version, user ID, and timestamp. |

## REDCap Hooks Used

| Hook | Purpose |
|------|---------|
| `redcap_module_system_enable` | Audit log entry only |
| `redcap_module_system_disable` | Audit log entry only |
| `redcap_data_entry_form` | Populate the version field; handle readonly and mark-as-missing |
| `redcap_survey_page` | Populate the version field on survey pages |
| `redcap_module_link_check_display` | Enable project navigation link |

## Legacy Piping Injection (removed)

Versions up to and including v1.1.1 wrote a `case "em-project-setting-value"` block into
`APP_PATH_DOCROOT/Classes/Piping.php` on system enable and removed it on system disable, exposing
`[em-project-setting-value:versioning:current-project-version]` for use in `@DEFAULT`. The constants
`PipingFilePath`, `PipingCode` and `PipingSearchTerm` and the methods `addCodeToFile()` and `removeCodeFromFile()`
supported this and have all been removed.

### Migration

- **Deployment order matters.** Disable the module at system level *while still running v1.1.1* so its
  `redcap_module_system_disable` hook removes the insert. Deploying this version first orphans the block in
  `Piping.php`, where it must then be removed by hand.
- **Project annotations.** Replace `@DEFAULT = '[em-project-setting-value:...]'` with `@VERSION` (or remove it and
  rely on the suffix). Without the receiver REDCap stamps the tag into the field verbatim; the module treats a value
  beginning with `StalePipePrefix` as empty and overwrites it, but only on forms that have not yet been saved. A
  form already saved holding that literal must be corrected as data.

## Security Features

1. **Input Sanitization**: Version numbers validated via `filter_input()` with `FILTER_VALIDATE_INT` and min/max range (1-999)
2. **Output Escaping**: All HTML output uses `htmlspecialchars()` with `ENT_QUOTES` and `UTF-8` encoding in both `index.php` and `VersioningModule.php`
3. **No Core File Modification**: The module writes to no REDCap source file, so it needs no write access outside its own directory
4. **Encoded Script Payload**: Values passed to the client are JSON encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` so field names and settings cannot break out of the `<script>` block
5. **Superuser Restrictions**: All configuration settings are limited to superusers only (`super-users-only: true` in config.json); version update form only displayed to superusers
6. **Audit Logging**: Version changes are logged with project ID, user ID, old/new version, and timestamp via `logVersionChange()`

## Version Update Page (index.php)

The version update page (`index.php`) provides a superuser-only interface for managing project versions.

### POST Handling Flow

1. Receives `versioning-set-version` via POST
2. Validates using `filter_input(INPUT_POST, ..., FILTER_VALIDATE_INT)` with range 1-999
3. Compares against current version (must be strictly greater)
4. On success: saves via `setProjectSetting()`, logs via `logVersionChange()`
5. Displays escaped success/error messages

### Security Measures in index.php

- All POST input sanitized with `filter_input()` and range validation
- All output escaped with `htmlspecialchars(ENT_QUOTES, UTF-8)`
- Hidden form fields (prefix, page, pid) are escaped before rendering
- Form only rendered for superusers via `$module->isSuperUser()` check

## Usage Instructions

### Initial Setup

1. Enable module at system level (Control Center)
2. Enable module for specific project
3. Configure `versioning-field-suffix` (e.g., `_crfver`)
4. Set `current-project-version` (e.g., `1`)
5. Optionally enable auto-readonly for version fields

### Instrument Design

1. Create a text field in each instrument to hold the version
2. Add the action tag `@VERSION` to it. Alternatively, name the field with the configured suffix (e.g.,
   `patient_details_crfver`) and omit the tag
3. The version auto-populates while the instrument has no saved data, and is fixed from the first save onwards

### Version Updates

1. Navigate to External Modules > Versioning link
2. Enter new version number (must be greater than current, range 1-999)
3. Submit to update
4. Version change is recorded in the audit log

## Important Considerations

- **REDCap Upgrades**: No action required. The module modifies no core file, so a REDCap upgrade cannot undo its setup
- **Existing Data**: Enabling on an existing project affects only instruments that have not yet been saved. Forms saved beforehand keep an empty version field permanently — the module does not back-fill them, because the version those forms were completed under is unknown. Locate them with a data quality rule
- **Not Applied on Import**: As with core's `@DEFAULT`, the version is applied at form render only, so records created via the API or data import receive no version
- **Single Version Field**: Where no field is tagged `@VERSION`, only instruments with exactly one field matching the suffix are affected
- **Unconfigured Module Alert**: If the module is enabled for a project but required settings (`versioning-field-suffix` or `current-project-version`) are not configured, a JavaScript alert prompts the user to configure them

## Automated Testing

The module includes Cypress feature files for testing:

### Active Test Scenarios

| Feature File | Description |
|-------------|-------------|
| E.122.100 | Versioning configurations |
| E.122.200 | Enable module on all projects |
| E.122.300 | Make module discoverable |
| E.122.400 | Allow non-admins to enable this module |
| E.122.500 | Hide this module from non-admins |
| E.122.700 | CRF versioning functionality |
| E.122.1200 | Module configuration permissions in projects |

### Custom Step Definitions

Custom step definitions are split across two files in `automated_tests/step_definitions/`:

#### noncore.js

General-purpose reusable Cypress/Cucumber steps covering:

- **MailHog Integration**: Email verification, password/verification code extraction, link clicking, email count validation
- **PDF Verification**: Content validation in downloaded and archived PDFs, local storage PDF checks
- **Data Resolution Workflow**: DRW option selection, comment management, query workflows, user rights assignment
- **Calendar Events**: Ad hoc event creation, schedule event interaction, calendar navigation, calendar popup handling
- **UI Interactions**: Alert management, report filters, file uploads, instrument rights, radio/checkbox/dropdown selections
- **Export Verification**: File hash validation, ZIP extraction, CDISC XML import
- **Record Status Dashboard**: Non-longitudinal bubble navigation, repeating instrument instance selection

#### external_module.js

Step definitions specific to external module and versioning functionality:

- **External Module Management**: Dialog box closing for external modules (active); button click for enable/delete version and field configuration (commented out, superseded by RCTF core steps)
- **Control Center To-Do List**: Icon clicks and request visibility checks within Pending/Low Priority/Archived request tables
- **Versioning Field Verification**: Disabled field state validation for version fields

### Test Data Fixtures

- `fixtures/cdisc_files/E122700.xml` - CDISC XML for CRF versioning tests
- `fixtures/cdisc_files/Project_redcap_val_nodata.xml` - Validation project template

### User Requirement Specification

- `urs/User Requirement Specification.spec` - Formal URS document for the module

## Dependencies

- REDCap External Modules Framework v14
- Write access to `APP_PATH_DOCROOT/Classes/Piping.php` (until piping injection is replaced)
- Cypress with Badeball Cucumber preprocessor (for automated tests)
- RCTF (REDCap Cypress Test Framework) npm package (for test step definitions)
