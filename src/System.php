<?php

declare(strict_types=1);

namespace NDC;

use Dotenv\{Dotenv, Validator};
use Exception;
use Ifsnop\Mysqldump\Mysqldump;
use PDO;
use RuntimeException;
use Swift_Attachment;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;

use function dirname;

use const DIRECTORY_SEPARATOR;

/**
 * Class System
 * @package NDC
 */
class System
{
    /**
     * @var Dotenv
     */
    protected Dotenv $env;
    /**
     * @var array
     */
    protected array $errors = [];
    /**
     * @var bool
     */
    protected bool $isCli;
    /**
     * @var array
     */
    protected array $files = [];

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
     */
    public function loadConfigurationEnvironment(): void
    {
        if (!file_exists(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env')) {
            throw new RuntimeException('Please configure this script with .env-dist to .env file');
        }
        $this->env = Dotenv::createImmutable(dirname(__DIR__));
        $this->env->load();
        if (!$this->isCli && !(bool)$_ENV['ALLOW_EXECUTE_IN_HTTP_BROWSER']) {
            die('Unauthorized to execute this script in your browser !');
        }
        if (PHP_VERSION_ID < 70400) {
            die('PHP VERSION IS NOT SUPPORTED, PLEASE USE THIS SCRIPT WITH TO PHP 7.4 VERSION OR HIGHTER');
        }
    }

    /**
     * @return Validator
     */
    public function checkRequirements(): Validator
    {
        return $this->env->required(
            [
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
                'ALLOW_EXECUTE_IN_HTTP_BROWSER'
            ]
        )->notEmpty();
    }

    /**
     * @return array
     */
    public function getExcludedDatabases(): array
    {
        if (empty(trim($_ENV['DB_EXCLUDE_DATABASES']))) {
            return [];
        }
        return (array)$this->parseAndSanitize($_ENV['DB_EXCLUDE_DATABASES']);
    }

    /**
     * @return array
     */
    public function getDatabases(): array
    {
        $pdo = new PDO(
            'mysql:host=' . $_ENV['DB_HOST'] . ';charset=UTF8', $_ENV['DB_USER'], $_ENV['DB_PASSWORD'],
            [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );
        return $pdo->query('SHOW DATABASES')->fetchAll();
    }

    /**
     * Process to backup databases
     * @return bool
     */
    public function process(): bool
    {
        foreach ($this->getDatabases() as $database) {
            if (!\in_array($database->Database, $this->getExcludedDatabases(), true)) {
                $file_format = $database->Database . '-' . time() . '.sql';
                try {
                    $dumper = new Mysqldump(
                        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $database->Database . ';charset=UTF8',
                        $_ENV['DB_USER'], $_ENV['DB_PASSWORD']
                    );
                    $dumper->start($_ENV['FILES_PATH_TO_SAVE_BACKUP'] . DIRECTORY_SEPARATOR . $file_format);
                    $this->files[] = $_ENV['FILES_PATH_TO_SAVE_BACKUP'] . DIRECTORY_SEPARATOR . $file_format;
                } catch (Exception $e) {
                    $this->errors[] = [
                        'dbname' => $database->Database,
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode()
                    ];
                    return false;
                }
            }
        }
        $this->sendMail();
        return true;
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
        $smtpTransport = new Swift_SmtpTransport($_ENV['MAIL_SMTP_HOST'], (int)$_ENV['MAIL_SMTP_PORT']);
        $smtpTransport->setUsername($_ENV['MAIL_SMTP_USER'])->setPassword($_ENV['MAIL_SMTP_PASSWORD']);
        $mailer = new Swift_Mailer($smtpTransport);
        if (empty($this->errors)) {
            if ((bool)$_ENV['MAIL_SEND_ON_SUCCESS']) {
                $body = "<strong>The backup of the databases has been successful!</strong>";
                if ((bool)$_ENV['MAIL_SEND_BACKUP_FILE']) {
                    $body .= "<br><br>You will find a copy of the backup attached to this email.";
                }
                $message = (new Swift_Message('Backup performed!'))
                    ->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME'])
                    ->setTo($_ENV['MAIL_TO'], $_ENV['MAIL_TO_NAME'])
                    ->setBody($body)
                    ->setCharset('utf-8')
                    ->setContentType('text/html');
                if ((bool)$_ENV['MAIL_SEND_BACKUP_FILE']) {
                    foreach ($this->files as $file) {
                        $attachment = Swift_Attachment::fromPath($file)->setContentType('application/sql');
                        $message->attach($attachment);
                    }
                }
                $mailer->send($message);
            }
        } else {
            if ((bool)$_ENV['MAIL_SEND_ON_ERROR']) {
                $body = "<strong>The backup of databases has encountered errors: </strong><br><br><ul>";
                foreach ($this->errors as $error) {
                    $body .= "<li>
                            <ul>
                                <li>Database: {$error['dbname']}</li>
                                <li>Error code: {$error['error_code']}</li>
                                <li>Error message: {$error['error_message']}</li>
                            </ul>
                           </li>";
                }
                $body .= '</ul>';
                $message = (new Swift_Message('Backup failed!'))
                    ->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME'])
                    ->setTo($_ENV['MAIL_TO'], $_ENV['MAIL_TO_NAME'])
                    ->setBody($body)
                    ->setCharset('utf-8')
                    ->setContentType('text/html');
                $mailer->send($message);
            }
        }
    }
}
