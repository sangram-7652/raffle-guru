<?php

/*
 * PHPUnit applies phpunit.xml's `<env force="true">` values to $_ENV and
 * putenv() only — but Laravel's env repository reads $_SERVER FIRST, and
 * in the CLI $_SERVER carries the process's real environment. Inside the
 * `api` container that environment points at the development MySQL
 * database and Redis (APP_ENV=local, DB_CONNECTION=mysql, ...), so
 * without this the forced testing values silently lose and
 * RefreshDatabase runs migrate:fresh against development data.
 *
 * Mirror every $_ENV value (which PHPUnit has already overwritten with the
 * forced ones) into $_SERVER before Laravel boots. Tests\TestCase then
 * refuses to boot at all if the result still isn't an isolated database.
 */
foreach ($_ENV as $name => $value) {
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
