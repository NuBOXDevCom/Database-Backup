<?php

declare(strict_types=1);
require 'vendor/autoload.php';

use NDC\{FileManager, System};

$system = new System();
$fileManager = new FileManager();

$system->checkRequirements();
$system->process();
$fileManager->removeOldFilesByIntervalDays();
