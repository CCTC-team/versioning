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
                        //TODO: check when going from 9 to 10 as the value may be compared by string not int!!!
                        //NOTE: the above is fine - php automatically compares the values numerically
                        $wrapThisItem = true;
                        $module = $matches[\'param1\'][0];
                        $projSettingKey = $matches[\'param2\'][0];

                        $sql = "select
    b.value as settingValue
from
    redcap_external_modules a,
    redcap_external_module_settings b
where
    a.external_module_id = b.external_module_id
    and a.directory_prefix = \'$module\'
    and b.project_id = $project_id
    and b.`key` = \'$projSettingKey\'";
                        $q = db_query($sql);
                        if (db_num_rows($q)) {
                            $res = db_result($q, 0);
                            $matches[\'post-pipe\'][$key] = $res;
                        }

                        break;//****** end of insert ******' . PHP_EOL;
    const PipingSearchTerm = '      $matches[\'post-pipe\'][$key] = "<a href=\"$participant_url\" target=\"_blank\">" . RCView::escape($link_text) . "</a>";
                            }
                        } else {
                            $matches[\'post-pipe\'][$key] = "";
                        }
                        break;
';

    //adds the $insertCode into the $filePath after the $searchTerm
    function addCodeToFile($filePath, $searchTerm, $insertCode) : void
    {
        $file_contents = file($filePath);
        $found = false;

        $searchArray = explode("\n", $searchTerm);
        $matched = 0;

        foreach ($file_contents as $index => $line) {
            //increment $matched so checks next line on next iteration
            if (str_contains($line, $searchArray[$matched])) {
                $matched++;
            }

            //if all the lines were found then mark as found
            if($matched == count($searchArray) - 1) {
                array_splice($file_contents, $index + 1, 0, $insertCode);
                $found = true;
                break;
            }
        }

        //write it back if was found
        if ($found) {
            file_put_contents($filePath, implode('', $file_contents));
        }
    }

    //removes the $removeCode from the $filePath
    function removeCodeFromFile($filePath, $removeCode) : void
    {
        $file_contents = file_get_contents($filePath);
        if(str_contains($file_contents, $removeCode)) {
            $modified_contents = str_replace($removeCode, "", $file_contents);
            file_put_contents($filePath, $modified_contents);
        }
    }

    function redcap_module_system_enable($version): void
    {
        //adds the code to the files as needed
        self::addCodeToFile(self::PipingFilePath, self::PipingSearchTerm, self::PipingCode);
    }


    function redcap_module_system_disable($version): void
    {
        //removes the previously added code
        self::removeCodeFromFile(self::PipingFilePath, self::PipingCode);
    }

    /*


     */

    public function redcap_module_link_check_display($project_id, $link) {
        return $link;
    }

    public function validateSettings($settings): ?string
    {
        if (array_key_exists("current-project-version", $settings)) {
            if(!is_numeric($settings['current-project-version'])) {
                return "The current project version should be a number";
            }
        }

        if (array_key_exists("versioning-field-suffix", $settings)) {
            if(empty($settings['versioning-field-suffix'])) {
                return "Versioning Field Suffix should not be empty";
            }
        }
        return null;
    }

    function HideMarkAsMissingIcon($crfVerField): void
    {
        //if the form has mark as missing icons, need to prevent the user being able to
        //clear the value in the _crfver field

        echo "<script type='text/javascript'>
    let markImage = document.querySelector('img.missingDataButton[fieldname=\'$crfVerField\']');
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

        //if the mandatory fields are not set, then do nothing
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

        //only apply versioning if one and only one field has the appropriate suffix
        if(count($crfVerFields) == 1) {
            $crfVerField = reset($crfVerFields);

            //always hide the icon allowing users to mark it as missing
            $this->HideMarkAsMissingIcon($crfVerField);

            $setAsReadonly = $this->getProjectSetting("version-field-auto-set-as-readonly");
            if($setAsReadonly) {
                echo "<script type='text/javascript'>
document.querySelector('#' + '$crfVerField' + '-tr').classList.add('@READONLY');
</script>
";
            }
        }
    }
}