<?php

namespace NDC\DatabaseBackup;

/**
 * Class FileManager
 * @package NDC\DatabaseBackup
 */
class FileManager
{
    /**
     * @var array
     */
    private $params;
    /**
     * @var array
     */
    private $files = [];
    /**
     * @var FileManager
     */
    private static $_instance;

    /**
     * @return FileManager|null
     */
    public static function getInstance(): ?FileManager
    {

        if (self::$_instance === null) {
            self::$_instance = new FileManager();
        }
        return self::$_instance;
    }

    /**
     * FileManager constructor.
     */
    private function __construct()
    {
        $this->params = [
            'files_path' => env('FILES_PATH_TO_SAVE_BACKUP', './Backups'),
            'days_interval' => env('FILES_DAYS_HISTORY', 3)
        ];
        $this->_getFiles();
        env('FILES_DAYS_HISTORY', 3) > 0 ?: $this->removeOldFilesByIntervalDays();
    }

    /**
     * @return void
     */
    private function _getFiles(): void
    {
        if (!is_dir($this->params['files_path']) && !mkdir($this->params['files_path']) && !is_dir($this->params['files_path'])) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->params['files_path']));
        }
        $files = scandir($this->params['files_path'], SCANDIR_SORT_ASCENDING);
        $this->files = array_filter($files, function ($k) {
            $return = [];
            if ($k !== '..' && $k !== '.') {
                $return[] = $k;
            }
            return $return;
        });
    }

    /**
     * @return void
     */
    private function removeOldFilesByIntervalDays(): void
    {
        foreach ($this->files as $file) {
            $absoluteFile = $this->params['files_path'] . DIRECTORY_SEPARATOR . $file;
            $filetime = filemtime($absoluteFile);
            if ($filetime < strtotime("-{$this->params['days_interval']} days")) {
                unlink($absoluteFile);
            }
        }
    }
}
