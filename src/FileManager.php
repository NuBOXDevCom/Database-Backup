<?php

namespace NDC;

use function getenv;

/**
 * Class FileManager
 * @package NDC
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
	 * FileManager constructor.
	 */
	public function __construct()
	{
		(new System())->loadConfigurationEnvironment();
		$this->params = [
			'files_path'    => getenv('FILES_PATH_TO_SAVE_BACKUP'),
			'days_interval' => getenv('FILES_DAYS_HISTORY')
		];
		$this->_getFiles();
	}
	
	/**
	 * @return void
	 */
	private function _getFiles(): void
	{
		$files       = scandir($this->params['files_path'], SCANDIR_SORT_ASCENDING);
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
	public function removeOldFilesByIntervalDays(): void
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
