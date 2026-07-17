### Versioning ###

[![Versioning EM Cypress Tests](https://github.com/CCTC-team/versioning/actions/workflows/cypress-tests.yml/badge.svg)](https://github.com/CCTC-team/versioning/actions/workflows/cypress-tests.yml)

As projects evolve over time with updates and amendments, it is often useful (or imperative) to adapt questions to
account for different versions. For instance, validating a user entered value may need to change for later versions, 
when, for example, in version 1 the maximum value is 20, whereas for version 2 the maximum value is 50.

To support this, the Versioning module simply enables the project designer to include a field in their forms that tracks
the current version. The fields should be created using a specific suffix (as defined in the module's project settings),
and, as users create new forms containing a matching field, the value in the field is automatically updated to the 
current version. This is handled by a simple javascript script so if the user chooses not to save the form there is
no prompt to save it unless further data has also been entered. The versioning field is not updated if already entered,
so it retains the version of the form when first saved.

#### System set up ####

Enabling the module at a system level will AUTOMATICALLY do the following via the system hook
`redcap_module_system_enable`;

1. Insert code in the `Piping.php` file - the following is inserted after the switch case statement `case "mycap-participant-link" :` around line 2036
    ```php
    //****** inserted by Versioning module ******
                    case "em-project-setting-value" :
                        $wrapThisItem = true;
                        $module = $matches['param1'][0];
                        $projSettingKey = $matches['param2'][0];

                        $sql = "select
                                    b.value as settingValue
                                from
                                    redcap_external_modules a,
                                    redcap_external_module_settings b
                                where
                                    a.external_module_id = b.external_module_id
                                    and a.directory_prefix = '$module'
                                    and b.project_id = $project_id
                                    and b.`key` = '$projSettingKey'";
                                                        $q = db_query($sql);
                                                        if (db_num_rows($q)) {
                                                            $res = db_result($q, 0);
                                                            $matches['post-pipe'][$key] = $res;
                                                        }
                        break;
        //****** end of insert ******
    ```
  This makes the versioning parameter `em-project-setting-value:versioning:current-project-version` available for use in the instruments in projects.

Disabling the module at a system level will AUTOMATICALLY do the following via the system hook
`redcap_module_system_disable`.
1. Remove the code inserted into `Piping.php`

When a new version of the module becomes available, the module should be disabled and then re-enabled from the Control Center at the system level. Failure to do so may cause the module to malfunction.

#### Set up and configuration by project


Set up is straightforward and there are just a few project level settings.

Settings

- `versioning-field-suffix` - this setting allows a superuser to set the suffix for fields that are treated as
  'versioning fields'. For example, using the suffix '_crfver', for forms that should be versioned, form designers 
  create a simple text field with a label of their choosing and make the variable name appropriate to the chosen suffix
  i.e. for form 'Form 1', create a Text Box field, add the label 'CRF version' and name the variable 'form_1_crfver'.
  If a form has exactly one field with the suffix then versioning is applicable; otherwise the versioning module has
  no effect.
- `current-project-version` - this setting allows a superuser to update the current version to be applied. Note: there
  are no restrictions on the value being entered here unlike when using the Versioning index page - see below
- `version-field-auto-set-as-readonly` - when checked, this setting will automatically make the versioning fields 
  readonly as if they were given @READONLY action tag. This just simplifies set up of the fields for each form

#### Usage

Add "@DEFAULT = '[em-project-setting-value:versioning:current-project-version]'" to the version field
of the instruments in the designer.

Link and Index page

All users can view the current version using the External Modules link called 'Versioning' in the left pane. For 
non-superusers, this simply shows the currently set version.

For superusers, there is the further option to update the current version. Updating the value from here includes some
rudimentary checks that are not present when updating the version directly in the project settings. Updating the value
is only possible if;
- is an integer with a maximum value of 999
- is a value at least one greater than the current value. Users can increment the version by more than one if required

#### Considerations

The Versioning module should be added at the inception of the project. It can be enabled once data capture has started,
but be aware that any forms containing a field with the versioning field suffix will be populated with the current
version, but will not be stored in the database until the form has been saved.

To-Do
Add a 3rd parameter to external module project setting for repeating fields

#### Automation Testing

The module includes comprehensive **Cypress automated** tests using the **Cucumber/Gherkin framework**. To set up Cypress, refer to [Setup_Overview.md](https://github.com/CCTC-team/CCTC_REDCap_Docker/blob/redcap_val/Setup_Overview.md).

All automated test scripts are located in the `automated_tests` directory. The test suite automatically picks up the scripts from this folder. These scripts can also be used to manually test the external module. The directory contains:
- Fixture files
- User Requirement Specification (URS) documents
- Feature test scripts

**Step Definition Locations:**

Step definitions are organized across multiple locations in the `redcap_cypress` repo under `redcap_cypress/cypress/support/step_definitions/`:

- **Non-core feature step definitions** are in `redcap_cypress/cypress/support/step_definitions/noncore.js`
- **Shared EM step definitions** (used by more than one external module) are in `redcap_cypress/cypress/support/step_definitions/external_module.js`

#### GitHub Actions Workflow

The module ships with a CI workflow at [.github/workflows/cypress-tests.yml](.github/workflows/cypress-tests.yml) that runs this module's own Cypress specs end-to-end against a prebuilt all-in-one REDCap image, using a self-contained Cypress runner image. There is no 3-container compose, no host `npm ci`, and no cloning of the harness at runtime — both images are published ahead of time and pulled from GHCR.

**Triggers**
- `push` to `main` (ignoring doc-only changes: `**/*.md`, `LICENSE`, `.gitignore`, `docs/**`)
- Manual `workflow_dispatch`

**What it does** (`cypress-tests` job)
1. Checks out the Versioning EM (this repo) into `versioning_em/`.
2. Logs in to GHCR and pulls two prebuilt images: `redcap-aio` (REDCap + MariaDB + MailHog in one container via supervisord) and `cypress-runner-aio` (the suite with `rctf` + `redcap_rsvc` baked in).
3. Stages the EM under test — strips `.git`/`.github` so only the module payload remains.
4. Starts the AIO container (ports `8443`/`8025`, volume `cctc_mariadb_data`), bind-mounting **this commit's** EM over the image's `modules/versioning_v1.1.0` so REDCap serves the code under test with no rebuild.
5. Waits for REDCap to come up (first boot initialises the DB).
6. Runs the runner image, which copies this module's `automated_tests` out of the container and runs only its `E.122.*` specs (excluding `*REDUNDANT*`), up to 3 attempts per spec, on Chromium. It reaches the DB/files over the mounted Docker socket and the UI over host networking.
7. Uploads the mochawesome reports (and, on failure, screenshots) as artifacts retained for 7 days.

**Follow-on jobs**
- `prune-artifacts` — deletes artifacts from older runs, keeping only the latest 2.
- `publish-report` — merges the run's mochawesome JSON into one combined HTML report and publishes it to GitHub Pages (report named `versioning_v1.1.0.html`, also served at the Pages root as `index.html`).

**Required repository secrets**
- `CCTC_TEAM_PAT` — PAT with `read:packages` for the private `redcap-aio` / `cypress-runner-aio` GHCR images.

**Version pins** (set as `env` at the top of the workflow)
- `AIO_IMAGE` / `RUNNER_IMAGE` — the GHCR image refs; both must be built for the **same** REDCap version.
- `EM_NAME` / `EM_VERSION` — `versioning` / `v1.1.0`. `EM_MODULE` (`versioning_v1.1.0`) is the directory REDCap discovers the module by and the runner uses to locate the specs. Bump `EM_VERSION`/`EM_MODULE` when releasing a new module version so the mount path and spec discovery stay aligned.

---

## Who are we

The Cambridge Cancer Trials Centre (CCTC) is a collaboration between Cambridge University Hospitals NHS Foundation Trust, the University of Cambridge, and Cancer Research UK. Founded in 2007, CCTC designs and conducts clinical trials and studies to improve outcomes for patients with cancer or those at risk of developing it. In 2011, CCTC began hosting the Cambridge Clinical Trials Unit - Cancer Theme (CCTU-CT).

CCTC has two divisions: Cancer Theme, which coordinates trial delivery, and Clinical Operations.