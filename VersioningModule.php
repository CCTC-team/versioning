<?php

namespace CCTC\VersioningModule;

use Exception;
use REDCap;
use ExternalModules\AbstractExternalModule;

class VersioningModule extends AbstractExternalModule {

    const PipingFilePath = APP_PATH_DOCROOT . "/Classes/Piping.php";
    const PipingCode =
        '//****** inserted by Versioning module ******
                    case "em-project-setting-value" :
                        $wrapThisItem = true;
                        $module = $matches[\'param1\'][0];
                        $projSettingKey = $matches[\'param2\'][0];

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
                            $matches[\'post-pipe\'][$key] = $res;
                        }
                        break;
        //****** end of insert ******' . PHP_EOL;
    const PipingSearchTerm = '      $matches[\'post-pipe\'][$key] = "<a href=\"$participant_url\" target=\"_blank\">" . RCView::escape($link_text) . "</a>";
                            }
                        } else {
                            $matches[\'post-pipe\'][$key] = "";
                        }
                        break;
';

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

            $file_contents = file($filePath);
            if ($file_contents === false) {
                throw new Exception("Failed to read file: $filePath");
            }

            // Check if code already exists
            $fullContents = implode('', $file_contents);
            if (strpos($fullContents, $insertCode) !== false) {
                $this->log('Piping code already exists in file', ['file' => $filePath]);
                return true;
            }

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
    function removeCodeFromFile($filePath, $removeCode): bool
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

            if (!str_contains($file_contents, $removeCode)) {
                $this->log('Code not found in file (may already be removed)', ['file' => $filePath]);
                return true;
            }

            $modified_contents = str_replace($removeCode, "", $file_contents);
            $result = file_put_contents($filePath, $modified_contents);

            if ($result === false) {
                throw new Exception("Failed to write file: $filePath");
            }

            $this->log('Piping code removed successfully', ['file' => $filePath]);
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
        self::removeCodeFromFile(self::PipingFilePath, self::PipingCode);
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
}
