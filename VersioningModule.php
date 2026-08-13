<?php

namespace CCTC\VersioningModule;

use ActionTags;
use Records;
use REDCap;
use ExternalModules\AbstractExternalModule;

class VersioningModule extends AbstractExternalModule {

    /** Fields carrying this action tag are populated with the project's current version. */
    const VersionActionTag = '@VERSION';

    /**
     * Projects set up before this module stopped patching Piping.php still carry
     * @DEFAULT = '[em-project-setting-value:versioning:current-project-version]'.
     * With the piping receiver gone REDCap cannot resolve it and stamps the tag
     * verbatim, so treat that literal as an empty field and overwrite it.
     */
    const StalePipePrefix = '[em-project-setting-value:';

    public function redcap_module_link_check_display($project_id, $link) {
        return $link;
    }

    function redcap_module_system_enable($version): void
    {
        $this->log('Module system enable initiated', ['version' => $version]);
    }

    function redcap_module_system_disable($version): void
    {
        $this->log('Module system disable initiated', ['version' => $version]);
    }

    public function validateSettings($settings): ?string
    {
        if (array_key_exists("current-project-version", $settings)) {
            if (!is_numeric($settings['current-project-version'])) {
                return "The current project version should be a number";
            }
        }

        if (array_key_exists("versioning-field-suffix", $settings)) {
            if (empty($settings['versioning-field-suffix'])) {
                return "Versioning Field Suffix should not be empty";
            }
        }
        return null;
    }

