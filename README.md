# FeedIt.sk
A trainable, fast, mobile-ready RSS reader. Designed for simplicity and speed. Parses even the most impossible and non-standard RSS feeds.

### 2 minute showcase video
https://www.youtube.com/watch?v=x4l0ltXHicg

### Tech used:
* PHP (using Composer)
* MongoDB
* CRON jobs (PHP)

---

**Installation (Windows):**
* download and install [XAMPP](https://www.apachefriends.org/download.html)
* download and install [MongoDB for Windows](https://www.mongodb.com/try/download/community)
* download and install the [MongoDB PHP extension DLL](https://pecl.php.net/package/mongodb/1.10.0/windows)
* download [MongoDB Database Tools](https://www.mongodb.com/try/download/database-tools?tck=docs_databasetools) (for importing the initial collection set)
* download and install [composer](https://getcomposer.org/download/)
* run `composer install` in the root folder of this repository
* extract MongoDB Database Tools to the root of this repository, then run `mongorestore.exe --drop` (be aware that any data in the **_feedit_** database will be  **deleted**)
* update the file **_api/_bootstrap.php** and change all the settings under `USER CONFIGURATION` (that's your MongoDB database name, SMTP mail server login details and site-wide support e-mail)
* the following _can be ran in the browser_, if you choose so - [configure Windows to run the following **6 CRON jobs** (tasks)](https://stackoverflow.com/a/22772792/467164) with PHP (make sure that it's set up to start in the _cron_ folder in `Task Action` options):
  * **_cron/auto-archive.php_** (every 6 hours)
  * **_cron/auto-archive-processed.php_** (every 6 hours)
  * **_cron/auto-remove.php_** (every 6 hours)
  * **_cron/links-trainer.php_** (every minute)
  * **_cron/rss-fetch.php_** (every 5 minutes)
  * **_cron/tiers-training-check.php_** (every 2 hours)
* if you're running FeedIt on any domain other than **_localhost_** or if you're getting **_reCaptcha limit errors_**:
  * [create your own free API key](https://www.google.com/recaptcha/admin/create) for Google's reCaptcha
  * update the **site key** `6LcwoBAcAAAAALFySP8EDETXmKB_PPEdanvgx2I2` in the **_index.php_** file
  * update the **secret key** `6LcwoBAcAAAAAJDSufZ8EQDzJmdTB_7SH7ULLtT6` in the file **_api/captcha.php_**