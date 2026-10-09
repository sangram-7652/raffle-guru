<?php

namespace Tests\Feature\Documentation;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P12.4 — lightweight, no-framework documentation validation. Not a
 * claim that documentation prose is correct (that needs a human/LLM
 * audit, done once for P12.4 — see docs/PRODUCTION_DEPLOYMENT.md etc.)
 * — only that it doesn't reference something that doesn't exist:
 * artisan commands, cross-linked doc files, compose files, and the
 * documented health endpoints. Deliberately simple regex scanning, not
 * a markdown parser — good enough to catch the actual failure mode
 * (a doc drifting out of sync with a renamed/removed command or file)
 * without building documentation tooling this project doesn't need.
 */
class DocumentationValidationTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // base_path() is apps/api inside the repo; docs/ lives two
        // levels up, at the repo root. Only true when the full repo is
        // actually present around apps/api — true in CI (the runner
        // checks out the whole repo) and on the host, but NOT inside
        // the local Docker dev container, which bind-mounts apps/api
        // alone (see docker-compose.yml) with no sibling docs/ visible
        // at all. Skip rather than falsely fail in that case.
        $candidate = dirname(base_path(), 2);

        if (! is_dir("{$candidate}/docs")) {
            $this->markTestSkipped(
                "Repo root ({$candidate}) has no docs/ directory — this container only has apps/api mounted ".
                '(see docker-compose.yml). Run this suite on the host or in CI, where the full repo is checked out.',
            );
        }

        $this->repoRoot = $candidate;
    }

    /**
     * @return list<string>
     */
    private function documentationFiles(): array
    {
        $files = glob("{$this->repoRoot}/docs/*.md") ?: [];
        $files[] = "{$this->repoRoot}/README.md";

        return $files;
    }

    public function test_every_referenced_artisan_command_actually_exists(): void
    {
        $registered = array_keys(Artisan::all());
        $missing = [];

        foreach ($this->documentationFiles() as $file) {
            $contents = (string) file_get_contents($file);

            // "php artisan <command>" — the command token stops at the
            // next whitespace, flag, or closing fence/backtick.
            preg_match_all('/php artisan ([a-zA-Z0-9][a-zA-Z0-9:_-]*)/', $contents, $matches);

            foreach ($matches[1] as $command) {
                // "artisan" itself has no subcommand (e.g. a generic
                // "php artisan" mention) — nothing to validate.
                if ($command === '') {
                    continue;
                }

                if (! in_array($command, $registered, true)) {
                    $missing[] = basename($file).': '.$command;
                }
            }
        }

        $this->assertSame([], $missing, 'Documentation references artisan command(s) that do not exist: '.implode(', ', $missing));
    }

    public function test_every_cross_linked_documentation_file_exists(): void
    {
        $missing = [];

        foreach ($this->documentationFiles() as $file) {
            $dir = dirname($file);
            $contents = (string) file_get_contents($file);

            // Markdown links to a local .md file: [text](Something.md)
            // or [text](docs/Something.md) — never an external http(s) link.
            preg_match_all('/\]\(([^)]+\.md)(?:#[^)]*)?\)/', $contents, $matches);

            foreach ($matches[1] as $link) {
                if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
                    continue;
                }

                $resolved = str_starts_with($link, 'docs/')
                    ? "{$this->repoRoot}/{$link}"
                    : "{$dir}/{$link}";

                if (! is_file($resolved)) {
                    $missing[] = basename($file).' -> '.$link;
                }
            }
        }

        $this->assertSame([], $missing, 'Documentation links to file(s) that do not exist: '.implode(', ', $missing));
    }

    public function test_referenced_docker_compose_files_exist(): void
    {
        $this->assertFileExists("{$this->repoRoot}/docker-compose.yml");
        $this->assertFileExists("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertFileExists("{$this->repoRoot}/infrastructure/nginx/dev.conf");
        $this->assertFileExists("{$this->repoRoot}/infrastructure/nginx/prod.conf");
        $this->assertFileExists("{$this->repoRoot}/infrastructure/docker/php/Dockerfile");
        $this->assertFileExists("{$this->repoRoot}/infrastructure/docker/web/Dockerfile");

        // P12.5 — the optional TLS overlay documented in
        // PRODUCTION_DEPLOYMENT.md §2 Option C.
        $this->assertFileExists("{$this->repoRoot}/docker-compose.tls.yml");
        $this->assertFileExists("{$this->repoRoot}/infrastructure/nginx/prod-tls.conf");
    }

    public function test_documented_health_endpoints_are_actually_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('up', $uris, 'GET /up (documented in PRODUCTION_DEPLOYMENT.md) is not a registered route.');
        $this->assertContains('api/v1/health', $uris, 'GET /api/v1/health is not a registered route.');
        $this->assertContains('api/v1/health/status', $uris, 'GET /api/v1/health/status is not a registered route.');
    }

    public function test_referenced_load_test_script_exists(): void
    {
        $this->assertFileExists("{$this->repoRoot}/load-tests/k6/read_paths.js");
    }
}
