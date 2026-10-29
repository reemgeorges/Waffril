<?php

namespace App\Console\Commands\CodeBasics;

use App\Console\Commands\Concerns\BuildsOpenApiFragments;
use App\Http\Controllers\FatherAuthController;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Phase 2B (2026-10-25), upgraded Phase 3B (2026-10-25) — Configurable
 * Auth Module generator.
 *
 * Generates a new Sanctum-backed account type (Agent, Customer, ...) that
 * plugs into the same foundation Phase 2A built for Admin/User: a guard +
 * provider (auto-wired at boot by App\Providers\AuthModulesServiceProvider
 * from config/auth_modules.php, no hand-editing config/auth.php needed), a
 * model extending BaseAuthModel (SoftDeletes + is_active + HasRoles for
 * free), an auth service, controller, resource, facade, policy, and a
 * routes file that is DISABLED BY DEFAULT — exactly like
 * App\Console\Commands\CodeAfterMigrate\ScaffoldRouteCommand's established
 * "secure by default, explicit opt-in" convention.
 *
 * Phase 3B: with --otp, the generated module now gets REAL working
 * register/resend/verify/reset-password/phone-change/email-change flows —
 * not a 501 placeholder. This is possible because App\Services\Basic\BaseAuthService
 * itself became genuinely generic and OTP-engine-backed in Phase 3A/3B
 * (every one of those methods is gated by config('auth_modules.modules.{guard}.otp'),
 * which this generator now populates with real per-action flags instead of
 * a flat purpose list). The generated service only still needs to
 * implement login() and a small verifyOtp() dispatcher itself.
 *
 * Still NOT generated: a working SMS/WhatsApp channel (none exist anywhere
 * in this project — see SmsOtpChannel/WhatsAppOtpChannel's own doc
 * comments) and 'social' as a --login identifier is still accepted as a
 * config declaration only; this generator refuses to emit a social-login
 * branch in generated code, because the existing hand-written
 * UserAuthService::login() social branch — before its Phase 3B fix —
 * authenticated on a bare, unverified social_id with no password and no
 * OAuth token verification at all. Nothing this generator emits should
 * repeat that mistake.
 */
class MakeAuthModuleCommand extends Command
{
    protected $signature = 'make:auth-module
        {name : Module name, e.g. Agent or Customer}
        {--guard= : Guard name (default: snake_case of name, e.g. "agent")}
        {--login=email : Comma-separated login identifiers: email,phone (social is declared-only, never generated)}
        {--registration : Declare self-registration intent in config. Requires --otp.}
        {--otp : Enable the OTP engine for this module (adds an otp_delivery_method column) and generate REAL register/resend/verify/reset-password flows (phone/email-change too, if applicable) — gated by --otp-actions, not 501 placeholders.}
        {--otp-actions= : Comma-separated subset of register,resend_register,reset_password,phone_change,email_change to enable. Default when --otp is passed: register,resend_register,reset_password (+ phone_change if --login includes phone).}
        {--with-seeder : Ensure DatabaseSeeder calls AuthModulesPermissionSeeder (idempotent; seeds a "manage-{module}" role for every configured module, not just this one)}
        {--with-tests : Also generate tests/Feature/{Name}AuthModuleTest.php}
        {--with-openapi : Also generate docs/openapi/modules/<module>.yaml for this auth module, aggregated across modules by openapi:build (Phase 8/11E).}
        {--enable-routes : Generate a LIVE routes file (guard-isolated) instead of the default fully-disabled scaffold}
        {--dry-run : Print what would be generated without writing anything}
        {--force : Overwrite files that already exist (use with care)}';

    protected $description = 'Generate a configurable, secure-by-default Sanctum auth module with a real OTP-backed register/reset/phone/email-change flow (model, migration, service, controller, resource, facade, policy, routes)';

    use BuildsOpenApiFragments;

    protected const KNOWN_OTP_ACTIONS = ['register', 'resend_register', 'reset_password', 'phone_change', 'email_change'];

    protected Filesystem $files;

    protected array $plannedFiles = [];

