<?php

namespace NDC\DatabaseBackup;

use const DIRECTORY_SEPARATOR as DS;
use function dirname;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Validator;
use Exception;
use Ifsnop\Mysqldump\Mysqldump;
use JBZoo\Lang\Lang;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use NDC\DatabaseBackup\Exception\NotAllowedException;
use NDC\DatabaseBackup\Exception\UnsupportedPHPVersionException;
use PDO;
use RuntimeException;
use SplFileObject;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;

/**
 * Class System
 * @package NDC\DatabaseBackup
 * @todo Add possibility to switch year/day/hour/minute in CleanerFileSequence
 */
class System
{
    /**
     * @var Dotenv
     */
    private $env;
    /**
     * @var array
     */
    private $errors = [];
    /**
     * @var Lang
     */
    private $l10n;
    /**
     * @var System|null
     */
    private static $_instance;
    /**
     * @var FilesystemInterface
     */
    private $fs;
    /**
     * @var string
     */
    private $localDir;

    /**
     * @param AdapterInterface $adapter
     * @param array $adapterOptions
     * @return System|null
     * @throws FileNotFoundException
     * @throws NotAllowedException
     * @throws UnsupportedPHPVersionException
     * @throws \JBZoo\Lang\Exception
     * @throws \JBZoo\Path\Exception
     */
    public static function getInstance(?AdapterInterface $adapter = null, array $adapterOptions = []): ?System
    {

        if (self::$_instance === null) {
            self::$_instance = new System($adapter, $adapterOptions);
        }
        return self::$_instance;
    }

    /**
     * System constructor.
     * @param AdapterInterface|null $adapter
     * @param array $adapterOptions
     * @throws FileNotFoundException
     * @throws \JBZoo\Lang\Exception
     * @throws \JBZoo\Path\Exception
     * @throws UnsupportedPHPVersionException
     * @throws NotAllowedException
     */
    private function __construct(?AdapterInterface $adapter, array $adapterOptions)
    {
        $this->setLocalDir(dirname(__DIR__) . DS);
        $this->loadConfigurationEnvironment();
        $this->setL10n(new Lang(env('LANGUAGE', 'en')));
        $this->getL10n()->load($this->getLocalDir() . 'i18n', null, 'yml');
        if (PHP_SAPI !== 'cli' && !(bool)env('ALLOW_EXECUTE_IN_WEB_BROWSER', false)) {
            throw new NotAllowedException($this->getL10n()->translate('unauthorized_browser'));
        }
        if ((PHP_MAJOR_VERSION . PHP_MINOR_VERSION) < 72) {
            throw new UnsupportedPHPVersionException($this->getL10n()->translate('unsupported_php_version'));
        }
        if ($adapter === null) {
            $adapter = new Local($this->getLocalDir() . env('DIRECTORY_TO_SAVE_LOCAL_BACKUP', 'MySQLBackups') . DS);
        }
        CliFormatter::output($this->getL10n()->translate('app_started'), CliFormatter::COLOR_BLUE);
        $this->setFs($adapter, $adapterOptions);
        (int)env('FILES_DAYS_HISTORY', 3) > 0 ? $this->removeOldFilesByIntervalDays() : null;
    }

    /**
     * Start System initialization
     * @return void
     * @throws RuntimeException
     */
    private function loadConfigurationEnvironment(): void
    {
        if (!file_exists($this->getLocalDir() . '.env')) {
            throw new InvalidFileException('Please configure this script with .env file');
        }
        $this->setEnv(Dotenv::create($this->getLocalDir(), '.env'));
        $this->getEnv()->overload();
        $this->checkRequirements();
    }

    /**
     * @return Validator
     */
    private function checkRequirements(): Validator
    {
        return $this->getEnv()->required([
            'DB_HOST',
            'DB_USER',
            'DB_PASSWORD'
        ])->notEmpty();
    }

    /**
     * @return array|string
     */
    private function getExcludedDatabases()
    {
        if (empty(trim(env('DB_EXCLUDE_DATABASES', 'information_schema,mysql,performance_schema')))) {
            return [];
        }
        return $this->parseAndSanitize(env('DB_EXCLUDE_DATABASES', 'information_schema,mysql,performance_schema'));
    }

    /**
     * @return array
     */
    private function getDatabases(): array
    {
        $pdo = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';charset=UTF8', env('DB_USER', 'root'),
            env('DB_PASSWORD', 'root'), [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        return $pdo->query('SHOW DATABASES')->fetchAll();
    }

    /**
     * Process to backup databases
     * @param array $settings
     */
    public function processBackup($settings = []): void
    {
        CliFormatter::output($this->l10n->translate('started_backup'), CliFormatter::COLOR_CYAN);
        $ext = 'sql';
        if (array_key_exists('compress', $settings)) {
            switch ($settings['compress']) {
                case 'gzip':
                case Mysqldump::GZIP:
                    $ext = 'sql.gz';
                    break;
                case 'bzip2':
                case Mysqldump::BZIP2:
                    $ext = 'sql.bz2';
                    break;
                case 'none':
                case Mysqldump::NONE:
                    $ext = 'sql';
                    break;
            }
        }
        foreach ($this->getDatabases() as $database) {
            if (!\in_array($database->Database, $this->getExcludedDatabases(), true)) {
                $filename = $database->Database . '-' . date($this->getL10n()->translate('date_format')) . ".$ext";
                try {
                    $dumper = new Mysqldump('mysql:host=' . env('DB_HOST',
                            'localhost') . ';dbname=' . $database->Database . ';charset=UTF8',
                        env('DB_USER', 'root'), env('DB_PASSWORD', ''), $settings, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                        ]);
                    $tempFile = $this->createTempFile();
                    $dumper->start($tempFile->getRealPath());
                    $this->getFs()->writeStream($filename, fopen($tempFile->getRealPath(), 'wb+'));
                    $this->deleteTempFile($tempFile);
                    CliFormatter::output($database->Database . ' ' . $this->getL10n()->translate('backuped_successfully'),
                        CliFormatter::COLOR_GREEN);
                } catch (Exception $e) {
                    CliFormatter::output('!! ERROR::' . $e->getMessage() . ' !!', CliFormatter::COLOR_RED);
                    $this->errors[] = [
                        'dbname' => $database->Database,
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode()
                    ];
                }
            }
        }
        $this->sendMail();
        CliFormatter::output($this->getL10n()->translate('databases_backup_successfull'), CliFormatter::COLOR_PURPLE);
    }

