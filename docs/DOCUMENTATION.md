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
versioning_v1.0.0/
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
versioning_v1.0.0/
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

### 1. Custom Piping Parameter

The module extends REDCap's piping functionality by injecting a custom case handler into `Piping.php`. This enables the use of:

```
[em-project-setting-value:versioning:current-project-version]
```

This piping parameter can be used in `@DEFAULT` action tags to automatically populate version fields when new forms are created.

> **Known Issue (P0 - Critical):** The piping injection modifies a core REDCap file (`Classes/Piping.php`), which will be overwritten on REDCap upgrades.

### 2. Automatic Version Field Population

When a form contains exactly one field ending with the configured suffix (e.g., `_crfver`), the module:
- Detects the version field on form load
- Hides the "Mark as Missing" icon for the version field
- Optionally sets the field as readonly

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

## Class Methods

### VersioningModule.php

#### Constants

| Constant | Description |
|----------|-------------|
| `PipingFilePath` | Path to `APP_PATH_DOCROOT/Classes/Piping.php` |
| `PipingCode` | SQL case handler code injected into Piping.php |
| `PipingSearchTerm` | Search pattern used to locate the injection point |

#### Hook Methods

| Method | Description |
|--------|-------------|
| `redcap_module_system_enable($version)` | Injects piping code into Piping.php on system enable. Logs the event. |
| `redcap_module_system_disable($version)` | Removes piping code from Piping.php on system disable. Logs the event. |
| `redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)` | Handles version field display: hides mark-as-missing icon, optionally applies readonly. Returns early if project ID is empty or required settings are not configured. |
| `redcap_module_link_check_display($project_id, $link)` | Returns the project link for all users. |
| `validateSettings($settings)` | Validates that `current-project-version` is numeric and `versioning-field-suffix` is non-empty. |

#### Utility Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `addCodeToFile($filePath, $searchTerm, $insertCode)` | `bool` | Injects code into a target file. Includes comprehensive error handling: validates file existence, readability, writability; checks for duplicate injection; logs success/failure. Throws `Exception` on error. |
| `removeCodeFromFile($filePath, $removeCode)` | `bool` | Removes injected code from a target file. Includes error handling similar to `addCodeToFile()`. Returns `true` if code was not found (idempotent). |
| `HideMarkAsMissingIcon($crfVerField)` | `void` | Outputs JavaScript to hide the mark-as-missing icon for the version field. Escapes the field name with `htmlspecialchars()`. |
| `logVersionChange($oldVersion, $newVersion)` | `void` | Records a version change in the module's audit log with project ID, old/new version, user ID, and timestamp. |

## REDCap Hooks Used

| Hook | Purpose |
|------|---------|
| `redcap_module_system_enable` | Inject custom piping code into Piping.php |
| `redcap_module_system_disable` | Remove custom piping code from Piping.php |
| `redcap_data_entry_form` | Handle version field display and readonly behavior |
| `redcap_module_link_check_display` | Enable project navigation link |

## Piping Code Injection

### Location
The module injects code into:
```
APP_PATH_DOCROOT/Classes/Piping.php
```

### Injected Code
The injection adds a new switch case `em-project-setting-value` that:
1. Accepts module name and setting key as parameters
2. Queries the external module settings table using parameterized queries
3. Returns the setting value for piping

### SQL Query (Parameterized)
```sql
SELECT b.value AS settingValue
FROM redcap_external_modules a
JOIN redcap_external_module_settings b
    ON a.external_module_id = b.external_module_id
WHERE a.directory_prefix = ?
    AND b.project_id = ?
    AND b.`key` = ?
```

### Error Handling for File Operations

Both `addCodeToFile()` and `removeCodeFromFile()` include:
- File existence validation
- Readability/writability checks
- Duplicate injection detection (idempotent operations)
- Structured logging on success and failure
- Exception propagation for callers to handle

## Security Features

1. **Input Sanitization**: Version numbers validated via `filter_input()` with `FILTER_VALIDATE_INT` and min/max range (1-999)
2. **Output Escaping**: All HTML output uses `htmlspecialchars()` with `ENT_QUOTES` and `UTF-8` encoding in both `index.php` and `VersioningModule.php`
3. **Parameterized Queries**: SQL injection prevention in piping code via `db_query()` with parameter binding
4. **File Operation Checks**: Validates file existence, readability, and writability before modifications; logs all file operations
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

1. Create a text field in each instrument with the configured suffix (e.g., `patient_details_crfver`)
2. Add action tag: `@DEFAULT = '[em-project-setting-value:versioning:current-project-version]'`
3. The version will auto-populate when new records are created

### Version Updates

1. Navigate to External Modules > Versioning link
2. Enter new version number (must be greater than current, range 1-999)
3. Submit to update
4. Version change is recorded in the audit log

## Important Considerations

- **Module Upgrades**: When upgrading REDCap, disable then re-enable the module at system level to update the Piping.php injection
- **Existing Data**: Enabling on existing projects will populate version fields on form load, but values are not saved until form submission
- **Single Version Field**: Only instruments with exactly one field matching the suffix are affected
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
