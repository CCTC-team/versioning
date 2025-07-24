<?php

//DO NOT use a namespace here as gives access then to root e.g. RCView, Records etc

// APP_PATH_DOCROOT = /var/www/html/redcap_v13.8.1/

use CCTC\VersioningModule\VersioningModule;
require_once APP_PATH_DOCROOT . "/Classes/REDCap.php";

$projId = $module->getProjectId();
$moduleName = $_POST['prefix'];
$page = $_POST['page'];

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

if(!isset($_POST['versioning-set-version'])){
    $versionNumber = $module->getProjectSetting("current-project-version");
} else {
    $versionNumber = filter_input(INPUT_POST, 'versioning-set-version', FILTER_VALIDATE_INT);
    $currVer = $module->getProjectSetting("current-project-version");

    //validate given version
    if($versionNumber !== null && $versionNumber !== false){
        if($versionNumber <= $currVer){
            $mess = "Expecting the new version [$versionNumber] to be at least one greater than current version [$currVer]";
        } else {
            $module->setProjectSetting("current-project-version", (string)$versionNumber);
            $goodMess = "Updated version number to $versionNumber";
        }
    } else {
        $nextVer = $currVer+1;
        $mess = "Expecting an integer between {$nextVer} and 999 but got -> " . $_POST['versioning-set-version'];
    }
}

$versionNumber = $module->getProjectSetting("current-project-version");
echo "<h5>The current version is $versionNumber</h5>";


if($module->isSuperUser()) {
    echo "
<form id='version-form' name='version-form' method='post' action='' class='mt-4'>
    <input type='hidden' id='prefix' name='prefix' value='$moduleName'>
    <input type='hidden' id='page' name='page' value='$page'>
    <input type='hidden' id='pid' name='pid' value='$projId'>
    <label for='versioning-set-version'>Update the version to: </label>
    <input id='versioning-set-version' name='versioning-set-version' type='text' size='3' maxlength='3'        
        class='x-form-text x-form-field ui-autocomplete-input' autocomplete='off'>
    <button id='submit-version' class='btn btn-outline-primary btn-xs ml-3' type='submit'>Submit</button>
</form>    
";

    if($mess != "") {
        echo "<p class='red'>$mess</p>";
    }
    if($goodMess != "") {
        echo "<p class='green'>$goodMess</p>";
    }
}