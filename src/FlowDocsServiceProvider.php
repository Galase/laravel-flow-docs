<?php

declare(strict_types=1);

namespace Galase\FlowDocs;

use Galase\FlowDocs\Console\GenerateFlowDocsCommand;
use Illuminate\Support\ServiceProvider;

class FlowDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flow-docs.php', 'flow-docs');

        $this->app->singleton(FlowDocsGenerator::class, function () {
            return new FlowDocsGenerator();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/flow-docs.php' => config_path('flow-docs.php'),
        ], 'flow-docs-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateFlowDocsCommand::class,
            ]);
        }
    }
}
