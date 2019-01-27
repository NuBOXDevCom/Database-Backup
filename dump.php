<?php

use NDC\DatabaseBackup\System;

require 'vendor/autoload.php';
//$storage = new \League\Flysystem\AwsS3v3\AwsS3Adapter();
$system = System::getInstance();
//$system->processBackup();