    // Add audit logging for version changes
    public function logVersionChange(int $oldVersion, int $newVersion): void
    {
        $this->log('Version updated', [
            'project_id' => $this->getProjectId(),
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'updated_by' => defined('USERID') ? USERID : 'unknown',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Fields on this instrument that carry the project version: those annotated
     * @VERSION, or - for projects set up before the tag existed - the single
     * field whose name ends with the configured suffix.
     */
    private function versionFields($project_id, string $instrument): array
    {
        $dictionary = REDCap::getDataDictionary($project_id, 'array', false, [], [$instrument]);

        $tagged = [];
        foreach ($dictionary as $field => $meta) {
            if (ActionTags::containsActionTags($meta['field_annotation'] ?? '', self::VersionActionTag)) {
                $tagged[] = $field;
            }
        }
        if (!empty($tagged)) return $tagged;

        $suffix = $this->getProjectSetting("versioning-field-suffix");
        if (empty($suffix)) return [];

        $bySuffix = array_values(array_filter(
            array_keys($dictionary),
            fn($field) => str_ends_with($field, $suffix)
        ));

        // As before, an ambiguous form is left alone: versioning applies only
        // where exactly one field matches.
        return count($bySuffix) == 1 ? $bySuffix : [];
    }

    /**
     * Mirrors core's @DEFAULT condition (Classes/DataEntry.php): a default is
     * only applied while the instrument instance has never been saved, so a
     * form permanently retains the version it was first completed under.
     */
    private function isUnsavedForm($record, string $instrument, $event_id, $repeat_instance): bool
    {
        if ($record === null || $record === '') return true;
        return !Records::formHasData($record, $instrument, $event_id, $repeat_instance ?: 1);
    }

    private function applyVersioning($project_id, $record, string $instrument, $event_id, $repeat_instance, bool $allowReadonly): void
    {
        if (empty($project_id)) return;

        // Retrieve the mandatory fields for the external module from the configuration settings
        $crfVerFieldSuffix = $this->getProjectSetting("versioning-field-suffix");
        $curProjectVersion = $this->getProjectSetting("current-project-version");

        if (empty($crfVerFieldSuffix) || empty($curProjectVersion)) {
            echo "<script type='text/javascript'>
                    alert('Please ensure the mandatory fields in the Versioning External Module are configured.');
                </script>";
            return;
        }

        $fields = $this->versionFields($project_id, $instrument);
        if (empty($fields)) return;

        $setReadonly = $allowReadonly && (bool) $this->getProjectSetting("version-field-auto-set-as-readonly");

        $this->emitVersionScript(
            $fields,
            trim((string) $curProjectVersion),
            $this->isUnsavedForm($record, $instrument, $event_id, $repeat_instance),
            $setReadonly
        );
    }

    private function emitVersionScript(array $fields, string $version, bool $setValue, bool $setReadonly): void
    {
        $config = json_encode([
            'fields'    => array_values($fields),
            'version'   => $version,
            'setValue'  => $setValue,
            'readonly'  => $setReadonly,
            'stalePipe' => self::StalePipePrefix,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo <<<HTML
<script type="text/javascript">
(function () {
    var cfg = {$config};

    function applyVersioning() {
        cfg.fields.forEach(function (field) {
            var input = document.querySelector('[name="' + field + '"]');

            if (input && cfg.setValue) {
                var current = (input.value || '').trim();
                if (current === '' || current.indexOf(cfg.stalePipe) === 0) {
                    // Assigning .value fires no events, so REDCap's
                    // dataEntryFormValuesChanged flag stays false and the user is
                    // not prompted to save a form they have only looked at.
                    input.value = cfg.version;
                }
            }

            if (cfg.readonly) {
                var row = document.getElementById(field + '-tr');
                if (row) row.classList.add('@READONLY');
            }

            // If the form has mark as missing icons, prevent the user clearing
            // the value in the version field.
            var missing = document.querySelector('img.missingDataButton[fieldname="' + field + '"]');
            if (missing) missing.style.display = 'none';
        });
    }

    /**
     * REDCap evaluates branching logic and calculations from the values present
     * when the page initialises. The version is written afterwards and without
     * firing change events, so logic keyed on the version field would still see
     * it as empty - and in JavaScript an empty value coerces to 0, silently
     * flipping comparisons such as [version] > 2. Re-run REDCap's own evaluators,
     * exactly as core does after it changes a value programmatically (see the
     * reset-value link in Classes/DataEntry.php). setDataEntryFormValuesChanged()
     * is deliberately NOT called, so the form is still not marked as edited.
     */
    function retriggerLogic() {
        if (!cfg.setValue) return;
        cfg.fields.forEach(function (field) {
            try { if (typeof calculate === 'function') calculate(field); } catch (e) {}
            try { if (typeof doBranching === 'function') doBranching(field); } catch (e) {}
        });
    }

    // The hook renders after the form, so the inputs already exist: set the
    // value straight away rather than waiting, to avoid a visible flicker.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyVersioning);
    } else {
        applyVersioning();
    }

    // Re-evaluation must happen after REDCap's own page initialisation. jQuery
    // ready callbacks run in registration order and REDCap registers its own
    // well before this point, so ours runs last.
    if (window.jQuery) {
        window.jQuery(retriggerLogic);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', retriggerLogic);
    } else {
        retriggerLogic();
    }
})();
</script>
HTML;
    }

    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance): void
    {
        $this->applyVersioning($project_id, $record, $instrument, $event_id, $repeat_instance, true);
    }

    public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance): void
    {
        // Surveys were previously versioned by core's @DEFAULT handling, which
        // applies on survey pages too. The readonly setting has never applied
        // there, so it stays off.
        $this->applyVersioning($project_id, $record, $instrument, $event_id, $repeat_instance, false);
    }

    public function redcap_module_save_configuration($project_id): void
    {
        $this->auditConfigurationChange($project_id);
    }

    private function auditConfigurationChange($project_id): void
    {
        $config   = $this->getConfig();
        $isSystem = empty($project_id);
        $scope    = $isSystem ? 'system' : 'project';
        $keys     = $this->collectSettingKeys($config[$scope . '-settings'] ?? []);
        if (empty($keys)) return;

        $snapshotKey = 'audit-snapshot-' . $scope;
        $read  = fn($k)     => $isSystem ? $this->getSystemSetting($k)    : $this->getProjectSetting($k);
        $write = fn($k, $v) => $isSystem ? $this->setSystemSetting($k, $v) : $this->setProjectSetting($k, $v);

        $new = [];
        foreach ($keys as $k) $new[$k] = $this->normaliseSetting($read($k));

        $rawOld = $read($snapshotKey);
        $old = is_string($rawOld) ? json_decode($rawOld, true) : null;
        // First save has no prior snapshot: treat the baseline as empty so the
        // initial configuration's real values are still logged ((empty) -> value),
        // while settings left blank stay '' vs '' and produce no noise.
        if (!is_array($old)) $old = [];

        $changed = false;
        foreach ($keys as $k) {
            $before = $this->normaliseSetting($old[$k] ?? null);
            $after  = $new[$k];
            if ($before !== $after) {
                $changed = true;
                $this->log("Configuration changed ($scope)", [
                    'project_id' => $project_id,
                    'setting'    => $k,
                    'old_value'  => $before === '' ? '(empty)' : $before,
                    'new_value'  => $after  === '' ? '(empty)' : $after,
                ]);
            }
        }
        if ($changed) $write($snapshotKey, json_encode($new));
    }

    private function collectSettingKeys(array $settings): array
    {
        $keys = [];
        foreach ($settings as $s) {
            if (!isset($s['key'])) continue;
            if (($s['type'] ?? '') === 'descriptive') continue;
            $keys[] = $s['key'];
        }
        return $keys;
    }

    private function normaliseSetting($v): string
    {
        if ($v === null || $v === false) return '';
        if ($v === true) return '1';
        if (is_array($v)) {
            // A repeatable setting the admin never filled comes back as an array
            // of empty entries (e.g. [null]), not as null. Treat that as unset,
            // otherwise the first save logs a phantom "(empty) -> [null]" change
            // for every blank repeatable.
            foreach ($v as $entry) {
                $hasValue = is_array($entry) ? !empty($entry) : trim((string) ($entry ?? '')) !== '';
                if ($hasValue) return json_encode($v);
            }
            return '';
        }
        return trim((string) $v);
    }
}