    /**
     * @param string $data
     * @return array|string
     */
    private function parseAndSanitize(string $data)
    {
        $results = explode(',', preg_replace('/\s+/', '', $data));
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
     * Send a mail if error or success backup database(s)
     */
    private function sendMail(): void
    {
        $smtpTransport = new Swift_SmtpTransport(env('MAIL_SMTP_HOST', 'localhost'), env('MAIL_SMTP_PORT', 25));
        $smtpTransport->setUsername(env('MAIL_SMTP_USER', ''))->setPassword(env('MAIL_SMTP_PASSWORD', ''));
        $mailer = new Swift_Mailer($smtpTransport);
        if (empty($this->errors)) {
            if ((bool)env('MAIL_SEND_ON_SUCCESS', false)) {
                $body = "<strong>{$this->getL10n()->translate('mail_db_backup_successfull')}</strong>";
                $message = (new Swift_Message($this->l10n->translate('mail_subject_on_success')))->setFrom(env('MAIL_FROM',
                    'system@my.website'),
                    env('MAIL_FROM_NAME', 'Website Mailer for Database Backup'))
                    ->setTo(env('MAIL_TO'),
                        env('MAIL_TO_NAME', 'Webmaster of my website'))
                    ->setBody($body)
                    ->setCharset('utf-8')
                    ->setContentType('text/html');
                $mailer->send($message);
            }
        } elseif ((bool)env('MAIL_SEND_ON_ERROR', false)) {
            $body = "<strong>{$this->getL10n()->translate('mail_db_backup_failed')}}:</strong><br><br><ul>";
            foreach ($this->errors as $error) {
                $body .= "<li>
                        <ul>
                            <li>{$this->getL10n()->translate('database')}: {$error['dbname']}</li>
                            <li>{$this->getL10n()->translate('error_code')}: {$error['error_code']}</li>
                            <li>{$this->getL10n()->translate('error_message')}: {$error['error_message']}</li>
                        </ul>
                       </li>";
            }
            $body .= '</ul>';
            $message = (new Swift_Message($this->getLocalDir()->translate('mail_subject_on_error')))->setFrom(env('MAIL_FROM',
                'system@my.website'),
                env('MAIL_FROM_NAME', 'Website Mailer for Database Backup'))
                ->setTo(env('MAIL_TO'),
                    env('MAIL_TO_NAME', 'Webmaster of my website'))
                ->setBody($body)
                ->setCharset('utf-8')
                ->setContentType('text/html');
            $mailer->send($message);
        }
    }

    /**
     * @throws FileNotFoundException
     */
    private function removeOldFilesByIntervalDays(): void
    {
        CliFormatter::output($this->l10n->translate('cleaning_files'), CliFormatter::COLOR_CYAN);
        $files = $this->getFs()->listContents();
        foreach ($files as $file) {
            $filetime = $this->getFs()->getTimestamp($file['path']);
            $daysInterval = (int)env('FILES_DAYS_HISTORY', 3);
            if ($filetime < strtotime("-{$daysInterval} days")) {
                $this->getFs()->delete($file['path']);
            }
        }
        CliFormatter::output($this->getL10n()->translate('cleaned_files_success'), CliFormatter::COLOR_GREEN);
    }

    /**
     * @return SplFileObject
     */
    private function createTempFile(): SplFileObject
    {
        $file = tmpfile();
        $name = stream_get_meta_data($file)['uri'];
        return new SplFileObject($name, 'w+');
    }

    /**
     * @param \SplFileInfo $file
     * @return bool
     */
    protected function deleteTempFile(\SplFileInfo $file): bool
    {
        return unlink($file->getRealPath());
    }

    /**
     * @return string
     */
    public function getLocalDir(): string
    {
        return $this->localDir;
    }

    /**
     * @param string $localDir
     */
    public function setLocalDir(string $localDir): void
    {
        $this->localDir = $localDir;
    }

    /**
     * @return Dotenv
     */
    public function getEnv(): Dotenv
    {
        return $this->env;
    }

    /**
     * @param Dotenv $env
     */
    public function setEnv(Dotenv $env): void
    {
        $this->env = $env;
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array $errors
     */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }

    /**
     * @return Lang
     */
    public function getL10n(): Lang
    {
        return $this->l10n;
    }

    /**
     * @param Lang $l10n
     */
    public function setL10n(Lang $l10n): void
    {
        $this->l10n = $l10n;
    }

    /**
     * @return FilesystemInterface
     */
    public function getFs(): FilesystemInterface
    {
        return $this->fs;
    }

    /**
     * @param AdapterInterface|null $adapter
     * @param array $adapterOptions
     */
    public function setFs(?AdapterInterface $adapter, array $adapterOptions): void
    {
        $this->fs = new Filesystem($adapter, $adapterOptions);
    }
}
