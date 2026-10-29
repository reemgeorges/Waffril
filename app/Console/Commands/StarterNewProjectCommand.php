<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Phase 9 (2026-10-26) — `php artisan starter:new {path}`: copies this
 * starter kit into a brand-new local project directory, without ever
 * touching GitHub, a package registry, or anything outside the one
 * target path this command is explicitly given. Local-first by design —
 * GitHub is not mandatory for any of it.
 *
 * Safety boundaries (CLAUDE.md: "do not modify/overwrite existing
 * projects automatically", "no destructive database operation... without
 * explicit authorization"):
 *  - REFUSES outright if the target path already exists and is not
 *    empty — no --force override for that case at all. The only way to
 *    use an existing path is for it to be empty (or not exist yet, in
 *    which case it's created).
 *  - Never copies runtime/secret state: .env (a fresh one is generated
 *    from .env.example with a freshly generated APP_KEY — never copied,
 *    never reused), .git history (opt-in only, via --with-git-history),
 *    vendor/, node_modules/, the dev sqlite database file, logs, cached
 *    views/sessions/bootstrap files, and (Phase 11F) real uploaded files
 *    under storage/app/public|private — a make:module --images deployment's
 *    actual user content, never copied into the new project.
 *  - Never runs `composer install`, a migration, or a seeder against the
 *    new project — those are real side effects (installing dependencies,
 *    touching a database) this command only ever prints as a next step
 *    for the developer to run themselves.
 *  - (Phase 11F) Writes a `.starter-kit.json` manifest into the new
 *    project recording the source starter kit's path and git commit —
 *    the one fact any real future upgrade/diff workflow needs first.
 */
class StarterNewProjectCommand extends Command
{
    protected $signature = 'starter:new
        {path : Absolute or relative path to the new project directory — must not already exist, or must be empty}
        {--name= : APP_NAME for the new project\'s .env (default: the target directory\'s basename)}
        {--with-git-history : Also copy the .git directory (full history). Omitted by default — a fresh project normally starts its own history.}';

    protected $description = 'Copy this starter kit into a new local project directory (never overwrites an existing non-empty one)';

    /**
     * Directories never traversed at all — either genuinely irrelevant
     * to a fresh copy (vendor/node_modules, reinstalled by the
     * developer's own `composer install`/`npm install`) or explicitly
     * opt-in (.git).
     */
    protected function excludedDirectories(): array
    {
        $always = ['vendor', 'node_modules', 'public/build', 'public/hot', 'public/storage'];

        return $this->option('with-git-history') ? $always : array_merge($always, ['.git']);
    }

    /**
     * Individual files skipped WITHIN an otherwise-copied directory —
     * the directory itself (and any of its own .gitignore placeholder
     * files) still needs to exist in the new project for Laravel to run,
     * only its runtime CONTENTS are excluded.
     *
     * Phase 11F (2026-10-26): two real bugs found and fixed here:
     *  1. PHP's fnmatch() `*` matches a leading dot by default (no
     *     FNM_PERIOD flag is passed) — so a broad pattern like
     *     "storage/framework/sessions/*" was ALSO excluding that
     *     directory's own ".gitignore" placeholder, contradicting this
     *     method's own stated intent. Confirmed by actually inspecting
     *     this real checkout's storage/ tree: storage/framework/cache/data/.gitignore
     *     and storage/framework/sessions/.gitignore both exist and would
     *     both have been silently dropped. Fixed with an explicit,
     *     always-first exception for any path whose basename is exactly
     *     ".gitignore".
     *  2. "Do not copy... existing uploads" was not actually enforced —
     *     storage/app/public/* and storage/app/private/* (the 'public'
     *     and 'local' disk roots make:module's own --images feature,
     *     Phase 6, stores real uploaded files under) were never
     *     excluded at all. A deployment with real user-uploaded images
     *     would have had every one of them copied into the new project.
     */
    protected function isExcludedFile(string $relativePath): bool
    {
        if (basename($relativePath) === '.gitignore') {
            return false;
        }

        $patterns = [
            '.env',
            '.env.backup',
            '.env.production',
            '.phpunit.result.cache',
            'database/database.sqlite',
            'storage/logs/*.log',
            'storage/framework/cache/data/*',
            'storage/framework/sessions/*',
            'storage/framework/views/*',
            'storage/framework/testing/*',
            'storage/app/public/*',
            'storage/app/private/*',
            'bootstrap/cache/*.php',
        ];

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    public function handle(Filesystem $files): int
    {
        $target = $this->argument('path');
        // Laravel's Filesystem has no isAbsolutePath() helper — a plain
        // check covers both POSIX ("/...") and Windows ("C:\..." or
        // "C:/...") absolute forms, which is all this needs.
        $isAbsolute = (bool) preg_match('#^(/|[A-Za-z]:[\\\\/])#', $target);
        $targetAbsolute = $isAbsolute ? $target : base_path('../'.$target);
        $targetAbsolute = rtrim(str_replace('\\', '/', $targetAbsolute), '/');

        if ($files->isDirectory($targetAbsolute) && count($files->allFiles($targetAbsolute)) + count($files->directories($targetAbsolute)) > 0) {
            $this->error("Refusing: \"{$targetAbsolute}\" already exists and is not empty. starter:new never writes into an existing project — pick a new or genuinely empty directory.");

            return 1;
        }

        $source = base_path();
        $excludedDirs = $this->excludedDirectories();

        // exclude() (not notPath()) is what actually stops Finder from
        // DESCENDING into these directories at all — vendor/ alone can
        // hold tens of thousands of files, and there is no reason to
        // enumerate them just to filter them back out afterward.
        $finder = (new Finder)->files()->ignoreDotFiles(false)->ignoreVCS(false)->exclude($excludedDirs)->in($source);

        $copied = 0;
        $skipped = 0;

        foreach ($finder as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if ($this->isExcludedFile($relativePath)) {
                $skipped++;

                continue;
            }

            $destinationPath = "{$targetAbsolute}/{$relativePath}";
            $files->ensureDirectoryExists(dirname($destinationPath));
            $files->copy($file->getPathname(), $destinationPath);
            $copied++;
        }

        $this->info("Copied {$copied} file(s) to {$targetAbsolute} ({$skipped} runtime/secret file(s) intentionally excluded).");

        $this->writeFreshEnv($files, $targetAbsolute);
        $this->writeStarterKitManifest($files, $source, $targetAbsolute);

        $this->printNextSteps($targetAbsolute);

        return 0;
    }

    /**
     * Phase 11F (2026-10-26): "provide a realistic, maintainable update
     * strategy for shared starter tooling." A full package-registry/
     * auto-merge system is a substantially larger undertaking than this
     * command's own scope (see docs/starter-roadmap.md's Phase 9 notes —
     * deliberately left as a limitation, not half-built here either).
     * The one thing any REAL upgrade strategy needs first is knowing
     * exactly which commit of the SOURCE starter kit a project started
     * from — without that, there is nothing to diff against. This
     * writes a small, durable manifest recording it, so a developer can
     * later `git diff <recorded commit>..HEAD -- app/Console/Commands`
     * (or similar) against the ORIGINAL starter kit's own history to see
     * exactly what's changed since, and cherry-pick or hand-port
     * whatever they want — a realistic, git-native workflow that needs
     * no new tooling or package registry, only this one recorded fact.
     */
    protected function writeStarterKitManifest(Filesystem $files, string $source, string $targetAbsolute): void
    {
        $commit = null;
        exec('git -C '.escapeshellarg($source).' rev-parse HEAD 2>'.(DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $output, $exitCode);
        if ($exitCode === 0 && ! empty($output[0])) {
            $commit = trim($output[0]);
        }

        $manifest = [
            'starter_kit_source_path' => $source,
            'starter_kit_source_commit' => $commit,
            'generated_at' => now()->toIso8601String(),
            'upgrade_hint' => $commit
                ? "To see what's changed in the starter kit since this project was created, from a checkout of the source repo run: git log {$commit}..HEAD -- app/Console/Commands app/Models/BaseModel.php app/Services/Basic — then hand-port or cherry-pick whatever's relevant."
                : 'Source commit unknown (source was not a git repository, or git was unavailable) — no automated diff is possible; compare files manually against the source path above.',
        ];

        $files->put("{$targetAbsolute}/.starter-kit.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('Wrote .starter-kit.json — records exactly which starter-kit commit this project started from, for future upgrades.');
    }

    /**
     * Generates a brand-new .env from .env.example — NEVER copies the
     * source project's real .env, and NEVER reuses its APP_KEY. A fresh,
     * cryptographically random key is generated the same way
     * `php artisan key:generate` itself does (32 random bytes,
     * base64-encoded), directly here rather than by shelling out to the
     * new project's own artisan — which isn't runnable yet anyway until
     * the developer's own `composer install` has populated vendor/.
     */
    protected function writeFreshEnv(Filesystem $files, string $targetAbsolute): void
    {
        $examplePath = "{$targetAbsolute}/.env.example";
        if (! $files->exists($examplePath)) {
            $this->warn('No .env.example found in the copy — skipping .env generation. Create one yourself before running the new project.');

            return;
        }

        $env = $files->get($examplePath);

        $appKey = 'base64:'.base64_encode(random_bytes(32));
        $env = preg_match('/^APP_KEY=.*$/m', $env)
            ? preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$appKey}", $env)
            : $env."\nAPP_KEY={$appKey}\n";

        $appName = $this->option('name') ?: basename($targetAbsolute);
        $env = preg_match('/^APP_NAME=.*$/m', $env)
            ? preg_replace('/^APP_NAME=.*$/m', 'APP_NAME="'.$appName.'"', $env)
            : $env."\nAPP_NAME=\"{$appName}\"\n";

        $files->put("{$targetAbsolute}/.env", $env);
        $this->info('Generated a fresh .env with a new APP_KEY (never copied from this project).');
    }

    protected function printNextSteps(string $targetAbsolute): void
    {
        $this->line('');
        $this->line('Next steps (nothing below was run automatically):');
        $this->line("  1. cd \"{$targetAbsolute}\"");
        $this->line('  2. composer install');
        $this->line('  3. Edit .env: set DB_* to your own database (SQLite, MySQL, whichever you use).');
        $this->line('  4. php artisan migrate   (review the migrations first if this matters to you — none have been run yet)');
        $this->line('  5. php artisan db:seed   (optional — every seeder here is empty or environment-gated; see AdminSeeder.php)');
        $this->line('');
        $this->line('Windows/Laragon: if the target is already under C:\\laragon\\www\\, Laragon auto-detects it on next refresh — open it at http://<folder-name>.test once composer install/migrate are done. If the target is elsewhere, move/symlink it under C:\\laragon\\www\\ first, or configure a virtual host yourself.');
    }
}
