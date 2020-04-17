<?php
/**
 * File Cache Cleaner
 *   - Command line example
 *
 * usage:
 *
 *   silent:
 *     php clean.php path/to/cache/directory
 *
 *   debug mode:
 *     php clean.php path/to/cache/directory debug
 */
declare(strict_types = 1);

require_once('src/FileCacheCleaner.php');
//require_once('vendor/autoload.php');

use Attogram\Cache\FileCacheCleaner;

$cacheDirectory = isset($argv[1]) ? $argv[1] : '';
$verbosity = isset($argv[2]) ? $argv[2] : '';

try {
    (new FileCacheCleaner())->clean($cacheDirectory, $verbosity);
} catch (Throwable $error) {
    echo $error->getMessage();
}
