<?php

use Tests\TestCase;

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

require __DIR__.'/../vendor/autoload.php';

// Explicit opt-in to run the whole suite on real MySQL instead of SQLite
// (MySQL behaves differently for locking, JSON and constraint errors):
//   docker compose exec -e TESTING_DB=mysql api vendor/bin/phpunit
// It can only ever select the isolated *_testing database.
if (($_SERVER['TESTING_DB'] ?? null) === 'mysql') {
    $_ENV['DB_CONNECTION'] = 'mysql';
    $_ENV['DB_DATABASE'] = TestCase::MYSQL_TEST_DATABASE;
    $_ENV['DB_URL'] = '';
}

foreach ($_ENV as $name => $value) {
    $_SERVER[$name] = $value;
}
