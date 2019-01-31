<?php

namespace NDC\DatabaseBackup;


/**
 * Class CliFormatter
 * @package NDC\DatabaseBackup
 */
class CliFormatter
{
    public const COLOR_BLACK = 30;
    public const COLOR_BLUE = 34;
    public const COLOR_GREEN = 32;
    public const COLOR_CYAN = 36;
    public const COLOR_RED = 31;
    public const COLOR_PURPLE = 35;
    public const COLOR_BROWN = 33;
    public const COLOR_LIGHT_GRAY = 37;

    /**
     * @param string $message
     * @param int|null $color
     */
    public static function output(string $message, int $color = null): void
    {
        if (PHP_SAPI === 'cli') {
            echo ' > ';
            if ($color !== null) {
                echo "\033[{$color}m";
            }
            echo $message;
            if ($color !== null) {
                echo "\033[0m";
            }
            echo PHP_EOL;
        }
    }
}