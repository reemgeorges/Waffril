<?php

namespace App\Console\Commands\Initialize;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class InitBasicsCommand extends Command
{
    protected $signature = 'init:structure
        {--soft-deletes : Forward a trashed‐mode flag to generators that support it}
        {--uuid         : Forward a uuid‐mode flag to generators that support it}';

    protected $description = 'Initialize the basics in the structure';

    protected $commands = [
        "make:base-model",
        "make:base-auth-model",
        "make:default-controller",
        "make:father-crud-controller",
        "make:basic-resource",
        "make:basic-request",
        "make:model-columns-service",
        "make:basic-crud-service",
        "make:provider-bindings",
        "make:general-trait",
        "make:auth-user-token",
    ];

    public function handle()
    {
        // Grab *all* options passed to this parent command
        // e.g. ['soft-deletes' => true, 'uuid' => false, 'another' => true]
        $allOptions = $this->options();

        foreach ($this->commands as $command) {
            // Locate the Symfony Command instance for introspection
            $cmd = $this->getApplication()->find($command);

            // Build the parameter list by filtering only those options
            // that were passed *and* that the child command actually defines
            $params = [];
            foreach ($allOptions as $name => $value) {
                if ($value && $cmd->getDefinition()->hasOption($name)) {
                    $params["--{$name}"] = true;
                }
            }

            $flags = empty($params) ? '' : ' ' . implode(' ', array_keys($params));
            $this->info("Running: {$command}{$flags}");
            $this->call($command, $params);
        }

        return 0;
    }
}
