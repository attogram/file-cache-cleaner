<?php
/**
 * File Cache Cleaner
 *  - Delete expired Laravel-style `Illuminate\Cache` cache files
 *  - https://github.com/attogram/file-cache-cleaner
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
use function getopt;
use function get_class;
use function gmdate;
use function in_array;
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
    const VERSION = '2.3.0';

    /** @var string Date Format for gmdate() */
    const DATE_FORMAT = 'Y-m-d H:i:s';

    /** @var string Usage */
    const USAGE = "Attogram File Cache Cleaner Usage:\n"
    . " file-cache-cleaner --directory cacheDirectory --clean\n"
    . "  Options:\n"
    . "  -d path  or  --directory path  - set path to Cache Directory\n"
    . "  -c       or  --clean           - clean cache: delete expired files, remove empty subdirectories\n\n";

    /** @var string $cacheDirectory - top-level of Cache Directory to be cleaned */
    private $cacheDirectory = '';

    /** @var array $subDirectoryList - list of all sub-directories in the Cache Directory */
    private $subDirectoryList = [];

    /** @var int $currentTime - current datetime in unix timestamp format */
    private $currentTime = 0;

    /** @var bool $clean - clean cache directory? */
    private $clean = false;

    /** @var array $report - report on cache status */
    private $report = [];

    /**
     * Clean The Cache Directory - delete expired cache files and empty directories
     */
    public function clean()
    {
        $this->verbose(get_class() . ' v' . self::VERSION);

        $this->setOptions();
        $this->examineCacheDirectory();
        $this->examineCacheSubdirectories();
        $this->showReport();        
    }

    private function setOptions()
    {
        $options = getopt('d:c', ['directory:', 'clean']);

        if (!$options) {
            print self::USAGE;
            $this->fatalError('Please specify --directory and --clean');
        }

        // -d or --directory - set Cache Directory
        $cacheDirectory = !empty($options['d']) ? $options['d'] : '';
        $cacheDirectory = !empty($options['directory']) ? $options['directory'] : $cacheDirectory;
        $this->setCacheDirectory($cacheDirectory);

        // -c or --clean - turn on Cleaning mode
        $this->clean = isset($options['c']) ? true : false;
        $this->clean = isset($options['clean']) ? true : $this->clean;
        $this->verbose('Cleaning Mode: ' . ($this->clean ? 'On' : 'Off'));

        $this->currentTime = time();
    }

    /**
     * @param string $directory (default '')
     */
    private function setCacheDirectory(string $directory = '')
    {
        if (!$directory) {
            print self::USAGE;
            $this->fatalError('Missing Cache Directory. Please specify with -d or --directory');
        }
        if (!is_dir($directory)) {
            print self::USAGE;
            $this->fatalError('Cache Directory Not Found: ' . $directory);
        }
        $this->cacheDirectory = realpath($directory);
        $this->verbose('Cache Directory: ' . $this->cacheDirectory);
    }

    private function examineCacheDirectory()
    {
        // Get all objects in cache directory, recursively into all sub-directories
        $filesystemIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($filesystemIterator as $splFileInfo) {
            $this->incrementReport('objects');
            $this->examineObject($splFileInfo);
        }
    }

    /**
     * @param \SplFileInfo $splFileInfo
     */
    private function examineObject($splFileInfo)
    {
        // Find Illuminate\Cache files - filenames are 40 character hexadecimal sha1 hashes
        if ($splFileInfo->isFile()) {
            if (strlen($splFileInfo->getFileName()) == 40) {
                $this->incrementReport('cache_files');
                $this->examineFile($splFileInfo->getPathName());
            
                return;
            }
            $this->incrementReport('non_cache_files');

            return;
        }

        // Save subdirectories to list - cache subdirectory names are always 2 characters long
        if ($splFileInfo->isDir()) {
            if (strlen($splFileInfo->getFileName()) == 2) {
                $this->incrementReport('cache_subdirectories');
                $this->subDirectoryList[] = $splFileInfo->getPathName();
    
                return;
            }
            $this->incrementReport('non_cache_subdirectories');

            return;
        }
    }

    /**
     * @param string $pathname - path and filename
     */
    private function examineFile(string $pathname)
    {
        $timestamp = $this->getFileCacheExpiration($pathname);

        if ($timestamp > $this->currentTime) { // If file cache is Not Expired yet
            $this->incrementReport('unexpired_cache_files');
            return;
        }

        $this->incrementReport('expired_cache_files');

        if (!$this->clean) {
            return;
        }

        if (unlink($pathname)) {
            $this->incrementReport('deleted_expired_cache_files');
    
            return;
        }
        $this->verbose('ERROR: unable to delete ' . $pathname); // @TODO - handle error deleting file
    }

    /**
     * @param string $pathname - path and filename
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
            $this->incrementReport('invalid_timestamp_cache_files');

            return 9999999999; // max time 2286-11-20 17:46:39
        }

        return (int) $timestamp;
    }

    /**
     * Remove Empty Subdirectories
     */
    private function examineCacheSubdirectories()
    {
        // reverse array of subdirectories so we start from last item
        foreach (array_reverse($this->subDirectoryList) as $directory) {
            if ($this->isEmptyDirectory($directory)) {
                $this->incrementReport('empty_cache_subdirectories');
                if ($this->clean) {
                    $this->removeDirectory($directory);
                }
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
            $this->incrementReport('deleted_empty_cache_subdirectories');
            
            return;
        }
        $this->verbose('ERROR deleting ' . $directory); // @TODO - handle error deleting directory
    }

    private function showReport()
    {
        $finalReport = 'Cache Report: ' . $this->cacheDirectory . "\n"
            . "-- Cache Files --\n"
            . $this->getReport('cache_files') . " cache files\n"
            . $this->getReport('unexpired_cache_files') . " unexpired cache files\n"
            . $this->getReport('expired_cache_files') . " expired cache files\n"
            . $this->getReport('deleted_expired_cache_files') . " deleted expired cache files\n"
            . "-- Cache Subdirectories --\n"
            . $this->getReport('cache_subdirectories') . " cache subdirectories\n"
            . $this->getReport('empty_cache_subdirectories') . " empty subdirectories\n"
            . $this->getReport('deleted_empty_cache_subdirectories') . " deleted empty subdirectories\n"
            . "-- Misc --\n"
            . $this->getReport('objects') . " total objects\n"
            . $this->getReport('non_cache_files') . " non-cache files\n"
            . $this->getReport('invalid_timestamp_cache_files') . " invalid timestamp cache files\n"
            . $this->getReport('non_cache_subdirectories') . " non-cache subdirectories\n"
            ;
        $this->verbose($finalReport);
    }

    /**
     * Increment report value
     * @param string $key
     */
    private function incrementReport($key)
    {
        if (empty($this->report[$key])) {
            $this->report[$key] = 1;

            return;
        }

        $this->report[$key]++;
    }

    /**
     * @param string $key
     * @return int
     */
    private function getReport($key)
    {
        if (isset($this->report[$key])) {
            return $this->report[$key];
        }

        return 0;
    }

    /**
     * @param mixed $msg (optional)
     */
    private function verbose($msg = '')
    {
        print gmdate(self::DATE_FORMAT) . ' UTC: ' . print_r($msg, true) . "\n";
    }

    /**
     * @param mixed $msg (optional)
     */
    private function fatalError($msg = '')
    {
        $this->verbose('FATAL ERROR: ' . print_r($msg, true));
        exit;
    }
}
