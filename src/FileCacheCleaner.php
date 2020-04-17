<?php
/**
 * File Cache Cleaner
 *   - delete expired Laravel Illuminate\Cache files
 *
 * usage:
 *   $cleaner = new Attogram\Cache\FileCacheCleaner();
 *   $cacheDirectory = '/path/to/cache/directory';
 *   $verbosity = 1; // 0 = off, 1 = on
 *   $cleaner->clean($cacheDirectory, $verbosity);
 *
 * @TODO - after file deletions, also delete empty cache subdirectories
 */
declare(strict_types = 1);

namespace Attogram\Cache;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function get_class;
use function gmdate;
use function is_dir;
use function preg_match;
use function print_r;
use function realpath;
use function strlen;
use function time;

class FileCacheCleaner
{
    const VERSION = '1.0.1';

    /** @var string $directory - top-level of Cache Directory to be cleaned */
    private $directory = '';

    /** @var int $now - current time in unix timestamp format */
    private $now = 0;

    /** @var int $verbose - verbosity level, empty = off, not-empty = on*/
    private $verbose = '';

    /**
     * @param string $directory (default '')
     * @param mixed $verbosity - verbosity level (default '' off) empty = off, not-empty = on
     */
    public function clean(string $directory = '', $verbosity = '')
    {
        $this->verbose = $verbosity;
        
        $this->now = time(); // datetime now in unix timestamp format
        $this->debug(get_class() . ': ' . gmdate('Y-m-d H:i:s', $this->now) . ' UTC');

        $this->setDirectory($directory);

        $directoryObjects = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($directoryObjects as $name => $splFileInfo) {
            // Laravel Illuminate\Cache filenames are 40 character hexadecimal sha1 hashes
            if ($splFileInfo->isFile() && strlen($splFileInfo->getFileName()) == 40) {
                $this->examineFile($splFileInfo->getPathName());
            }
        }
    }

    /**
     * @param string $directory (default '')
     * @throws Exception
     */
    private function setDirectory(string $directory = '')
    {
        if (!$directory) {
            throw new Exception('Missing Directory');
        }

        if (!is_dir($directory)) {
            throw new Exception('Directory Not Found');
        }

        $this->directory = realpath($directory);
        $this->debug('directory: ' . $this->directory);
    }

    /**
     * @param string $pathname - full path and filename
     */
    private function examineFile(string $pathname)
    {
        if (!$timestamp = $this->getFileCacheExpiration($pathname)) {
            return;
        }



        // If file cache is Not Expired yet
        if ($timestamp >= $this->now) {
            $this->debug('cache active : ' . gmdate('Y-m-d H:i:s', $timestamp) . " UTC - $pathname");
            return;
        }

        $this->debug('cache expired: ' . gmdate('Y-m-d H:i:s', $timestamp) . " UTC - $pathname");

        if (unlink($pathname)) {
            return;
        }

        $this->debug('ERROR: unable to delete ' . $pathname);
    }

    /**
     * @param string $pathname - full path and filename
     * @return int - expiration time as unix timestamp, or 0 on error
     */
    private function getFileCacheExpiration(string $pathname): int
    {
        // Get expiration time from an Illuminate\Cache File
        // as a unix timestamp, from the first 10 characters in the file
        $timestamp = file_get_contents($pathname, false, null, 0, 10);

        if (!$timestamp
            || strlen($timestamp) != 10 // if timestamp is Not 10 characters long
            || !preg_match('/^([0-9]+)$/', $timestamp) // if timestamp is Not numbers-only
        ) {
            $this->debug('Not cache: ' . $pathname);
            return 0;
        }

        return (int) $timestamp;
    }

    /**
     * @param mixed $msg
     */
    private function debug($msg = '')
    {
        if ($this->verbose) {
            echo print_r($msg, true) . "\n";
        }
    }
}
