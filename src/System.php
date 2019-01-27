<?php

namespace NDC\DatabaseBackup;

use const DIRECTORY_SEPARATOR;
use function dirname;
use Dotenv\{
    Dotenv, Validator
};
use Exception;
use Ifsnop\Mysqldump\Mysqldump;
use JBZoo\Lang\Lang;
use League\Flysystem\{
    Adapter\Local,
    AdapterInterface,
    Filesystem
};
use PDO;
use \{
    RuntimeException,
    Swift_Attachment,
    Swift_Mailer,
    Swift_Message,
    Swift_SmtpTransport
};

/**
 * Class System
 * @package NDC\DatabaseBackup
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
     * @var bool
     */
    private $isCli;
    /**
     * @var array
     */
    private $files = [];
    /**
     * @var Lang
     */
    private $l10n;

    /**
     * @var System|null
     */
    private static $_instance;
    /**
     * @var AdapterInterface
     */
    private $adapter;

    /**
     * @param AdapterInterface $adapter
     * @param array $adapterOptions
     * @return System|null
     * @throws \League\Flysystem\FileNotFoundException
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
     * @param $adapter
     * @param $adapterOptions
     * @throws \League\Flysystem\FileNotFoundException
     */
    private function __construct(?AdapterInterface $adapter, array $adapterOptions)
    {
        $this->isCli = PHP_SAPI === 'cli';
        try {
            $this->loadConfigurationEnvironment();
        } catch (\JBZoo\Lang\Exception $e) {
            throw new RuntimeException($e);
        } catch (\JBZoo\Path\Exception $e) {
            throw new RuntimeException($e);
        }
        if ($adapter === null) {
            $adapter = new Local(env('FILES_PATH_TO_SAVE_BACKUP'));
        }
        $this->adapter = new Filesystem($adapter, $adapterOptions);
        env('FILES_DAYS_HISTORY', 3) > 0 ?: $this->removeOldFilesByIntervalDays();
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
            throw new RuntimeException('Please configure this script with .env file');
        }
        $this->env = Dotenv::create(dirname(__DIR__), '.env');
        $this->env->overload();
        $this->checkRequirements();
        $this->l10n = new Lang(env('LANGUAGE', 'en'));
        $this->l10n->load(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'i18n', null, 'yml');
        if (!$this->isCli && !(bool)env('ALLOW_EXECUTE_IN_WEB_BROWSER', false)) {
            die($this->l10n->translate('unauthorized_browser'));
        }
        if ((PHP_MAJOR_VERSION . PHP_MINOR_VERSION) < 72) {
            die($this->l10n->translate('unsupported_php_version'));
        }
    }

    /**
     * @return Validator
     */
    private function checkRequirements(): Validator
    {
        return $this->env->required([
            'DB_HOST',
            'DB_USER',
            'DB_PASSWORD',
            'FILES_DAYS_HISTORY',
            'DIRECTORY_TO_SAVE_BACKUP'
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
     */
    public function processBackup(): void
    {
        foreach ($this->getDatabases() as $database) {
            if (!\in_array($database->Database, $this->getExcludedDatabases(), true)) {
                $file_format = $database->Database . '-' . date($this->l10n->translate('date_format')) . '.sql';
                try {
                    $dumper = new Mysqldump('mysql:host=' . env('DB_HOST',
                            'localhost') . ';dbname=' . $database->Database . ';charset=UTF8',
                        env('DB_USER', 'root'), env('DB_PASSWORD', ''));
                    $dumper->start('tmp' . DIRECTORY_SEPARATOR . $file_format);
                    $this->adapter->copy(env('DIRECTORY_TO_SAVE_BACKUP',
                            'MySQLBackups') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $file_format,
                        env('DIRECTORY_TO_SAVE_BACKUP', 'MySQLBackups'));
                    $this->adapter->delete(env('DIRECTORY_TO_SAVE_BACKUP',
                            'MySQLBackups') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $file_format);
                } catch (Exception $e) {
                    $this->errors[] = [
                        'dbname' => $database->Database,
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode()
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
                $body = "<strong>{$this->l10n->translate('mail_db_backup_successfull')}</strong>";
                if ((bool)env('MAIL_SEND_WITH_BACKUP_FILE', false)) {
                    $body .= "<br><br>{$this->l10n->translate('mail_db_backup_file')}";
                }
                $message = (new Swift_Message($this->l10n->translate('mail_subject_on_success')))->setFrom(env('MAIL_FROM',
                    'system@my.website'),
                    env('MAIL_FROM_NAME', 'Website Mailer for Database Backup'))
                    ->setTo(env('MAIL_TO'),
                        env('MAIL_TO_NAME', 'Webmaster of my website'))
                    ->setBody($body)
                    ->setCharset('utf-8')
                    ->setContentType('text/html');
                if ((bool)env('MAIL_SEND_WITH_BACKUP_FILE', false)) {
                    foreach ($this->adapter->listFiles() as $file) {
                        $attachment = Swift_Attachment::fromPath($file)->setContentType('application/sql');
                        $message->attach($attachment);
                    }
                }
                $mailer->send($message);
            }
        } elseif ((bool)env('MAIL_SEND_ON_ERROR', false)) {
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
            $message = (new Swift_Message($this->l10n->translate('mail_subject_on_error')))->setFrom(env('MAIL_FROM',
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
     * @throws \League\Flysystem\FileNotFoundException
     */
    private function removeOldFilesByIntervalDays(): void
    {
        $files = $this->adapter->listContents();
        foreach ($files as $file) {
            $absoluteFile = $file['path'] . DIRECTORY_SEPARATOR . $file['basename'];
            $filetime = $this->adapter->getTimestamp($absoluteFile);
            if ($filetime < strtotime("-{$this->params['days_interval']} days")) {
                $this->adapter->delete($absoluteFile);
            }
        }
    }
}
