<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Console;

use Galase\FlowDocs\FlowDocsGenerator;
use Illuminate\Console\Command;

class GenerateFlowDocsCommand extends Command
{
    protected $signature = 'flow-docs:generate
        {--services : Generate only service documentation}
        {--controllers : Generate only controller documentation}
        {--output= : Override configured output path}
        {--force : Overwrite generated HTML files}
        {--no-routes : Skip route analysis}';

    protected $description = 'Generate static HTML flow documentation for a Laravel application.';

    public function handle(FlowDocsGenerator $generator): int
    {
        if (! file_exists(config_path('flow-docs.php'))) {
            $this->error('Flow Docs config file not found.');
            $this->line('Run: php artisan vendor:publish --tag=flow-docs-config');

            return self::FAILURE;
        }

        if ($this->option('services') && $this->option('controllers')) {
            $this->error('Use either --services or --controllers, not both.');

            return self::FAILURE;
        }

        $config = config('flow-docs', []);

        if ($this->option('output')) {
            $output = (string) $this->option('output');
            $config['output_path'] = str_starts_with($output, DIRECTORY_SEPARATOR)
                ? $output
                : base_path($output);
        }

        $this->line('Generating flow documentation...');

        $result = $generator->generate($config, [
            'only_services' => (bool) $this->option('services'),
            'only_controllers' => (bool) $this->option('controllers'),
            'force' => (bool) $this->option('force'),
            'with_routes' => ! (bool) $this->option('no-routes'),
        ]);

        $this->info('Flow documentation generated.');
        $this->table(
            ['Metric', 'Total'],
            [
                ['Services', $result['services']],
                ['Controllers', $result['controllers']],
                ['Models', $result['models']],
                ['Tables', $result['tables']],
                ['Methods', $result['methods']],
                ['Files written', $result['files']],
                ['Output', $result['output']],
            ]
        );

        return self::SUCCESS;
    }
}
