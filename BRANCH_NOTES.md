# Branch notes — `feat/version-action-tag`

Working notes for this branch. **Delete this file before merging.**

## What this branch does

Removes the `Classes/Piping.php` patch and replaces `@DEFAULT = '[em-project-setting-value:...]'`
with a module-implemented `@VERSION` action tag. The module now writes the version into the field
itself from its render hooks, so it modifies no REDCap source file. See the commit message on
`feat: replace Piping.php patch with @VERSION action tag` for the full rationale.

Status: **E.122.700 passes locally**, including the branching-logic assertions at lines 153 and 160
that caught the one real defect (see "Do not remove `retriggerLogic()`" in `docs/DOCUMENTATION.md`).
E.122.100 not yet re-run — it has two module-log tables that were edited.

## Why the branch may not be needed

This work is a fallback. The preferred outcome is that Vanderbilt accepts a generic piping receiver
into core REDCap:

```
[em-project-setting-value:<module_prefix>:<setting_key>]
```

An email making that case is drafted but not sent. If core gains the receiver, every External Module
can surface its project settings server-side — reaching alerts, PDFs, descriptive text, reports and
branching logic, none of which a client-side approach can touch. This branch would then be
unnecessary, or would keep `@VERSION` only as a convenience.

If Vanderbilt declines, this branch is the way forward and the remaining work below applies.

## Remaining work

### 1. Version bump to v1.2.0

Not done. Requires, in one commit so the pieces stay aligned:

- Rename the module directory `versioning_v1.1.1` → `versioning_v1.2.0`
- `EM_VERSION` and `EM_MODULE` in `.github/workflows/cypress-tests.yml` — **critical**: the workflow
  bind-mounts the EM over `modules/<EM_MODULE>`. If that path no longer exists the mount silently
  misses and REDCap serves the image's baked copy, so CI passes while testing the wrong code.
- Every `v1.1.1` string elsewhere. Current counts:

  | File | Occurrences |
  |---|---|
  | `automated_tests/E.122.700 - CRF Versioning.feature` | 22 |
  | `automated_tests/E.122.500 - Hide this module from non-admins.feature` | 13 |
  | `automated_tests/E.122.300 - Make module discoverable.feature` | 9 |
  | `automated_tests/E.122.1200 - Module configuration permissions in projects.feature` | 9 |
  | `automated_tests/E.122.400 - Allow non-admins to enable this module.feature` | 8 |
  | `automated_tests/E.122.1300 - Config change audit log.feature` | 8 |
  | `automated_tests/E.122.200 - Enable module on all projects.feature` | 5 |
  | `README.md` | 5 |
  | `docs/DOCUMENTATION.md` | 4 |
  | `automated_tests/E.122.100 - Versioning Configurations.feature` | 3 |
  | `.github/workflows/cypress-tests.yml` | 2 |

  Note the feature files assert on both `Versioning - v1.1.1` (UI labels) and `versioning_v1.1.1`
  (log messages), so a blanket replace of `v1.1.1` → `v1.2.0` covers both.

### 2. Make `versioning-field-suffix` optional

Currently still `"required": true`, which forces admins of tag-based projects to invent a suffix that
nothing uses. It cannot be *removed* — it is the backwards-compatibility path for every project built
before `@VERSION` — but it should not be mandatory. Four changes:

- `config.json` — `"required": false` on `versioning-field-suffix`, and drop the `branchingLogic`
  blocks from `current-project-version` and `version-field-auto-set-as-readonly` so they are always
  visible
- `validateSettings()` — remove the "Versioning Field Suffix should not be empty" rule
- `applyVersioning()` — the guard currently aborts when the suffix is empty, which would break a
  tag-only project entirely. Require only `current-project-version`
- The `current-project-version` setting description still reads "any new forms containing a field
  with the given suffix" — wording predates the tag

Optional, same edit: the "please configure the module" alert currently fires on *every* form in an
unconfigured project, including forms with no version field. Moving it after the `versionFields()`
check would limit it to forms where versioning is actually expected. This is a behaviour change, so
it was deliberately left out of the mechanism-swap commit.

## Deployment gotcha

Whenever this ships, **disable the module at system level while still running v1.1.1** so its
`redcap_module_system_disable` hook removes the `Piping.php` insert. Deploying this version first
orphans the block in core, where it must then be deleted by hand. Existing projects also need their
`@DEFAULT = '[em-project-setting-value:...]'` annotations replaced with `@VERSION`.

## CI on this branch

`cypress-tests.yml` triggers only on push to `main` and `workflow_dispatch` — there is no
`pull_request` trigger, so neither pushing this branch nor opening a PR runs anything. To get CI
coverage before merge: Actions → **Versioning EM Cypress Tests** → **Run workflow** → select this
branch. `actions/checkout` has no explicit `ref`, so it checks out the dispatched branch.
