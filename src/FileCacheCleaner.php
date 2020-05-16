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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_reverse;
use function file_get_contents;
use function getopt;
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
    const VERSION = '2.3.1';

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
        $this->examineCache();
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
        // expiration comparison time
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

    private function examineCache()
    {
        // Get all objects in cache directory, recursively into all sub-directories
        $filesystemIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($filesystemIterator as $splFileInfo) {
            $this->incrementReport('objects');
            $this->findCacheFile($splFileInfo);
            $this->findCacheSubdirectory($splFileInfo);
        }
    }

    /**
     * @param \SplFileInfo $splFileInfo
     */
    private function findCacheFile($splFileInfo)
    {
        if (!$splFileInfo->isFile()) {
            return;
        }
        // Find Illuminate\Cache files 
        // - filenames are 40 character hexadecimal sha1 hashes, no extension
        if (strlen($splFileInfo->getFileName()) == 40) {
            $this->incrementReport('cache_files');
            $this->examineCacheFile($splFileInfo->getPathName());
        
            return;
        }
        $this->incrementReport('non_cache_files');
    }

    /**
     * @param string $pathname - path and filename
     */
    private function examineCacheFile(string $pathname)
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
        $this->incrementReport('unable_to_delete_files');
    }

    /**
     * @param \SplFileInfo $splFileInfo
     */
    private function findCacheSubdirectory($splFileInfo)
    {
        if (!$splFileInfo->isDir()) {
            return;
        }
        // Save subdirectories to list 
        // - cache subdirectory names are always 2 characters long, alphanumeric
        if (strlen($splFileInfo->getFileName()) == 2) {
            $this->incrementReport('cache_subdirectories');
            $this->subDirectoryList[] = $splFileInfo->getPathName();

            return;
        }
        $this->incrementReport('non_cache_subdirectories');
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
        if (!$this->clean) {
            return;
        }
        if (rmdir($directory)) {
            $this->incrementReport('deleted_empty_cache_subdirectories');
            
            return;
        }
        $this->incrementReport('unable_to_delete_directories');
    }

    private function showReport()
    {
        $finalReport = 'Cache Report: ' . $this->cacheDirectory . "\n"
            . "---------- Cache Files ----------\n"
            . $this->getReport('cache_files') . " cache files\n"
            . $this->getReport('unexpired_cache_files') . " unexpired cache files\n"
            . $this->getReport('expired_cache_files') . " expired cache files\n"
            . $this->getReport('deleted_expired_cache_files') . " deleted expired cache files\n"
            . "---------- Cache Subdirectories ----------\n"
            . $this->getReport('cache_subdirectories') . " cache subdirectories\n"
            . $this->getReport('empty_cache_subdirectories') . " empty cache subdirectories\n"
            . $this->getReport('deleted_empty_cache_subdirectories') . " deleted empty cache subdirectories\n"
            . "---------- Misc ----------\n"
            . $this->getReport('objects') . " total objects\n"
            . $this->getReport('non_cache_files') . " non-cache files\n"
            . $this->getReport('invalid_timestamp_cache_files') . " invalid timestamp cache files\n"
            . $this->getReport('non_cache_subdirectories') . " non-cache subdirectories\n"
            . $this->getReport('unable_to_delete_files') . " unable-to-delete files\n"
            . $this->getReport('unable_to_delete_directories') . " unable-to-delete directories\n"
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
     * @return string
     */
    private function getReport($key)
    {
        if (isset($this->report[$key])) {
            return str_pad(number_format($this->report[$key]), 10, ' ', STR_PAD_LEFT);
        }

        return '         0';
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