    public function handle(Filesystem $files): int
    {
        $this->files = $files;

        $name = Str::studly($this->argument('name'));
        $guard = $this->option('guard') ?: Str::snake($name);
        $table = Str::snake(Str::pluralStudly($name));
        $provider = $table;
        $key = Str::snake($name);
        $routeFile = Str::kebab($name);

        $identifiers = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('login')))));
        $identifiers = array_values(array_intersect($identifiers, ['email', 'phone', 'social'])) ?: ['email'];

        if (in_array('social', $identifiers, true)) {
            $this->warn(
                "'social' was requested as a login identifier for {$name}, but this generator never emits a ".
                'social-login branch (see the class doc comment for why). It is recorded in config/auth_modules.php '.
                'as a declared intent only — you must implement it yourself, with real token verification, if needed.'
            );
        }

        $otp = (bool) $this->option('otp');
        $registration = (bool) $this->option('registration');

        if ($registration && ! $otp) {
            $this->error(
                '--registration requires --otp. Self-registration without OTP verification is not supported by '.
                'this generator — pass --otp (it generates a real, working flow now, not a placeholder) or drop '.
                '--registration.'
            );

            return 1;
        }

        $otpActions = [];

        if ($otp) {
            $requested = $this->option('otp-actions')
                ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('otp-actions')))))
                : array_values(array_filter([
                    'register', 'resend_register', 'reset_password',
                    in_array('phone', $identifiers, true) ? 'phone_change' : null,
                ]));

            $unknown = array_diff($requested, self::KNOWN_OTP_ACTIONS);
            if ($unknown) {
                $this->error('Unknown --otp-actions value(s): '.implode(', ', $unknown).'. Known actions: '.implode(', ', self::KNOWN_OTP_ACTIONS).'.');

                return 1;
            }

            if (in_array('phone_change', $requested, true) && ! in_array('phone', $identifiers, true)) {
                $this->error("'phone_change' requires --login to include 'phone' (this module has no phone columns otherwise).");

                return 1;
            }

            $otpActions = $requested;
        }

        if (config("auth_modules.modules.{$guard}") || $this->configHasModuleMarker($guard)) {
            $this->info("Auth module '{$guard}' already exists in config/auth_modules.php — skipping (nothing written). Use --force to regenerate individual files.");

            return 0;
        }

        $context = compact('name', 'guard', 'table', 'provider', 'key', 'routeFile', 'identifiers', 'otp', 'otpActions', 'registration');

        $plan = $this->buildPlan($context);

        if ($this->option('dry-run')) {
            $this->info("Dry run — would generate the following for auth module '{$name}' (guard: {$guard}):");
            foreach ($plan as $path => $description) {
                $this->line("  - {$path}  ({$description})");
            }

            return 0;
        }

        foreach ($plan as $path => $description) {
            $this->writeIfAbsent($path, fn () => $this->contentFor($path, $context));
        }

        $this->injectAuthModuleConfig($context);

        if ($this->option('with-seeder')) {
            $this->ensureSeederIsRegistered();
        }

        $this->info("Auth module '{$name}' generated. Nothing was migrated, seeded, or made live automatically.");
        $this->line('Next steps:');
        $this->line('  1. Review the generated migration, then run it yourself against a dev/test database.');
        $this->line("  2. Review routes/api/v1/{$routeFile}.php — it is disabled by default unless --enable-routes was passed.");
        if ($this->option('with-seeder')) {
            $this->line('  3. Run `php artisan db:seed --class=Database\\Seeders\\AuthModulesPermissionSeeder` yourself when ready.');
        }

        return 0;
    }

    /**
     * Map of absolute path => human-readable description, used for both
     * --dry-run output and the actual write loop.
     */
    protected function buildPlan(array $c): array
    {
        $plan = [
            database_path('migrations/'.now()->format('Y_m_d_His')."_create_{$c['table']}_table.php") => 'migration',
            app_path("Models/{$c['name']}.php") => 'model',
            database_path("factories/{$c['name']}Factory.php") => 'factory',
            app_path("Services/Functional/{$c['name']}AuthService.php") => 'auth service',
            app_path("Http/Controllers/Auth/{$c['name']}AuthController.php") => 'controller',
            app_path("Http/Resources/Model/{$c['name']}Resource.php") => 'resource',
            app_path("Facades/Services/Auth/{$c['name']}AuthFacade.php") => 'facade',
            app_path("Policies/{$c['name']}Policy.php") => 'policy (self-management only; auto-discovered)',
            base_path("routes/api/v1/{$c['routeFile']}.php") => $this->option('enable-routes') ? 'routes (LIVE: login/logout, guard-isolated)' : 'routes (disabled scaffold)',
        ];

        if ($this->option('with-tests')) {
            $plan[base_path("tests/Feature/{$c['name']}AuthModuleTest.php")] = 'tests';
        }

        if ($this->option('with-openapi')) {
            $plan[base_path("docs/openapi/modules/{$c['routeFile']}.yaml")] = 'OpenAPI fragment (complete, accurate, per-auth-module)';
        }

        return $plan;
    }

    protected function writeIfAbsent(string $path, callable $content): void
    {
        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->info('Skipping (already exists): '.$path);

            return;
        }

        $dir = dirname($path);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($path, $content());
        $this->info('Generated: '.$path);
    }

    protected function configHasModuleMarker(string $guard): bool
    {
        $path = config_path('auth_modules.php');
        if (! $this->files->exists($path)) {
            return false;
        }

        return Str::contains($this->files->get($path), "// AUTH-MODULE: {$guard}");
    }

    /**
     * Idempotent append into config/auth_modules.php's 'modules' array,
     * guarded by a dedicated `// AUTH-MODULE: {guard}` marker comment (more
     * robust than matching on exact array formatting).
     */
    protected function injectAuthModuleConfig(array $c): void
    {
        $path = config_path('auth_modules.php');
        $content = $this->files->get($path);

        $identifiersPhp = implode(', ', array_map(fn ($i) => "'{$i}'", $c['identifiers']));
        $actionsPhp = implode(', ', array_map(fn ($a) => "'{$a}'", $c['otpActions']));

        $entry = <<<PHP

        // AUTH-MODULE: {$c['guard']}
        '{$c['guard']}' => [
            'label' => '{$c['name']}',
            'model' => \\App\\Models\\{$c['name']}::class,
            'guard' => '{$c['guard']}',
            'provider' => '{$c['provider']}',
            'table' => '{$c['table']}',
            'login_identifiers' => [{$identifiersPhp}],
            'registration' => {$this->boolLiteral($c['registration'])},
            'verification_required' => {$this->boolLiteral($c['registration'])},
            'soft_deletes' => true,
            'active_column' => 'is_active',
            'otp' => [
                'enabled' => {$this->boolLiteral($c['otp'])},
                'actions' => [{$actionsPhp}],
            ],
            'require_login_challenge' => false,
            'managed_by_generator' => true,
        ],

PHP;

        $marker = "    'modules' => [";
        if (! Str::contains($content, $marker)) {
            $this->error("Could not find 'modules' => [ in config/auth_modules.php — new module config was NOT written. Add it manually:".PHP_EOL.$entry);

            return;
        }

        $content = Str::replaceFirst($marker, $marker.$entry, $content);
        $this->files->put($path, $content);
        $this->info("Registered '{$c['guard']}' in config/auth_modules.php.");
    }

    protected function boolLiteral(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    protected function ensureSeederIsRegistered(): void
    {
        $path = database_path('seeders/DatabaseSeeder.php');
        $content = $this->files->get($path);

        if (Str::contains($content, 'AuthModulesPermissionSeeder')) {
            $this->info('DatabaseSeeder already calls AuthModulesPermissionSeeder.');

            return;
        }

        $content = Str::replaceFirst(
            'public function run(): void
    {',
            'public function run(): void
    {
        $this->call(\\Database\\Seeders\\AuthModulesPermissionSeeder::class);',
            $content
        );

        $this->files->put($path, $content);
        $this->info('Registered AuthModulesPermissionSeeder in DatabaseSeeder (not executed — run db:seed yourself).');
    }

    protected function contentFor(string $path, array $c): string
    {
        // Normalize separators first — app_path()/database_path()/base_path()
        // return backslashes on Windows, so a '/Models/'-style check would
        // never match there.
        $path = str_replace('\\', '/', $path);

        return match (true) {
            Str::contains($path, '/migrations/') => $this->migrationStub($c),
            Str::contains($path, 'AuthModuleTest.php') => $this->testStub($c),
            Str::contains($path, 'AuthService.php') => $this->serviceStub($c),
            Str::contains($path, 'AuthController.php') => $this->controllerStub($c),
            Str::contains($path, 'AuthFacade.php') => $this->facadeStub($c),
            Str::contains($path, '/factories/') => $this->factoryStub($c),
            Str::contains($path, '/Resources/') => $this->resourceStub($c),
            Str::contains($path, '/Policies/') => $this->policyStub($c),
            Str::contains($path, '/routes/') => $this->option('enable-routes') ? $this->liveRoutesStub($c) : $this->disabledRoutesStub($c),
            Str::contains($path, '/openapi/') => $this->openApiStub($c),
            Str::contains($path, '/Models/') => $this->modelStub($c),
            default => throw new \RuntimeException("No stub for {$path}"),
        };
    }

    protected function migrationStub(array $c): string
    {
        $phoneColumns = in_array('phone', $c['identifiers'], true)
            ? "            \$table->string('prefix_phone', 10)->nullable();\n            \$table->string('phone_number', 30)->nullable();\n"
            : '';

        $otpColumn = $c['otp']
            ? "            \$table->string('otp_delivery_method')->nullable();\n"
            : '';

        // 'status'/'activated_at' back the register()/verifyOtp() "is this
        // account verified yet" flag — only needed when 'register' is
        // actually enabled.
        $registerColumns = in_array('register', $c['otpActions'], true)
            ? "            \$table->boolean('status')->default(false);\n            \$table->timestamp('activated_at')->nullable();\n"
            : '';

        $phoneChangeColumns = in_array('phone_change', $c['otpActions'], true)
            ? "            \$table->boolean('phone_status')->default(false);\n            \$table->timestamp('phone_verified_at')->nullable();\n"
            : '';

        $emailChangeColumns = in_array('email_change', $c['otpActions'], true)
            ? "            \$table->boolean('email_status')->default(false);\n"
            : '';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, upgraded Phase 3B, 2026-10-25).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('{$c['table']}')) {
            return;
        }

        Schema::create('{$c['table']}', function (Blueprint \$table) {
            \$table->id();
            \$table->string('name');
            \$table->string('email')->unique();
            \$table->timestamp('email_verified_at')->nullable();
            \$table->string('password');
            \$table->boolean('is_active')->default(true);
{$phoneColumns}{$otpColumn}{$registerColumns}{$phoneChangeColumns}{$emailChangeColumns}            \$table->rememberToken();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$c['table']}');
    }
};

PHP;
    }

    protected function modelStub(array $c): string
    {
        $phoneFillable = in_array('phone', $c['identifiers'], true)
            ? "        'prefix_phone' => 'prefix_phone',\n        'phone_number' => 'phone_number',\n"
            : '';

        $otpFillable = $c['otp']
            ? "        'otp_delivery_method' => 'otp_delivery_method',\n"
            : '';

        $registerFillable = in_array('register', $c['otpActions'], true)
            ? "        'status' => 'status',\n"
            : '';
        $registerCasts = in_array('register', $c['otpActions'], true)
            ? "        'status' => 'boolean',\n"
            : '';

        $phoneChangeFillable = in_array('phone_change', $c['otpActions'], true)
            ? "        'phone_status' => 'phone_status',\n"
            : '';
        $phoneChangeCasts = in_array('phone_change', $c['otpActions'], true)
            ? "        'phone_status' => 'boolean',\n"
            : '';

        $emailChangeFillable = in_array('email_change', $c['otpActions'], true)
            ? "        'email_status' => 'email_status',\n"
            : '';
        $emailChangeCasts = in_array('email_change', $c['otpActions'], true)
            ? "        'email_status' => 'boolean',\n"
            : '';

        return <<<PHP
<?php

namespace App\Models;

use Database\Factories\\{$c['name']}Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, upgraded Phase 3B, 2026-10-25).
 *
 * @use HasFactory<{$c['name']}Factory>
 */
