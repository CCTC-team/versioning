<?php

//DO NOT use a namespace here as gives access then to root e.g. RCView, Records etc

// APP_PATH_DOCROOT = /var/www/html/redcap_v13.8.1/

use CCTC\VersioningModule\VersioningModule;
require_once APP_PATH_DOCROOT . "/Classes/REDCap.php";

$projId = $module->getProjectId();
$moduleName = $_POST['prefix'] ?? '';
$page = $_POST['page'] ?? '';

echo "
<div class='projhdr'>
    <div style='float:left;'>
        <i class='fas fa-sun'></i> Versioning
    </div>
</div>
<br/>
";

if($module->isSuperUser()) {
    echo
"<p>
    The current crf version can be updated via the module project settings in the usual
    way, but can also be set here. Setting here is preferable as some checks are performed first.
</p>";
}

echo "</br>";

$mess = "";
$goodMess = "";

// Handle POST submission
if (isset($_POST['versioning-set-version'])) {
    // Improved input sanitization with min/max range validation
    $newVersion = filter_input(INPUT_POST, 'versioning-set-version', FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => 999
        ]
    ]);

    $currVer = (int)$module->getProjectSetting("current-project-version");

    // Validate given version
    if ($newVersion === false || $newVersion === null) {
        // Escape output
        $rawInput = htmlspecialchars($_POST['versioning-set-version'] ?? '', ENT_QUOTES, 'UTF-8');
        $mess = "Invalid version number. Must be an integer between 1 and 999. Got: " . $rawInput;
    } elseif ($newVersion <= $currVer) {
        $mess = "New version must be greater than current version (" . htmlspecialchars((string)$currVer, ENT_QUOTES, 'UTF-8') . ").";
    } else {
        $module->setProjectSetting("current-project-version", (string)$newVersion);
        // Log version change for audit trail
        $module->logVersionChange($currVer, $newVersion);
        $goodMess = "Updated version number to " . htmlspecialchars((string)$newVersion, ENT_QUOTES, 'UTF-8');
    }
}

$versionNumber = $module->getProjectSetting("current-project-version");
// Escape output
echo "<h5>The current version is " . htmlspecialchars((string)$versionNumber, ENT_QUOTES, 'UTF-8') . "</h5>";


if($module->isSuperUser()) {
    // Escape all values used in HTML output
    $escapedModuleName = htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8');
    $escapedPage = htmlspecialchars($page, ENT_QUOTES, 'UTF-8');
    $escapedProjId = htmlspecialchars((string)$projId, ENT_QUOTES, 'UTF-8');

    echo "
<form id='version-form' name='version-form' method='post' action='' class='mt-4'>
    <input type='hidden' id='prefix' name='prefix' value='{$escapedModuleName}'>
    <input type='hidden' id='page' name='page' value='{$escapedPage}'>
    <input type='hidden' id='pid' name='pid' value='{$escapedProjId}'>
    <label for='versioning-set-version'>Update the version to: </label>
    <input id='versioning-set-version' name='versioning-set-version' type='text' size='3' maxlength='3'
        class='x-form-text x-form-field ui-autocomplete-input' autocomplete='off'>
    <button id='submit-version' class='btn btn-outline-primary btn-xs ml-3' type='submit'>Submit</button>
</form>
";

    if($mess != "") {
        echo "<p class='red'>{$mess}</p>";
    }
    if($goodMess != "") {
        echo "<p class='green'>{$goodMess}</p>";
    }
}
