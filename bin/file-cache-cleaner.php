<?php
/**
 * File Cache Cleaner
 *   - Command line example
 *
 * usage:
 *
 *   silent:
 *     php file-cache-cleaner.php path/to/cache/directory
 *
 *   debug mode:
 *     php file-cache-cleaner.php path/to/cache/directory debug
 */
declare(strict_types = 1);

require_once(__DIR__ . '/../src/FileCacheCleaner.php');
//require_once(__DIR__ . '/../vendor/autoload.php');

use Attogram\Cache\FileCacheCleaner;

$cacheDirectory = isset($argv[1]) ? $argv[1] : '';
$verbosity = isset($argv[2]) ? $argv[2] : '';

try {
    (new FileCacheCleaner())->clean($cacheDirectory, $verbosity);
} catch (Throwable $error) {
    echo get_class($error) . ': ' . $error->getMessage();
}