class {$c['name']} extends BaseAuthModel
{
    use HasFactory, HasRoles, Notifiable;

    protected \$fillable = [
        'name' => 'name',
        'email' => 'email',
        'email_verified_at' => 'email_verified_at',
        'password' => 'password',
        'remember_token' => 'remember_token',
        'is_active' => 'is_active',
{$phoneFillable}{$otpFillable}{$registerFillable}{$phoneChangeFillable}{$emailChangeFillable}    ];

    protected \$casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
{$registerCasts}{$phoneChangeCasts}{$emailChangeCasts}    ];

    protected \$hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

PHP;
    }

    protected function factoryStub(array $c): string
    {
        return <<<PHP
<?php

namespace Database\Factories;

use App\Models\\{$c['name']};
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, 2026-10-25).
 *
 * @extends Factory<{$c['name']}>
 */
class {$c['name']}Factory extends Factory
{
    protected static ?string \$password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::\$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return \$this->state(fn (array \$attributes) => [
            'is_active' => false,
        ]);
    }
}

PHP;
    }

    protected function serviceStub(array $c): string
    {
        $hasPhone = in_array('phone', $c['identifiers'], true);

        $phoneInputs = $hasPhone ? "        \$pp = \$request->input('prefix_phone');\n        \$pn = \$request->input('phone_number');\n" : '';
        $phoneBranch = $hasPhone ? " elseif (\$pp && \$pn && \$pass) {\n            \$actor = \$this->model::where('prefix_phone', \$pp)->where('phone_number', \$pn)->first();\n        }" : '';

        $verifyOtpBody = $this->verifyOtpBodyFor($c);

        return <<<PHP
<?php

namespace App\Services\Functional;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\\{$c['name']}Resource;
use App\Models\\{$c['name']};
use App\Services\Basic\BaseAuthService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, upgraded Phase 3B, 2026-10-25).
 *
 * Login identifiers: {$this->csv($c['identifiers'])}.
 * OTP-backed actions enabled for this module: {$this->csv($c['otpActions'] ?: ['(none — --otp was not passed)'])}.
 * register()/resendRegisterOtp()/sendResetOtp()/verifyResetOtp()/resetPassword()/
 * requestPhoneChange()/requestEmailChange() are all inherited from
 * BaseAuthService — they are real, working implementations gated by
 * config('auth_modules.modules.{$c['guard']}.otp'), not placeholders.
 * Only login() and verifyOtp() need a module-specific implementation.
 */
