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

Enabling the module at a system level requires no further action. The module makes no changes to REDCap's own
source files; everything it does is done through the External Module framework's hooks.

> **Upgrading from v1.1.1 or earlier.** Those versions inserted a `case "em-project-setting-value"` block into
> REDCap's `Classes/Piping.php` when the module was enabled at system level, and removed it again when disabled.
> That mechanism is gone. **Before deploying this version, disable the module at system level while still running
> the old version**, so its `redcap_module_system_disable` hook removes the insert. If the new version is deployed
> first, the removal hook no longer exists and the block is orphaned in `Piping.php`, where it must be deleted by
> hand. See [Migrating existing projects](#migrating-existing-projects) for the project-level changes.

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

##### Configuration audit log

Every change to the module's configuration is recorded to the module's **View Logs** page (Control Center →
External Modules → View Logs). One entry is written per changed setting, recording the setting name, its old and
new values, the user who made the change and when. The first save on a freshly configured module logs the values
that were actually set (as `(empty) -> value`); settings left blank are not logged. Old and new values are held as
log parameters, which REDCap shows to super-users via the **Show Parameters** button. Note this is separate from the
Versioning index page's own version-change log, which records project version changes rather than module settings.

#### Usage

Add the `@VERSION` action tag to the version field of the instruments in the designer. The tag is listed in the
designer's **@ Action Tags** popup under this module.

A field is treated as a version field if **either** of the following is true:

- it carries the `@VERSION` action tag, or
- it is the only field on the instrument whose name ends with the configured `versioning-field-suffix`

The action tag takes precedence: if any field on an instrument is tagged, only tagged fields are versioned and the
suffix is ignored for that instrument. The suffix route is retained so projects built before `@VERSION` existed keep
working unchanged.

##### Migrating existing projects

Projects created against v1.1.1 or earlier carry
`@DEFAULT = '[em-project-setting-value:versioning:current-project-version]'` on their version fields. Once the
`Piping.php` insert is gone REDCap can no longer resolve that receiver, so it writes the tag into the field
verbatim. Replace those annotations with `@VERSION` (or remove them and rely on the suffix).

As a safety net the module treats a field whose value begins with `[em-project-setting-value:` as empty and
overwrites it with the current version. That only helps on forms that have not yet been saved — a form already
saved with the unresolved literal in it holds that value in the database and needs correcting as data.

Link and Index page

All users can view the current version using the External Modules link called 'Versioning' in the left pane. For 
non-superusers, this simply shows the currently set version.

For superusers, there is the further option to update the current version. Updating the value from here includes some
rudimentary checks that are not present when updating the version directly in the project settings. Updating the value
is only possible if;
- is an integer with a maximum value of 999
- is a value at least one greater than the current value. Users can increment the version by more than one if required

#### Considerations

The Versioning module should be added at the inception of the project. It can be enabled once data capture has
started, but be aware that the version is only applied to an instrument that has not yet been saved. Forms already
saved before the module was enabled keep an empty version field permanently — the module deliberately does not
back-fill them, because the version those forms were actually completed under is not known. Find them with a data
quality rule and resolve them deliberately.

The version is written when the form is rendered and is stored only when the form is saved, so a form that is opened
and abandoned records nothing. As with REDCap's own `@DEFAULT`, the version is not applied to records created through
the API or by data import.

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
4. Starts the AIO container (ports `8443`/`8025`, volume `cctc_mariadb_data`), bind-mounting **this commit's** EM over the image's `modules/versioning_v1.1.1` so REDCap serves the code under test with no rebuild.
5. Waits for REDCap to come up (first boot initialises the DB).
6. Runs the runner image, which copies this module's `automated_tests` out of the container and runs only its `E.122.*` specs (excluding `*REDUNDANT*`), up to 3 attempts per spec, on Chromium. It reaches the DB/files over the mounted Docker socket and the UI over host networking.
7. Uploads the mochawesome reports (and, on failure, screenshots) as artifacts retained for 7 days.

**Follow-on jobs**
- `prune-artifacts` — deletes artifacts from older runs, keeping only the latest 2.
- `publish-report` — merges the run's mochawesome JSON into one combined HTML report and publishes it to GitHub Pages **per module version**: the report for this run lands at `/<EM_VERSION>/index.html` (e.g. `/v1.1.1/`) and the Pages root serves an index linking every published version, newest first. Previously published versions are preserved by restoring the cumulative site from the `pages-store` branch before the new version is added and the snapshot force-pushed back.

**Required repository secrets**
- `CCTC_TEAM_PAT` — PAT with `read:packages` for the private `redcap-aio` / `cypress-runner-aio` GHCR images.

**Version pins** (set as `env` at the top of the workflow)
- `AIO_IMAGE` / `RUNNER_IMAGE` — the GHCR image refs; both must be built for the **same** REDCap version.
- `EM_NAME` / `EM_VERSION` — `versioning` / `v1.1.1`. `EM_MODULE` (`versioning_v1.1.1`) is the directory REDCap discovers the module by and the runner uses to locate the specs. Bump `EM_VERSION`/`EM_MODULE` when releasing a new module version so the mount path and spec discovery stay aligned.

---

## Who are we

The Cambridge Cancer Trials Centre (CCTC) is a collaboration between Cambridge University Hospitals NHS Foundation Trust, the University of Cambridge, and Cancer Research UK. Founded in 2007, CCTC designs and conducts clinical trials and studies to improve outcomes for patients with cancer or those at risk of developing it. In 2011, CCTC began hosting the Cambridge Clinical Trials Unit - Cancer Theme (CCTU-CT).

CCTC has two divisions: Cancer Theme, which coordinates trial delivery, and Clinical Operations.