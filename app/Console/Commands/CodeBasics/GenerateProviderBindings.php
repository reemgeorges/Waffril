<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateProviderBindings extends Command
{
    protected $signature = 'make:provider-bindings';
    protected $description = 'Ensure AppServiceProvider has a $facades array and registers them in register()';

    public function handle(Filesystem $files)
    {
        $provider = app_path('Providers/AppServiceProvider.php');
        if (! $files->exists($provider)) {
            return $this->error("AppServiceProvider not found at {$provider}");
        }

        $content = $files->get($provider);

        // 1) Ensure protected $facades = [] is declared
        if (strpos($content, 'protected $facades') === false) {
            // Insert right after the class opening brace
            $content = preg_replace(
                '/(class\s+AppServiceProvider[^{]*\{\r?\n)/',
                "$1    /**\n     * Facades to register in the container\n     * @var array<string, string>\n     */\n    protected \$facades = [];\n\n",
                $content
            );
            $this->info('Inserted protected $facades = [] into AppServiceProvider.');
        } else {
            $this->info('$facades property already exists.');
        }

        // 2) Ensure the register() method contains the foreach loop
        if (strpos($content, 'foreach ($this->facades') === false) {
            $loop = <<<'PHP'
        foreach ($this->facades as $facade => $service) {
            $this->app->singleton($facade, function () use ($service) {
                return new $service;
            });
        }
PHP;
            // Inject loop right after the opening brace of register()
            $content = preg_replace(
                '/(public function register\(\)[^{]*\{\r?\n)/',
                "$1{$loop}\n",
                $content
            );
            $this->info('Injected facade-registration loop into register().');
        } else {
            $this->info('Registration loop already present in register().');
        }

        // 3) Save changes
        $files->put($provider, $content);

        return 0;
    }
}