class {$c['name']}AuthService extends BaseAuthService
{
    protected function setVariables(): void
    {
        \$this->model    = {$c['name']}::class;
        \$this->key      = '{$c['key']}';
        \$this->resource = {$c['name']}Resource::class;
        \$this->guard    = '{$c['guard']}';
    }

    public function login(BasicRequest \$request): array
    {
        \$email = \$request->input('email');
        \$pass  = \$request->input('password');
{$phoneInputs}
        if (\$email && \$pass) {
            \$actor = \$this->model::where('email', \$email)->first();
        }{$phoneBranch} else {
            throw new HttpResponseException(\$this->requiredField(__('messages.invalid_credentials')));
        }

        if (! \$actor || ! \$actor->password || ! Hash::check(\$pass, \$actor->password)) {
            throw new HttpResponseException(\$this->requiredField(__('messages.invalid_credentials')));
        }

        // Inactive/soft-deleted accounts cannot authenticate. Soft-deleted
        // rows are already excluded above by Eloquent's default SoftDeletes
        // query scope (no withTrashed() call in any branch here).
        if (! \$actor->is_active) {
            throw new HttpResponseException(\$this->requiredField(__('messages.account_inactive')));
        }

        // completeLogin() issues a token immediately, or — if this module
        // sets require_login_challenge in config/auth_modules.php — issues
        // a login_challenge OTP instead and withholds the token until
        // verifyLoginChallenge() confirms it (off by default).
        return \$this->completeLogin(\$actor, \$request);
    }

{$verifyOtpBody}
}

