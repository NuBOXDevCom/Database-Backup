<?php

namespace NDC\DatabaseBackup;

use const DIRECTORY_SEPARATOR;
use function {
	dirname, getenv
};
use Dotenv\{
	Dotenv, Validator
};
use Exception;
use Ifsnop\Mysqldump\Mysqldump;
use JBZoo\Lang\Lang;
use PDO;
use RuntimeException;
use Swift_Attachment;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;

/**
 * Class System
 * @package NDC\DatabaseBackup
 */
class System
{
	/**
	 * @var Dotenv
	 */
	protected $env;
	/**
	 * @var array
	 */
	protected $errors = [];
	/**
	 * @var bool
	 */
	protected $isCli;
	/**
	 * @var array
	 */
	protected $files = [];
	/**
	 * @var Lang
	 */
	protected $l10n;
	
	/**
	 * System constructor.
	 */
	public function __construct()
	{
		$this->isCli = PHP_SAPI === 'cli';
		$this->loadConfigurationEnvironment();
	}
	
	/**
	 * Start System initialization
	 * @return void
	 * @throws RuntimeException
	 * @throws \JBZoo\Lang\Exception
	 * @throws \JBZoo\Path\Exception
	 */
	public function loadConfigurationEnvironment(): void
	{
		if (!file_exists(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env')) {
			throw new RuntimeException('Please configure this script with .env-dist to .env file');
		}
		$this->env = new Dotenv(dirname(__DIR__));
		$this->env->overload();
		$this->l10n = new Lang(getenv('LANGUAGE'));
		$this->l10n->load(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'i18n', null, 'yml');
		if (!$this->isCli && !(bool)getenv('ALLOW_EXECUTE_IN_HTTP_BROWSER')) {
			die($this->l10n->translate('unauthorized_browser'));
		}
		if (PHP_VERSION_ID < 70000) {
			die($this->l10n->translate('unsupported_php_version'));
		}
	}
	
	/**
	 * @return Validator
	 */
	public function checkRequirements(): Validator
	{
		return $this->env->required([
			'DB_HOST',
			'DB_USER',
			'DB_PASSWORD',
			'MAIL_FROM',
			'MAIL_FROM_NAME',
			'MAIL_TO',
			'MAIL_TO_NAME',
			'MAIL_SEND_ON_ERROR',
			'MAIL_SEND_ON_SUCCESS',
			'MAIL_SMTP_HOST',
			'MAIL_SMTP_PORT',
			'FILES_DAYS_HISTORY',
			'FILES_PATH_TO_SAVE_BACKUP',
			'LANGUAGE',
			'ALLOW_EXECUTE_IN_HTTP_BROWSER'
		])->notEmpty();
	}
	
	/**
	 * @return array|string
	 */
	public function getExcludedDatabases()
	{
		if (empty(trim(getenv('DB_EXCLUDE_DATABASES')))) {
			return [];
		}
		return $this->parseAndSanitize(getenv('DB_EXCLUDE_DATABASES'));
	}
	
	/**
	 * @return array
	 */
	public function getDatabases(): array
	{
		$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';charset=UTF8', getenv('DB_USER'), getenv('DB_PASSWORD'), [
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION
		]);
		return $pdo->query('SHOW DATABASES')->fetchAll();
	}
	
	/**
	 * Process to backup databases
	 */
	public function process(): void
	{
		foreach ($this->getDatabases() as $database) {
			if (!\in_array($database->Database, $this->getExcludedDatabases(), true)) {
				$file_format = $database->Database . '-' . time() . '.sql';
				try {
					$dumper = new Mysqldump('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $database->Database . ';charset=UTF8',
						getenv('DB_USER'), getenv('DB_PASSWORD'));
					$dumper->start(getenv('FILES_PATH_TO_SAVE_BACKUP') . DIRECTORY_SEPARATOR . $file_format);
					$this->files[] = getenv('FILES_PATH_TO_SAVE_BACKUP') . DIRECTORY_SEPARATOR . $file_format;
				} catch (Exception $e) {
					$this->errors[] = [
						'dbname'        => $database->Database,
						'error_message' => $e->getMessage(),
						'error_code'    => $e->getCode()
					];
				}
			}
		}
		$this->sendMail();
	}
	
	/**
	 * @param string $data
	 * @return array|string
	 */
	private function parseAndSanitize(string $data)
	{
		$results = explode(',', $data);
		if (\count($results) > 1) {
			foreach ($results as $k => $v) {
				$results[$k] = trim($v);
				if (empty($v)) {
					unset($results[$k]);
				}
			}
			return $results;
		}
		return trim($results[0]);
	}
	
	/**
	 * Send a mail if error or success backup database
	 */
	private function sendMail(): void
	{
		$smtpTransport = new Swift_SmtpTransport(getenv('MAIL_SMTP_HOST'), getenv('MAIL_SMTP_PORT'));
		$smtpTransport->setUsername(getenv('MAIL_SMTP_USER'))->setPassword(getenv('MAIL_SMTP_PASSWORD'));
		$mailer = new Swift_Mailer($smtpTransport);
		if (empty($this->errors)) {
			if ((bool)getenv('MAIL_SEND_ON_SUCCESS')) {
				$body = "<strong>{$this->l10n->translate('mail_db_backup_successfull')}</strong>";
				if ((bool)getenv('MAIL_SEND_BACKUP_FILE')) {
					$body .= "<br><br>{$this->l10n->translate('mail_db_backup_file')}";
				}
				$message = (new Swift_Message($this->l10n->translate('mail_subject_on_success')))->setFrom(getenv('MAIL_FROM'),
					getenv('MAIL_FROM_NAME'))
																								 ->setTo(getenv('MAIL_TO'),
																									 getenv('MAIL_TO_NAME'))
																								 ->setBody($body)
																								 ->setCharset('utf-8')
																								 ->setContentType('text/html');
				if ((bool)getenv('MAIL_SEND_BACKUP_FILE')) {
					foreach ($this->files as $file) {
						$attachment = Swift_Attachment::fromPath($file)->setContentType('application/sql');
						$message->attach($attachment);
					}
				}
				$mailer->send($message);
			}
		} else {
			if ((bool)getenv('MAIL_SEND_ON_ERROR')) {
				$body = "<strong>{$this->l10n->translate('mail_db_backup_failed')}}:</strong><br><br><ul>";
				foreach ($this->errors as $error) {
					$body .= "<li>
                            <ul>
                                <li>{$this->l10n->translate('database')}: {$error['dbname']}</li>
                                <li>{$this->l10n->translate('error_code')}: {$error['error_code']}</li>
                                <li>{$this->l10n->translate('error_message')}: {$error['error_message']}</li>
                            </ul>
                           </li>";
				}
				$body .= '</ul>';
				$message = (new Swift_Message($this->l10n->translate('mail_subject_on_error')))->setFrom(getenv('MAIL_FROM'),
					getenv('MAIL_FROM_NAME'))
																							   ->setTo(getenv('MAIL_TO'),
																								   getenv('MAIL_TO_NAME'))
																							   ->setBody($body)
																							   ->setCharset('utf-8')
																							   ->setContentType('text/html');
				$mailer->send($message);
			}
		}
	}
}
