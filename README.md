# File Cache Cleaner

Delete expired cache files.  For projects using
[`Illuminate\Cache`](https://github.com/illuminate/cache) FileStore
*without* a Laravel installation.

PHP 7+, One class, No dependencies, Composer Ready.

[![Maintainability](https://api.codeclimate.com/v1/badges/a98629e339eeef4d56bf/maintainability)](https://codeclimate.com/github/attogram/file-cache-cleaner/maintainability)
[![Latest Stable Version](https://poser.pugx.org/attogram/file-cache-cleaner/v/stable)](https://packagist.org/packages/attogram/file-cache-cleaner)
[![Total Downloads](https://poser.pugx.org/attogram/file-cache-cleaner/downloads)](https://packagist.org/packages/attogram/file-cache-cleaner)
[![License](https://poser.pugx.org/attogram/file-cache-cleaner/license)](https://packagist.org/packages/attogram/file-cache-cleaner)

## Usage

Install:

`composer require attogram/file-cache-cleaner`

Report on cache status only:

`vendor/bin/file-cache-cleaner -d path/to/cache/directory`

Clean cache - delete expired cache files and empty subdirectories:

`vendor/bin/file-cache-cleaner -d path/to/cache/directory --clean`

Command Line Options:

* `-d path`  or  `--directory path`  - set path to Cache Directory
* `-c`       or  `--clean`           - clean cache: delete expired files, remove empty subdirectories

## Project Links

* Git Repo: <https://github.com/attogram/file-cache-cleaner>
* Packagist: <https://packagist.org/packages/attogram/file-cache-cleaner>
* CodeClimate: <https://codeclimate.com/github/attogram/file-cache-cleaner>

## Related Projects

Laravel artisan commands to delete expired cache files:

* <https://github.com/jdavidbakr/laravel-cache-garbage-collector>
* <https://github.com/arifhp86/laravel-clear-expired-cache-file>
* <https://github.com/momokang/laravel-clean-file-cache>