PHP;
    }

    protected function verifyOtpBodyFor(array $c): string
    {
        if (! $c['otp'] || ! in_array('register', $c['otpActions'], true)) {
            return <<<'PHP'
    public function verifyOtp(BasicRequest $request): array
    {
        throw new HttpResponseException(
            $this->apiResponse(null, false, 'OTP is not available for this module yet.', 501)
        );
    }
PHP;
        }

        $phoneDelegate = in_array('phone_change', $c['otpActions'], true)
            ? "        if (\$request->purpose === 'phone_change') {\n            return \$this->verifyPhoneChangeOtp(\$request);\n        }\n\n"
            : '';
        $emailDelegate = in_array('email_change', $c['otpActions'], true)
            ? "        if (\$request->purpose === 'email_change') {\n            return \$this->verifyEmailChangeOtp(\$request);\n        }\n\n"
            : '';

        return <<<PHP
    public function verifyOtp(BasicRequest \$request): array
    {
{$phoneDelegate}{$emailDelegate}        \$col = \$request->filled('id') ? 'id' : 'email';
        \$actor = \$this->model::where(\$col, \$request->input(\$col))->firstOrFail();
        \$channel = \$actor->otp_delivery_method ?? 'email';

        if (! \\App\\Support\\OtpChannelHelper::verify(\$actor, \$request->input('purpose'), (string) \$request->input('otp'), \$channel)) {
            throw new HttpResponseException(\$this->requiredField(__('messages.invalid_otp')));
        }

        \$actor->status = true;
        \$actor->activated_at = now();
        \$actor->save();

        \$token = null;
        if (\$request->filled('id') && \$actor->id === \$request->input('id')) {
            [\$token] = \$this->issueToken(\$actor, \$request->input('token_device'));
        }

        return [
            'message' => 'Your account has been verified successfully.',
            'token' => \$token,
            \$this->key => \$this->resource::make(\$actor->fresh()),
        ];
    }
PHP;
    }

    protected function controllerStub(array $c): string
    {
        return <<<PHP
<?php

namespace App\Http\Controllers\Auth;

use App\Facades\Services\Auth\\{$c['name']}AuthFacade;
use App\Http\Controllers\FatherAuthController;
use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, 2026-10-25).
 */
class {$c['name']}AuthController extends FatherAuthController
{
    use GeneralTrait;

    protected function setVariables(): void
    {
        \$this->key = '{$c['key']}';
        \$this->service = {$c['name']}AuthFacade::class;
    }

    public function logout(Request \$request)
    {
        try {
            \$deviceToken = \$request->input('token_device');
            \$actor = \$request->user('{$c['guard']}');

            if (\$actor) {
                \$actor->currentAccessToken()?->delete();
            }

            if (\$deviceToken) {
                \$this->service::revokeDeviceToken(\$request);
            }

            return \$this->apiResponse(['message' => __('messages.logged_out')]);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
}

PHP;
    }

    protected function resourceStub(array $c): string
    {
        return <<<PHP
<?php

namespace App\Http\Resources\Model;

use App\Http\Resources\Basic\BasicResource;
use App\Models\\{$c['name']};
use App\Services\Basic\ModelColumnsService;
use Illuminate\Http\Request;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, 2026-10-25).
 */
class {$c['name']}Resource extends BasicResource
{
    public function toArray(Request \$request): array
    {
        return \$this->initResource(
            ModelColumnsService::getServiceFor({$c['name']}::class)
        );
    }
}

PHP;
    }

    protected function facadeStub(array $c): string
    {
        return <<<PHP
<?php

namespace App\Facades\Services\Auth;

use Illuminate\Support\Facades\Facade;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, 2026-10-25).
 *
 * Returns the service's FQCN directly (not a container alias) — Laravel's
 * container auto-resolves a parameterless-constructor concrete class like
 * {$c['name']}AuthService without any binding needing to exist in
 * AppServiceProvider, so this facade needs no registration step anywhere.
 */
