<?php

use NDC\DatabaseBackup\System;

require 'vendor/autoload.php';
/**
 * Inject an FlysystemAdapter for use other file system (eg. AWS S3)
 *
 * $storage = new \League\Flysystem\AwsS3v3\AwsS3Adapter(*params*);
 * $system = System::getInstance($storage);
 */
$system = System::getInstance();
$system->processBackup();