## 1.3.0 (2014-01-28)

* Fix #8: Performing a DB select when the DB is set to default '0' is not necessary and breaks Twemproxy
* Fix #13: Added PIDFILE writing when child COUNT > 1
* Fix #14: Add bin/resque to composer
* Fix #17: Catch redis connection issue
* Fix #24: Use getmypid to specify a persistent connection unique identifier
* Add redis authentication support


## 1.2.7 (2013-10-15)

* Include the file given by APP_INCLUDE as soon as possible in bin/resque

## 1.2.6 (2013-06-20)

* Update composer dependencies

## 1.2.5 (2013-05-22)

* Drop support of igbinary serializer in failed job trace
* Use ISO-8601 formatted date in log
* Drop .php extension in resque bin filename

> If you're starting your workers manually, use `php bin/resque` instead of `php bin/resque.php`


## 1.2.4 (2013-04-141) ##

* Fix #3 : Logging now honour verbose level

## 1.2.3 (2012-01-31) ##

* Fix fatal error when updating job status

## 1.2.2 (2012-01-30) ##

* Add missing autoloader path

## 1.2.1 (2012-01-30) ##

* Moved top-level resque.php to bin folder
* Detect composer autoloader up to 3 directory level, and fail gracefully if not found
* Change some functions scope to allow inheritance


## 1.0.15 (2012-01-23) ##

* Record job processing time

## 1.0.14 (2012-10-23) ##

* Add method to get failed jobs details
* Merge v1.2 from parent

## 1.0.13 (2012-10-17) ##

* Pause and unpause events go into their own log category

## 1.0.12 (2012-10-14) ##

* Check that `$logger` is not null before using

## 1.0.11 (2012-10-01) ##

* Update Composer.json

## 1.0.10 (2012-09-27) ##

* Update Composer.json


## 1.0.9 (2012-09-20) ##

* Delegate all the MonologHandler creation to MonologInit. (requires a composer update).
* Fix stop event that was not logged

## 1.0.8 (2012-09-19) ##

* In start log, add a new fields for recording queues names

## 1.0.7 (2012-09-10) ##

* Fix tests

## 1.0.6 (2012-09-10) ##

* Merge latest commits from php-resque


## 1.0.5 (2012-08-29) ##

* Add custom redis database and namespace support

## 1.0.4 (2012-08-29) ##

* Job creation will be delegated to Resque_Job_Creator class if found
* Use persistent connection to Redis

## 1.0.3 (2012-08-26) ##

* Fix unknown self reference

## 1.0.2 (2012-08-22) ##

* Don't use persistent connection to redis, because of segfault bug

## 1.0.1 (2012-08-21) ##

* Output to STDOUT if no log Handler is defined

## 1.0.0 (2012-08-21) ##

* Initial release