class {$c['name']}AuthFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\Functional\\{$c['name']}AuthService::class;
    }
}

PHP;
    }

    protected function policyStub(array $c): string
    {
        return <<<PHP
<?php

namespace App\Policies;

use App\Models\\{$c['name']};
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, 2026-10-25).
 *
 * Governs an Admin (config('auth_modules.management_guard')) managing
 * {$c['name']} accounts. Typed to the generic Authenticatable contract, not
 * App\Models\Admin directly, so a non-Admin actor is denied instead of
 * TypeErroring if this policy is ever checked against one. Not wired to
 * any route yet — auto-discovered by Laravel's App\Models\X ->
 * App\Policies\XPolicy convention, ready for whichever controller ends up
 * managing {$c['name']} accounts.
 */
class {$c['name']}Policy
{
    public function viewAny(Authenticatable \$actor): bool
    {
        return \$this->isManager(\$actor);
    }

    public function view(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->isManager(\$actor);
    }

    public function create(Authenticatable \$actor): bool
    {
        return \$this->isManager(\$actor);
    }

    public function update(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->isManager(\$actor);
    }

    public function delete(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->isManager(\$actor);
    }

    public function restore(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->isManager(\$actor);
    }

    public function forceDelete(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        // Permanent delete is never granted by the generic manage-* role;
        // it needs its own explicit, separately-granted permission.
        return false;
    }

    protected function isManager(Authenticatable \$actor): bool
    {
        \$managementGuardModel = config('auth.providers.'.config('auth.guards.'.config('auth_modules.management_guard', 'admin').'.provider').'.model');

        return \$managementGuardModel
            && \$actor instanceof \$managementGuardModel
            && method_exists(\$actor, 'hasRole')
            && \$actor->hasRole('manage-{$c['guard']}');
    }
}

