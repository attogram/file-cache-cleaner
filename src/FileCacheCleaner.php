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
        $this->debug(get_class() . ' v' . self::VERSION);

        $this->setOptions();
        $this->examineCacheDirectory();
        $this->examineCacheSubdirectories();

        $this->debug($this->report);
        
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
            $this->fatalError('Cache Directory Not Found');
        }
        $this->cacheDirectory = realpath($directory);
        $this->debug('Cache Directory: ' . $this->cacheDirectory);
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
        if ($splFileInfo->isFile() && strlen($splFileInfo->getFileName()) == 40) {
            $this->examineFile($splFileInfo->getPathName());
            
            return;
        }
        // Save subdirectories to list
        if ($splFileInfo->isDir()) {
            $this->incrementReport('subdirectories');
            $this->subDirectoryList[] = $splFileInfo->getPathName();

            return;
        }

        $this->incrementReport('non_cache_files');
    }

    /**
     * @param string $pathname - path and filename
     */
    private function examineFile(string $pathname)
    {
        if (!$timestamp = $this->getFileCacheExpiration($pathname))  { // If no valid timestamp found
            $this->incrementReport('invalid_cache_expiration_files');
            return;
        }

        if ($timestamp >= $this->currentTime) { // If file cache is Not Expired yet
            $this->incrementReport('unexpired_cache_files');
            return;
        }

        $this->incrementReport('expired_cache_files');

        if (!$this->clean) {
            return;
        }

        if (unlink($pathname)) {
            $this->incrementReport('deleted_cache_files');
    
            return;
        }
        $this->debug('ERROR: unable to delete ' . $pathname); // @TODO - handle error deleting file
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
            $this->incrementReport('non-cache-files');

            return 9999999999; // max time 2286-11-20 17:46:39
        }

        return (int) $timestamp;
    }

    /**
     * Remove Empty Directories
     */
    private function examineCacheSubdirectories()
    {
        foreach (array_reverse($this->subDirectoryList) as $directory) {
            if ($this->isEmptyDirectory($directory)) {
                $this->incrementReport('empty_directories');
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
            $this->incrementReport('deleted_dirs');
            
            return;
        }
        $this->debug('ERROR deleting ' . $directory); // @TODO - handle error deleting directory
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    private function setReport($key, $value)
    {
        $this->report[$key] = $value;
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
     * @return mixed
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
    private function debug($msg = '')
    {
        print gmdate(self::DATE_FORMAT) . ' UTC: ' . print_r($msg, true) . "\n";
    }

    /**
     * @param mixed $msg (optional)
     */
    private function fatalError($msg = '')
    {
        print gmdate(self::DATE_FORMAT) . ' UTC: FATAL ERROR: ' . print_r($msg, true) . "\n";
        exit;
    }
}
