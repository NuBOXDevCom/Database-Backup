<?php

use NDC\DatabaseBackup\System;

require 'vendor/autoload.php';
/**
 * Inject an FlysystemAdapter for use other file system (eg. AWS S3)
 *
 * $storage = new \League\Flysystem\AwsS3v3\AwsS3Adapter(*params*);
 * $storageOptions = [];
 * $system = System::getInstance($storage, $storageOptions);
 */
$system = System::getInstance();
// Available array options listed in https://github.com/ifsnop/mysqldump-php#dump-settings
$system->processBackup();