PHP;
    }

    /**
     * Phase 3B: route lines now reflect whichever otpActions were enabled,
     * instead of always being just login/logout.
     */
    protected function publicRouteLines(array $c): array
    {
        $lines = ["Route::post('login', [{$c['name']}AuthController::class, 'login']);"];

        if (in_array('register', $c['otpActions'], true)) {
            $lines[] = "Route::post('register', [{$c['name']}AuthController::class, 'register']);";
        }
        if (in_array('resend_register', $c['otpActions'], true)) {
            $lines[] = "Route::post('register/resend', [{$c['name']}AuthController::class, 'resendRegisterOtp']);";
        }
        if (in_array('register', $c['otpActions'], true)) {
            $lines[] = "Route::post('register/verify', [{$c['name']}AuthController::class, 'verifyOtp']);";
        }
        if (in_array('reset_password', $c['otpActions'], true)) {
            $lines[] = "Route::post('password/send-otp', [{$c['name']}AuthController::class, 'sendResetOtp']);";
            $lines[] = "Route::post('password/verify-otp', [{$c['name']}AuthController::class, 'verifyResetOtp']);";
            $lines[] = "Route::post('password/reset', [{$c['name']}AuthController::class, 'resetPassword']);";
        }

        return $lines;
    }

    protected function authenticatedRouteLines(array $c): array
    {
        $lines = ["Route::post('logout', [{$c['name']}AuthController::class, 'logout']);"];

        if (in_array('phone_change', $c['otpActions'], true)) {
            $lines[] = "Route::post('request-phone-change', [{$c['name']}AuthController::class, 'requestPhoneChange']);";
            $lines[] = "Route::post('verify-phone-change-otp', [{$c['name']}AuthController::class, 'verifyPhoneChangeOtp']);";
        }
        if (in_array('email_change', $c['otpActions'], true)) {
            $lines[] = "Route::post('request-email-change', [{$c['name']}AuthController::class, 'requestEmailChange']);";
            $lines[] = "Route::post('verify-email-change-otp', [{$c['name']}AuthController::class, 'verifyEmailChangeOtp']);";
        }

        return $lines;
    }

    /**
     * Phase 11E (2026-10-26): previously `make:auth-module` generated NO
     * OpenAPI documentation at all — a confirmed gap from Phase 8's own
     * roadmap notes ("make:auth-module-generated routes have no OpenAPI
     * generation... docs:check-drift will always flag them"). Reuses
     * publicRouteLines()/authenticatedRouteLines() — the EXACT same
     * methods liveRoutesStub()/disabledRoutesStub() already call — so
     * this can never drift from whichever routes this module actually
     * gets; parses each line's path/method rather than re-deriving the
     * otpActions conditionals a second time.
     *
     * Request body schemas are read directly off FatherAuthController's
     * own real parameter types via reflection — the SHARED, pre-existing
     * FormRequest classes it type-hints (RegisterRequest, LoginRequest,
     * etc.) — not hand-duplicated per field, so they can't silently drift
     * from the actual validation rules either.
     */
    protected function openApiStub(array $c): string
    {
        $live = (bool) $this->option('enable-routes');
        $schema = [
            'components' => [
                'schemas' => [
                    $c['name'] => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'is_active' => ['type' => 'boolean'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                ],
            ],
        ];

        if (! $live) {
            $schema['paths'] = [];

            return "# Generated by `make:auth-module {$c['name']}` (Phase 2B/3B; OpenAPI added Phase 11E, 2026-10-26).\n".
                "# This module has no --enable-routes — its routes are disabled (commented\n".
                "# out), so there is nothing live to document. Regenerate with --with-openapi\n".
                "# after also passing --enable-routes.\n".
                $this->dumpOpenApiYaml($schema);
        }

        $prefix = "/api/v1/{$c['routeFile']}";
        $paths = [];

        foreach ($this->publicRouteLines($c) as $line) {
            [$uri, $method] = $this->parseAuthRouteLine($line, $c);
            if ($uri === null) {
                continue;
            }
            [$properties, $required] = $this->authRequestSchemaFor($method);
            $paths["{$prefix}/{$uri}"] = ['post' => $this->openApiOperation("{$c['name']}: {$method}", false, $properties ?: null, $required)];
        }

        foreach ($this->authenticatedRouteLines($c) as $line) {
            [$uri, $method] = $this->parseAuthRouteLine($line, $c);
            if ($uri === null) {
                continue;
            }
            [$properties, $required] = $this->authRequestSchemaFor($method);
            $paths["{$prefix}/{$uri}"] = ['post' => $this->openApiOperation("{$c['name']}: {$method}", true, $properties ?: null, $required)];
        }

        $schema['paths'] = $paths;

        return "# Generated by `make:auth-module {$c['name']}` (Phase 2B/3B; OpenAPI added Phase 11E, 2026-10-26).\n".
            "# A COMPLETE, accurate reflection of this auth module's real, live\n".
            "# endpoints — aggregated across every module's fragment by\n".
            "# `php artisan openapi:build` into docs/openapi/openapi.yaml.\n".
            $this->dumpOpenApiYaml($schema);
    }

    /**
     * Parses a line this SAME generator already produced
     * (`Route::post('uri', [{Name}AuthController::class, 'method']);`)
     * rather than re-deriving which routes exist — [null, null] if the
     * line doesn't match the exact shape this generator always writes
     * (defensive; never expected in practice).
     */
    protected function parseAuthRouteLine(string $line, array $c): array
    {
        $pattern = "/Route::post\('([^']+)',\s*\[{$c['name']}AuthController::class,\s*'(\w+)'\]\);/";
        if (preg_match($pattern, $line, $m)) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }

    /**
     * Resolves the REAL FormRequest FatherAuthController type-hints for
     * $method (reflection, not a hand-maintained lookup table — can't
     * drift from what the controller actually requires), instantiates
     * it, and converts its rules() into OpenAPI properties/required.
     * Returns [[], []] if the method has no typed FormRequest parameter
     * (e.g. logout, which takes none).
     */
    protected function authRequestSchemaFor(string $method): array
    {
        if (! method_exists(FatherAuthController::class, $method)) {
            return [[], []];
        }

        $reflection = new \ReflectionMethod(FatherAuthController::class, $method);
        $requestClass = null;
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                $requestClass = $type->getName();
                break;
            }
        }

        if (! $requestClass) {
            return [[], []];
        }

        $rules = (new $requestClass)->rules();
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            // A nested field like 'files.*' isn't a top-level property —
            // skip it rather than emit a malformed OpenAPI property name.
            if (str_contains($field, '.')) {
                continue;
            }

            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);
            $fieldRules = array_map(fn ($r) => is_string($r) ? $r : '', $fieldRules);

            $properties[$field] = ['type' => $this->authRuleToOpenApiType($fieldRules)];
            if (in_array('required', $fieldRules, true)) {
                $required[] = $field;
            }
        }

        return [$properties, $required];
    }

    protected function authRuleToOpenApiType(array $fieldRules): string
    {
        foreach ($fieldRules as $rule) {
            if (in_array($rule, ['integer', 'numeric'], true)) {
                return 'integer';
            }
            if ($rule === 'boolean') {
                return 'boolean';
            }
            if ($rule === 'array') {
                return 'array';
            }
        }

        return 'string';
    }

    protected function disabledRoutesStub(array $c): string
    {
        $public = implode("\n", array_map(fn ($l) => "//     {$l}", $this->publicRouteLines($c)));
        $authenticated = implode("\n", array_map(fn ($l) => "//         {$l}", $this->authenticatedRouteLines($c)));

        return <<<PHP
<?php

use App\Http\Controllers\Auth\\{$c['name']}AuthController;
use Illuminate\Support\Facades\Route;

// Generated by `make:auth-module {$c['name']}` (Phase 2B, upgraded Phase 3B, 2026-10-25).
//
// Disabled by default — no --enable-routes was passed at generation time.
// Re-run with --enable-routes to generate a live, guard-isolated group, or
// hand-write one following routes/api/v1/admin.php's pattern:
//   Route::middleware(['auth:{$c['guard']}', 'guard.identity:{$c['guard']}'])->group(...)
//
// Route::prefix('{$c['routeFile']}')->group(function () {
{$public}
//
//     Route::middleware(['auth:{$c['guard']}', 'guard.identity:{$c['guard']}'])->group(function () {
{$authenticated}
//     });
// });

PHP;
    }

    protected function liveRoutesStub(array $c): string
    {
        $public = implode("\n", array_map(fn ($l) => "    {$l}", $this->publicRouteLines($c)));
        $authenticated = implode("\n", array_map(fn ($l) => "        {$l}", $this->authenticatedRouteLines($c)));

        return <<<PHP
<?php

use App\Http\Controllers\Auth\\{$c['name']}AuthController;
use Illuminate\Support\Facades\Route;

// Generated by `make:auth-module {$c['name']}` (Phase 2B, upgraded Phase 3B,
// 2026-10-25), with --enable-routes. Authenticated routes require BOTH a
// valid '{$c['guard']}' guard token AND guard.identity:{$c['guard']} (see
// App\Http\Middleware\EnsureGuardIdentity — Sanctum alone does not stop a
// different guard's token from passing auth:{$c['guard']}).

Route::prefix('{$c['routeFile']}')->group(function () {
{$public}

    Route::middleware(['auth:{$c['guard']}', 'guard.identity:{$c['guard']}'])->group(function () {
{$authenticated}
    });
});

PHP;
    }

    protected function testStub(array $c): string
    {
        return <<<PHP
<?php

namespace Tests\Feature;

use App\Models\\{$c['name']};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Generated by `make:auth-module {$c['name']}` (Phase 2B, 2026-10-25).
 *
 * NOTE: run the generated migration against your test database before
 * these tests will pass (this generator never runs migrations itself).
 */
class {$c['name']}AuthModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_and_provider_are_configured(): void
    {
        \$this->assertSame('sanctum', config('auth.guards.{$c['guard']}.driver'));
        \$this->assertSame({$c['name']}::class, config('auth.providers.'.config('auth.guards.{$c['guard']}.provider').'.model'));
    }

    public function test_inactive_actor_cannot_login(): void
    {
        Route::post('/__test/{$c['guard']}-module/login', function (\App\Http\Requests\Model\Auth\LoginRequest \$request) {
            try {
                return response()->json(app(\App\Services\Functional\\{$c['name']}AuthService::class)->login(\$request));
            } catch (\Illuminate\Http\Exceptions\HttpResponseException \$e) {
                return \$e->getResponse();
            }
        });

        {$c['name']}::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        \$response = \$this->postJson('/__test/{$c['guard']}-module/login', [
            'email' => 'inactive@example.com',
            'password' => 'secret123',
        ]);

        \$response->assertStatus(400);
        \$response->assertJson(['status' => false]);
    }

    public function test_active_actor_can_login(): void
    {
        Route::post('/__test/{$c['guard']}-module/login-ok', function (\App\Http\Requests\Model\Auth\LoginRequest \$request) {
            try {
                return response()->json(app(\App\Services\Functional\\{$c['name']}AuthService::class)->login(\$request));
            } catch (\Illuminate\Http\Exceptions\HttpResponseException \$e) {
                return \$e->getResponse();
            }
        });

        {$c['name']}::factory()->create([
            'email' => 'active@example.com',
            'password' => Hash::make('secret123'),
        ]);

        \$response = \$this->postJson('/__test/{$c['guard']}-module/login-ok', [
            'email' => 'active@example.com',
            'password' => 'secret123',
        ]);

        \$response->assertStatus(200);
        \$this->assertNotEmpty(\$response->json('data.token'));
    }

    public function test_a_different_guards_token_is_rejected_by_this_guards_route(): void
    {
        Route::middleware(['auth:{$c['guard']}', 'guard.identity:{$c['guard']}'])
            ->get('/__test/{$c['guard']}-module/protected', fn () => response()->json(['ok' => true]));

        \$other = \App\Models\Admin::factory()->create();
        \$token = \$other->createToken('t')->plainTextToken;

        \$this->withHeader('Authorization', "Bearer {\$token}")
            ->getJson('/__test/{$c['guard']}-module/protected')
            ->assertStatus(401);
    }
}

PHP;
    }

    protected function csv(array $values): string
    {
        return implode(', ', $values);
    }
}
