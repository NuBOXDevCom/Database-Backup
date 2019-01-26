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
    protected $isCli = false;
    /**
     * @var array
     */
    protected $files = [];
    /**
     * @var Lang
     */
    protected $l10n;

    /**
     * @var self|null
     */
    private static $_instance;

    /**
     * @return self|null
     */
    public static function getInstance(): ?self
    {

        if (self::$_instance === null) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    /**
     * System constructor.
     */
    private function __construct()
    {
        $this->isCli = PHP_SAPI === 'cli';
        try {
            $this->loadConfigurationEnvironment();
        } catch (\JBZoo\Lang\Exception $e) {
            throw new RuntimeException($e);
        } catch (\JBZoo\Path\Exception $e) {
            throw new RuntimeException($e);
        }
        FileManager::getInstance();
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
        $this->env = Dotenv::create(dirname(__DIR__));
        $this->env->overload();
        $this->checkRequirements();
        $this->l10n = new Lang(env('LANGUAGE', 'en'));
        $this->l10n->load(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'i18n', null, 'yml');
        if (!$this->isCli && !(bool)getenv('ALLOW_EXECUTE_IN_WEB_BROWSER')) {
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
            'ALLOW_EXECUTE_IN_WEB_BROWSER'
        ])->notEmpty();
    }

    /**
     * @return array|string
     */
    private function getExcludedDatabases()
    {
        if (empty(trim(getenv('DB_EXCLUDE_DATABASES')))) {
            return [];
        }
        return $this->parseAndSanitize(getenv('DB_EXCLUDE_DATABASES'));
    }

    /**
     * @return array
     */
    private function getDatabases(): array
    {
        $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';charset=UTF8', getenv('DB_USER'), getenv('DB_PASSWORD'), [
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
                    $dumper->start(env('FILES_PATH_TO_SAVE_BACKUP', './Backups') . DIRECTORY_SEPARATOR . $file_format);
                    $this->files[] = env('FILES_PATH_TO_SAVE_BACKUP', './Backups') . DIRECTORY_SEPARATOR . $file_format;
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
                if ((bool)getenv('MAIL_SEND_WITH_BACKUP_FILE')) {
                    foreach ($this->files as $file) {
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
}
