<?php

use NDC\{
	FileManager, System
};

require 'vendor/autoload.php';
$system      = new System();
$fileManager = new FileManager();

$system->checkRequirements();
$system->process();
$fileManager->removeOldFilesByIntervalDays();
