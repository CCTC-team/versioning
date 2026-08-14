<?php

namespace CCTC\VersioningModule;

use Exception;
use REDCap;
use ExternalModules\AbstractExternalModule;

class VersioningModule extends AbstractExternalModule {

    const PipingFilePath = APP_PATH_DOCROOT . "/Classes/Piping.php";
    // Markers delimiting the inserted block. Removal matches on these rather than
    // on the PipingCode text, so a block written by an EARLIER module version is
    // still found: REDCap calls redcap_module_system_enable() on a version change
    // without calling the old version's disable hook, and PHP accepts duplicate
    // case labels silently, using whichever appears first.
    const PipingMarkerStart = '//****** inserted by Versioning module ******';
    const PipingMarkerEnd   = '//****** end of insert ******';

    const PipingCode = self::PipingMarkerStart . '
                    case "em-project-setting-value" :
                        $wrapThisItem = true;
                        $module = $matches[\'param1\'][$key];
                        $projSettingKey = $matches[\'param2\'][$key];
                        // Optional third parameter: selects one entry of a repeatable
                        // setting or sub_settings group. 1-based, to match REDCap\'s
                        // repeat instances.
                        $settingIndex = trim($matches[\'param3\'][$key] ?? "");
                        if ($settingIndex === "") {
                            // "Place instance in proper place" earlier in this file moves
                            // any NUMERIC trailing parameter into \'instance\' and blanks
                            // the original, so a numeric index never survives in param3.
                            // This receiver has no record-instance meaning, so recover it.
                            $fromInstance = trim($matches[\'instance\'][$key] ?? "");
                            if (is_numeric($fromInstance)) $settingIndex = $fromInstance;
                        }

                        // Use parameterized query to prevent SQL injection
                        $sql = "SELECT b.value AS settingValue
                                FROM redcap_external_modules a
                                JOIN redcap_external_module_settings b
                                    ON a.external_module_id = b.external_module_id
                                WHERE a.directory_prefix = ?
                                    AND b.project_id = ?
                                    AND b.`key` = ?";
                        $q = db_query($sql, [$module, $project_id, $projSettingKey]);
                        if (db_num_rows($q)) {
                            $res = db_result($q, 0);
                            if ($settingIndex !== "") {
                                // Repeatable settings and sub_settings are stored
                                // JSON-encoded. Anything we cannot resolve to a scalar
                                // yields "", as piping does elsewhere.
                                $decoded = is_numeric($settingIndex) ? json_decode($res, true) : null;
                                $offset = (int)$settingIndex - 1;
                                $res = (is_array($decoded) && $offset >= 0 && isset($decoded[$offset]) && is_scalar($decoded[$offset]))
                                     ? (string)$decoded[$offset]
                                     : "";
                            }
                            $matches[\'post-pipe\'][$key] = $res;
                        } else {
                            // post-pipe MUST be set on every path. It is paired with
                            // pre-pipe positionally by the preg_replace at the end of
                            // pipeSpecialTags, so a missing entry shifts every later
                            // tag onto the wrong value rather than merely blanking
                            // this one.
                            $matches[\'post-pipe\'][$key] = "";
                        }
                        break;
        ' . self::PipingMarkerEnd . PHP_EOL;
    const PipingSearchTerm = '      $matches[\'post-pipe\'][$key] = "<a href=\"$participant_url\" target=\"_blank\">" . RCView::escape($link_text) . "</a>";
                            }
                        } else {
                            $matches[\'post-pipe\'][$key] = "";
                        }
                        break;
';

    /** Regex matching any whole block this module has ever inserted, markers included. */
    private static function insertedBlockPattern(): string
    {
        return '#[ \t]*' . preg_quote(self::PipingMarkerStart, '#')
             . '.*?' . preg_quote(self::PipingMarkerEnd, '#') . '[ \t]*\R#s';
    }

    /** Strip every inserted block. Returns [contents, blocksRemoved]. */
    private static function stripInsertedBlocks(string $contents): array
    {
        $count = 0;
        $stripped = preg_replace(self::insertedBlockPattern(), '', $contents, -1, $count);
        if ($stripped === null) {
            throw new Exception('Failed to strip inserted code (preg_replace error)');
        }
        return [$stripped, $count];
    }

    // Add comprehensive error handling to addCodeToFile
    function addCodeToFile($filePath, $searchTerm, $insertCode): bool
    {
        try {
            // Validate file exists
            if (!file_exists($filePath)) {
                throw new Exception("Target file not found: $filePath");
            }

            // Validate file is readable
            if (!is_readable($filePath)) {
                throw new Exception("Target file is not readable: $filePath");
            }

            // Validate file is writable
            if (!is_writable($filePath)) {
                throw new Exception("Target file is not writable: $filePath");
            }

            $fullContents = file_get_contents($filePath);
            if ($fullContents === false) {
                throw new Exception("Failed to read file: $filePath");
            }

            // Already correct: exactly our block, and nothing stale alongside it.
            [$withoutBlocks, $existingBlocks] = self::stripInsertedBlocks($fullContents);
            if ($existingBlocks == 1 && strpos($fullContents, $insertCode) !== false) {
                $this->log('Piping code already exists in file', ['file' => $filePath]);
                return true;
            }

            // Otherwise drop whatever is there (a duplicate, or a block written by an
            // earlier module version) so the insert below cannot stack on top of it.
            $file_contents = preg_split('/(?<=\n)/', $withoutBlocks, -1, PREG_SPLIT_NO_EMPTY);

            $found = false;
            $searchArray = explode("\n", $searchTerm);
            $matched = 0;

            foreach ($file_contents as $index => $line) {
                if (str_contains($line, $searchArray[$matched])) {
                    $matched++;
                }

                if ($matched == count($searchArray) - 1) {
                    array_splice($file_contents, $index + 1, 0, $insertCode);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->log('Search term not found in file', [
                    'file' => $filePath,
                    'search_term' => substr($searchTerm, 0, 100) . '...'
                ]);
                return false;
            }

            $result = file_put_contents($filePath, implode('', $file_contents));
            if ($result === false) {
                throw new Exception("Failed to write file: $filePath");
            }

            $this->log('Piping code inserted successfully', ['file' => $filePath]);
            return true;

        } catch (Exception $e) {
            $this->log('Error modifying file', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Add comprehensive error handling to removeCodeFromFile
    function removeCodeFromFile($filePath): bool
    {
        try {
            if (!file_exists($filePath)) {
                $this->log('File not found for code removal', ['file' => $filePath]);
                return false;
            }

            if (!is_readable($filePath)) {
                throw new Exception("Target file is not readable: $filePath");
            }

            if (!is_writable($filePath)) {
                throw new Exception("Target file is not writable: $filePath");
            }

            $file_contents = file_get_contents($filePath);
            if ($file_contents === false) {
                throw new Exception("Failed to read file: $filePath");
            }

            [$modified_contents, $blocksRemoved] = self::stripInsertedBlocks($file_contents);

            if ($blocksRemoved === 0) {
                $this->log('Code not found in file (may already be removed)', ['file' => $filePath]);
                return true;
            }

            $result = file_put_contents($filePath, $modified_contents);

            if ($result === false) {
                throw new Exception("Failed to write file: $filePath");
            }

            $this->log('Piping code removed successfully', [
                'file' => $filePath,
                'blocks_removed' => $blocksRemoved
            ]);
            return true;

        } catch (Exception $e) {
            $this->log('Error removing code from file', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    function redcap_module_system_enable($version): void
    {
        $this->log('Module system enable initiated', ['version' => $version]);
        self::addCodeToFile(self::PipingFilePath, self::PipingSearchTerm, self::PipingCode);
    }

    function redcap_module_system_disable($version): void
    {
        $this->log('Module system disable initiated', ['version' => $version]);
        self::removeCodeFromFile(self::PipingFilePath);
    }

    public function redcap_module_link_check_display($project_id, $link) {
        return $link;
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

    function HideMarkAsMissingIcon($crfVerField): void
    {
        //if the form has mark as missing icons, need to prevent the user being able to
        //clear the value in the _crfver field
        // Escape field name for JavaScript
        $escapedField = htmlspecialchars($crfVerField, ENT_QUOTES, 'UTF-8');

        echo "<script type='text/javascript'>
                let markImage = document.querySelector('img.missingDataButton[fieldname=\"{$escapedField}\"]');
                if(markImage) {
                    markImage.style.display = 'none';
                }
            </script>";
    }

    /**
     * @throws Exception
     */
    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance): void
    {
        //if the form doesn't have a crf version field nothing happens
        //sets the version of the form if empty
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

        $fields = REDCap::getFieldNames($instrument);
        $crfVerFields = array_filter($fields, function($field) use ($crfVerFieldSuffix) {
            return str_ends_with($field, $crfVerFieldSuffix);
        });

        if (count($crfVerFields) == 1) {
            $crfVerField = reset($crfVerFields);

            $this->HideMarkAsMissingIcon($crfVerField);

            $setAsReadonly = $this->getProjectSetting("version-field-auto-set-as-readonly");
            if ($setAsReadonly) {
                $escapedField = htmlspecialchars($crfVerField, ENT_QUOTES, 'UTF-8');
                echo "<script type='text/javascript'>
document.querySelector('#' + '{$escapedField}' + '-tr').classList.add('@READONLY');
</script>
";
            }
        }
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
