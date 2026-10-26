# Local development setup (Windows)

## PHP
- Install PHP 8.4 (non-thread-safe, x64) from the zip into `C:\php` and add it to PATH. Verify the SHA256 of the download against the value on windows.php.net.
- The zip has no `php.ini`. Copy `php.ini-development` to `php.ini` and set:
  - `extension_dir = "C:\php\ext"`
  - `memory_limit = 512M`, `upload_max_filesize = 8M`, `post_max_size = 10M`, `max_execution_time = 60`, `date.timezone = UTC`
  - `curl.cainfo` and `openssl.cafile` pointing at a current `cacert.pem` (https://curl.se/ca/cacert.pem)
  - extensions: bcmath, curl, exif (after mbstring), fileinfo, gd, intl, mbstring, mysqli, openssl, pdo_mysql, pdo_sqlite, sodium, sqlite3, zip
- Production runs PHP 8.3, and `composer.json` pins the platform to 8.3.0: do not use PHP 8.4-only features.

## After any PHP change
1. `php --ini` shows `C:\php\php.ini`, and `php -m` lists every extension above.
2. `composer check-platform-reqs` reports success.
3. `php artisan optimize:clear`, then `php artisan test` passes.
4. Restart `php artisan serve`.

## Databases
- Local MySQL 8: one development database and one test database whose name ends in `_test` (the test base class refuses to run against anything else).

## Composer on Windows
- `composer.bat` swallows the `^` character, so `composer require "pkg:^3.0"` silently becomes the exact version `3.0`. Use `~` instead (`pkg:~3.0`), or type `^^`. After any `composer require`, run `composer validate` and look for "exact version" warnings.