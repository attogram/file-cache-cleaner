<?php
/**
 * File Cache Cleaner
 * Delete expired Laravel-style `Illuminate\Cache` cache files
 *
 */
declare(strict_types = 1);

namespace Attogram\Cache;

use DirectoryIterator;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_reverse;
use function file_get_contents;
use function get_class;
use function gmdate;
use function is_dir;
use function preg_match;
use function print_r;
use function realpath;
use function rmdir;
use function strlen;
use function time;
use function unlink;

class FileCacheCleaner
{
    /** @var string Code Version */
    const VERSION = '2.1.1';

    /** @var string Date Format for gmdate() */
    const DATE_FORMAT = 'Y-m-d H:i:s';

    /** @var string $cacheDirectory - top-level of Cache Directory to be cleaned */
    private $cacheDirectory = '';

    /** @var array $subDirectoryList - list of all sub-directories in the Cache Directory */
    private $subDirectoryList = [];

    /** @var int $currentTime - current datetime in unix timestamp format */
    private $currentTime = 0;

    /** @var mixed $verbose - verbosity level, empty = off, not-empty = on*/
    private $verbose = '';

    /** @var array $counts - status counts */
    private $count = [];

    /**
     * @param string $directory (default '')
     * @param mixed $verbosity - verbosity level (default '' off) empty = off, not-empty = on
     */
    public function clean(string $directory = '', $verbosity = '')
    {
        $this->verbose = $verbosity;
        $this->currentTime = time();
        $this->debug(get_class() . ' v' . self::VERSION);
        $this->debug('Check Time: ' . gmdate(self::DATE_FORMAT, $this->currentTime));
    
        $this->setCacheDirectory($directory);
        $this->debug('Cache Directory: ' . $this->cacheDirectory);

        $this->count['objects'] = $this->count['files'] = $this->count['directories']
            = $this->count['deleted_files'] = $this->count['deleted_dirs'] = 0;

        $this->examineCacheDirectory();
        $this->debug($this->count['objects'] . ' objects found');
        $this->debug($this->count['files'] . ' files found');
        $this->debug($this->count['directories'] . ' sub-directories found');
        $this->debug($this->count['deleted_files'] . ' deleted files');
    
        $this->examineDirectories();
        $this->debug($this->count['deleted_dirs'] . ' deleted empty directories');
    }

    /**
     * @param string $directory (default '')
     * @throws InvalidArgumentException
     */
    private function setCacheDirectory(string $directory = '')
    {
        if (!$directory) {
            throw new InvalidArgumentException('Missing Cache Directory');
        }
        if (!is_dir($directory)) {
            throw new InvalidArgumentException('Cache Directory Not Found');
        }
        $this->cacheDirectory = realpath($directory);
    }

    private function examineCacheDirectory()
    {
        // Get all objects in cache directory, recursively into all sub-directories
        $filesystemIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($filesystemIterator as $splFileInfo) {
            $this->count['objects']++;
            // Find Illuminate\Cache files - filenames are 40 character hexadecimal sha1 hashes
            if ($splFileInfo->isFile() && strlen($splFileInfo->getFileName()) == 40) {
                $this->count['files']++;
                $this->examineFile($splFileInfo->getPathName());
                continue;
            }
            // Save directories to list
            if ($splFileInfo->isDir()) {
                $this->count['directories']++;
                $this->subDirectoryList[] = $splFileInfo->getPathName();
            }
        }
    }

    /**
     * @param string $pathname - full path and filename
     */
    private function examineFile(string $pathname)
    {
        if (!($timestamp = $this->getFileCacheExpiration($pathname)) // If no valid timestamp found
            || ($timestamp >= $this->currentTime) // If file cache is Not Expired yet
        ) {
            return;
        }
        if (unlink($pathname)) {
            $this->count['deleted_files']++;
            $this->debug('DELETED - ' . gmdate(self::DATE_FORMAT, $timestamp) . " UTC - $pathname");
    
            return;
        }
        $this->debug('ERROR: unable to delete ' . $pathname); // @TODO - handle error deleting file
    }

    /**
     * @param string $pathname - full path and filename
     * @return int - expiration time as unix timestamp, or 9999999999 on error
     */
    private function getFileCacheExpiration(string $pathname): int
    {
        // Get expiration time from an Illuminate\Cache File
        // as a unix timestamp, from the first 10 characters in the file
        $timestamp = file_get_contents($pathname, false, null, 0, 10);
        if (!$timestamp // if timestamp not found
            || strlen($timestamp) != 10 // if timestamp is Not 10 characters long
            || !preg_match('/^([0-9]+)$/', $timestamp) // if timestamp is Not numbers-only
        ) {
            $this->debug('Not cache: ' . $pathname);
            return 9999999999; // max time 2286-11-20 17:46:39
        }

        return (int) $timestamp;
    }

    /**
     * Remove Empty Directories
     */
    private function examineDirectories()
    {
        foreach (array_reverse($this->subDirectoryList) as $directory) {
            if ($this->isEmptyDirectory($directory)) {
                $this->removeDirectory($directory);
            }
        }
    }

    /**
     * Is the Directory Empty?
     * @param string $directory
     * @return bool
     */
    private function isEmptyDirectory($directory)
    {
        foreach (new DirectoryIterator($directory) as $thing) {
            if (!$thing->isDot() && ($thing->isFile() || $thing->isDir())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove the Directory
     * @param string $directory
     */
    private function removeDirectory($directory)
    {
        if (rmdir($directory)) {
            $this->count['deleted_dirs']++;
            $this->debug('DELETED EMPTY DIR: ' . $directory);
            
            return;
        }
        $this->debug('ERROR deleting ' . $directory); // @TODO - handle error deleting directory
    }

    /**
     * @param mixed $msg
     */
    private function debug($msg = '')
    {
        if ($this->verbose) {
            print gmdate(self::DATE_FORMAT) . ' UTC: ' . print_r($msg, true) . "\n";
        }
    }
}
