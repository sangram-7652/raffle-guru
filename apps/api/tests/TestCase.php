<?php

namespace Tests;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only MySQL database tests may ever touch — never the development
     * `raffleguru` database. See useIsolatedMysqlDatabase(). One-time
     * local setup (the app user can't create databases):
     *
     *   docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e
     *     "CREATE DATABASE raffleguru_testing; GRANT ALL ON raffleguru_testing.* TO raffleguru;"'
     *   docker compose exec -e DB_DATABASE=raffleguru_testing api php artisan migrate
     */
    public const MYSQL_TEST_DATABASE = 'raffleguru_testing';

    /**
     * Fail closed BEFORE any test trait (RefreshDatabase, ...) runs:
     * createApplication() is called by setUp() ahead of setUpTraits().
     * Allowed: SQLite in-memory, or a MySQL database whose name ends in
     * `_testing`. Anything else aborts the whole test instead of
     * migrate:fresh-ing whatever database the environment points at.
     * See tests/bootstrap.php for why phpunit.xml alone is not enough.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $isolated = ($connection === 'sqlite' && $database === ':memory:')
            || ($connection === 'mysql' && str_ends_with($database, '_testing'));

        if (! $app->environment('testing') || ! $isolated) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: env=%s, database=%s/%s. Tests may only use SQLite :memory: or a MySQL *_testing database.',
                $app->environment(), $connection, $database,
            ));
        }

        return $app;
    }

    /**
     * An admin session must carry the admin's current session_version, the
     * way a real sign-in stamps it (RecordAdminLogin). Stamping it here keeps
     * every admin test on the same session path the application uses, and a
     * revocation test can still change the version afterwards.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if ($guard === 'admin') {
            // Written and saved to the session handler, not just the in-memory
            // store: the next request reloads the stored session, which would
            // otherwise overwrite the stamp with one left by an earlier request.
            $session = $this->app['session.store'];

            if (! $session->isStarted()) {
                $session->start();
            }

            $session->put(Admin::SESSION_VERSION_KEY, (int) DB::table('admins')
                ->where('id', $user->getAuthIdentifier())
                ->value('session_version'));
            $session->save();
        }

        return $this;
    }

    /**
     * For the pcntl concurrency tests, which need genuinely concurrent
     * real-MySQL connections: switch the default connection to the
     * isolated MySQL test database (see MYSQL_TEST_DATABASE).
     */
    protected function useIsolatedMysqlDatabase(): void
    {
        Config::set('database.connections.mysql.database', self::MYSQL_TEST_DATABASE);
        Config::set('database.default', 'mysql');
        DB::purge('mysql');

        // A previous `TESTING_DB=mysql` run can leave this database with no
        // tables: DatabaseMigrations tests (e.g. DrawHandoffTest) roll every
        // migration back in teardown. Bring the schema up to date — plain
        // `migrate`, idempotent, never `fresh` — so these tests don't depend
        // on which suite ran last.
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful only starts a session
        // for requests it recognizes as coming from a configured stateful
        // domain (checked via the Referer/Origin header — see
        // config/sanctum.php). Real browser requests always carry one; set
        // it here so tests exercise the same cookie-session code path
        // instead of silently falling back to a sessionless request.
        $this->withHeader('Referer', config('app.frontend_url'));

        // RateLimiter (used by throttle:* middleware — see
        // AppServiceProvider) stores its counters in the cache, which
        // RefreshDatabase does NOT reset between tests. Without this, a
        // test that intentionally exhausts a limiter (RateLimitingTest)
        // leaks 429s into unrelated tests that happen to run afterwards.
        Cache::flush();

        // CSRF token validation is Laravel's own well-tested library code,
        // not application logic — what these tests verify is our auth
        // behavior (registration, login, sessions, ...), which is orthogonal
        // to whether a request happens to carry a matching X-XSRF-TOKEN.
        // This does NOT affect the real application: CSRF stays fully
        // enforced outside the test suite (see config/sanctum.php).
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
