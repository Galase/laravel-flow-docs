<?php

declare(strict_types=1);

namespace Galase\FlowDocs;

use Galase\FlowDocs\Support\Analysis\CodeAnalyzer;
use Galase\FlowDocs\Support\Analysis\MigrationAnalyzer;
use Galase\FlowDocs\Support\Analysis\PhpSourceAnalyzer;
use Galase\FlowDocs\Support\Docs\ClassDocsGenerator;
use Galase\FlowDocs\Support\Docs\DatabaseDocsGenerator;
use Galase\FlowDocs\Support\Docs\ModelDocsGenerator;
use Galase\FlowDocs\Support\Docs\RootIndexRenderer;
use Illuminate\Support\Facades\Route;

class FlowDocsGenerator
{
    public function generate(array $config, array $options = []): array
    {
        $output = rtrim((string) ($config['output_path'] ?? public_path('docs/flow')), '/');
        $appDir = (string) ($config['app_dir'] ?? app_path());
        $onlyServices = (bool) ($options['only_services'] ?? false);
        $onlyControllers = (bool) ($options['only_controllers'] ?? false);
        $withRoutes = (bool) ($options['with_routes'] ?? true);

        $appFiles = PhpSourceAnalyzer::phpFiles($appDir);
        $classes = PhpSourceAnalyzer::discoverClasses($appFiles);
        $services = array_filter($classes, fn (array $class) => CodeAnalyzer::isServiceClass($class, $config));
        $controllers = array_filter($classes, fn (array $class) => CodeAnalyzer::isControllerClass($class, $config));
        $models = array_filter($classes, fn (array $class) => CodeAnalyzer::isModelClass($class, $config));
        $migrations = MigrationAnalyzer::analyze($config);
        $joins = CodeAnalyzer::inferJoins($classes, $config);
        $routes = $withRoutes ? $this->routesByAction() : [];
        $docTools = $this->docTools();

        $files = 0;
        if (! $onlyControllers) {
            $files += ClassDocsGenerator::generate($services, 'services', $classes, $config, $output, [], $docTools);
        }

        if (! $onlyServices) {
            $files += ClassDocsGenerator::generate($controllers, 'controllers', $classes, $config, $output, $routes, $docTools);
        }

        if (! $onlyServices && ! $onlyControllers) {
            $files += ModelDocsGenerator::generate($models, $migrations, $joins, $config, $output, $docTools);
            $files += DatabaseDocsGenerator::generate($migrations, $models, $joins, $config, $output, $docTools);
        }

        RootIndexRenderer::write($output, $config, ! $onlyControllers, ! $onlyServices, ! $onlyServices && ! $onlyControllers, ! $onlyServices && ! $onlyControllers);
        $files++;

        return [
            'services' => count($services),
            'controllers' => count($controllers),
            'models' => count($models),
            'tables' => count($migrations['tables']),
            'methods' => array_sum(array_map(fn (array $class) => count($class['methods']), $classes)),
            'files' => $files,
            'output' => $output,
        ];
    }

    private function docTools(): array
    {
        return [
            'phpFiles' => fn (string $dir): array => PhpSourceAnalyzer::phpFiles($dir),
            'methodReturnIndex' => fn (array $classes, array $config): array => CodeAnalyzer::methodReturnIndex($classes, $config),
            'usageMap' => fn (array $classes, array $files): array => CodeAnalyzer::usageMap($classes, $files),
            'fileName' => fn (string $fqcn): string => $this->fileName($fqcn),
            'tableFileName' => fn (string $table): string => $this->tableFileName($table),
            'modelTableName' => fn (array $model): string => CodeAnalyzer::modelTableName($model),
            'modelRelations' => fn (array $model, array $config): array => CodeAnalyzer::modelRelations($model, $config),
            'dependencyInjections' => fn (array $class, array $config = []): array => CodeAnalyzer::dependencyInjections($class, $config),
            'inferVariableModels' => fn (array $method, array $class, array $returnIndex, array $config = []): array => CodeAnalyzer::inferVariableModels($method, $class, $returnIndex, $config),
            'detectModels' => fn (array $method, array $class, array $returnIndex, array $config): array => CodeAnalyzer::detectModels($method, $class, $returnIndex, $config),
            'detectActions' => fn (string $body, array $config): array => CodeAnalyzer::detectActions($body, $config),
            'detectCalls' => fn (string $body, array $config = []): array => CodeAnalyzer::detectCalls($body, $config),
            'explicitPurpose' => fn (array $method, array $models, array $inferred, array $config): string => CodeAnalyzer::explicitPurpose($method, $models, $inferred, $config),
            'lineExplanations' => fn (array $method, array $inferred, array $config = []): array => CodeAnalyzer::lineExplanations($method, $inferred, $config),
        ];
    }

    private function routesByAction(): array
    {
        $map = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@') && $action !== 'Closure') {
                $action .= '@__invoke';
            }
            if ($action === 'Closure') {
                continue;
            }
            $map[$action][] = [
                'method' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
            ];
        }

        return $map;
    }

    private function fileName(string $fqcn): string
    {
        return str_replace('\\', '__', $fqcn) . '.html';
    }

    private function tableFileName(string $table): string
    {
        return (preg_replace('/[^A-Za-z0-9_-]+/', '_', $table) ?? 'table') . '.html';
    }
}
