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
	 * @var self
	 */
	private static $_instance;

	/**
	 * @return FileManager|null
	 */
	public static function getInstance(): ?self
	{

		if (self::$_instance === null) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	/**
	 * FileManager constructor.
	 */
	public function __construct()
	{
		System::getInstance();
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
		$files = scandir($this->params['files_path'], SCANDIR_SORT_ASCENDING);
		$this->files = array_filter($files, function($k) {
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
		$time_before = time() - $this->params['days_interval'] * 86400;
		foreach ($this->files as $file) {
			$f = explode('-', $file);
			$f = str_replace('.sql', '', $f);
			if ($f[1] < $time_before) {
				@unlink($this->params['files_path'] . '/' . $file);
			}
		}
	}
}
