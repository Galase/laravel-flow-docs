<?php

declare(strict_types=1);

namespace Galase\FlowDocs;

use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

class FlowDocsGenerator
{
    public function generate(array $config, array $options = []): array
    {
        $output = rtrim((string) ($config['output_path'] ?? public_path('docs/flow')), '/');
        $appDir = (string) ($config['app_dir'] ?? app_path());
        $onlyServices = (bool) ($options['only_services'] ?? false);
        $onlyControllers = (bool) ($options['only_controllers'] ?? false);
        $withRoutes = (bool) ($options['with_routes'] ?? true);

        $appFiles = $this->phpFiles($appDir);
        $classes = $this->discoverClasses($appFiles);
        $services = array_filter($classes, fn (array $class) => $this->isServiceClass($class, $config));
        $controllers = array_filter($classes, fn (array $class) => $this->isControllerClass($class, $config));
        $models = array_filter($classes, fn (array $class) => $this->isModelClass($class, $config));
        $migrations = $this->analyzeMigrations($config);
        $joins = $this->inferJoins($classes);
        $routes = $withRoutes ? $this->routesByAction() : [];

        $files = 0;
        if (! $onlyControllers) {
            $files += $this->generateClassDocs($services, 'services', $classes, $config, $output, []);
        }

        if (! $onlyServices) {
            $files += $this->generateClassDocs($controllers, 'controllers', $classes, $config, $output, $routes);
        }

        if (! $onlyServices && ! $onlyControllers) {
            $files += $this->generateModelDocs($models, $classes, $migrations, $joins, $config, $output);
            $files += $this->generateDatabaseDocs($migrations, $models, $joins, $config, $output);
        }

        $this->writeRootIndex($output, $config, !$onlyControllers, !$onlyServices, ! $onlyServices && ! $onlyControllers, ! $onlyServices && ! $onlyControllers);
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

    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function discoverClasses(array $files): array
    {
        $classes = [];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $tokens = token_get_all($code);
            $class = $this->classFromTokens($tokens);

            if ($class === '') {
                continue;
            }

            $namespace = $this->namespaceFromTokens($tokens);
            $fqcn = $namespace !== '' ? $namespace . '\\' . $class : $class;

            $classes[$fqcn] = [
                'fqcn' => $fqcn,
                'class' => $class,
                'namespace' => $namespace,
                'file' => $file,
                'path' => $this->relativePath($file),
                'code' => $code,
                'imports' => $this->importsFromCode($code),
                'extends' => $this->extendsFromTokens($tokens),
                'methods' => $this->extractMethods($code),
                'lines' => substr_count($code, "\n") + 1,
            ];
        }

        ksort($classes);

        return $classes;
    }

    private function extractMethods(string $code): array
    {
        $tokens = token_get_all($code);
        $methods = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                }
            }

            if (! $name) {
                continue;
            }

            $visibility = 'public';
            $static = false;
            for ($k = $i - 1; $k >= max(0, $i - 14); $k--) {
                if ($tokens[$k] === ';' || $tokens[$k] === '}') {
                    break;
                }
                if (is_array($tokens[$k])) {
                    $visibility = match ($tokens[$k][0]) {
                        T_PUBLIC => 'public',
                        T_PROTECTED => 'protected',
                        T_PRIVATE => 'private',
                        default => $visibility,
                    };
                    $static = $static || $tokens[$k][0] === T_STATIC;
                }
            }

            $brace = null;
            $signature = '';
            for ($j = $i; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $brace = $j;
                    break;
                }
                if ($tokens[$j] === ';') {
                    break;
                }
                $signature .= $this->tokenText($tokens[$j]);
            }

            if ($brace === null) {
                continue;
            }

            $level = 0;
            $end = $brace;
            for ($j = $brace; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $level++;
                } elseif ($tokens[$j] === '}') {
                    $level--;
                }
                if ($level === 0) {
                    $end = $j;
                    break;
                }
            }

            $body = '';
            for ($j = $brace + 1; $j < $end; $j++) {
                $body .= $this->tokenText($tokens[$j]);
            }

            $returnType = null;
            if (preg_match('/\)\s*:\s*([?\\\\A-Za-z0-9_|]+)/', $signature, $match)) {
                $returnType = ltrim($match[1], '?');
            }

            $startLine = is_array($tokens[$i]) ? $tokens[$i][2] : 0;
            $methods[] = [
                'name' => $name,
                'visibility' => $visibility,
                'static' => $static,
                'signature' => trim(preg_replace('/\s+/', ' ', $signature) ?? ''),
                'returnType' => $returnType,
                'startLine' => $startLine,
                'endLine' => $startLine + substr_count($body, "\n") + 1,
                'body' => $body,
            ];
        }

        return $methods;
    }

    private function generateClassDocs(array $classes, string $kind, array $allClasses, array $config, string $output, array $routes): int
    {
        $base = $output . '/' . $kind;
        $items = $base . '/' . $kind;
        $this->ensureDirectory($items);

        $returnIndex = $this->methodReturnIndex($allClasses, $config);
        $usage = $this->usageMap($classes, $this->phpFiles((string) ($config['app_dir'] ?? app_path())));
        $files = 0;
        $cards = '';
        $indexItems = '';
        $totalMethods = 0;
        $totalPublic = 0;

        foreach ($classes as $fqcn => $class) {
            $fileName = $this->fileName($fqcn);
            $public = count(array_filter($class['methods'], fn (array $m) => $m['visibility'] === 'public'));
            $routeCount = 0;
            foreach ($class['methods'] as $method) {
                $routeCount += count($routes[$fqcn . '@' . $method['name']] ?? []);
            }
            $totalMethods += count($class['methods']);
            $totalPublic += $public;
            $cards .= '<a class="block rounded-lg border border-slate-200 bg-white p-4 hover:border-blue-400" href="' . $kind . '/' . $this->h($fileName) . '">';
            $cards .= '<p class="break-words text-sm font-semibold text-slate-900">' . $this->h($fqcn) . '</p>';
            $cards .= '<p class="mt-2 text-xs text-slate-500">' . $this->h($class['path']) . '</p>';
            $cards .= '<div class="mt-3 flex flex-wrap gap-2 text-xs"><span class="rounded bg-blue-50 px-2 py-1 text-blue-700">' . $public . ' publicos</span><span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">' . count($usage[$fqcn]) . ' usos</span><span class="rounded bg-violet-50 px-2 py-1 text-violet-700">' . $routeCount . ' rotas</span></div>';
            $cards .= '</a>';
            $indexItems .= '<li><a class="flex items-center justify-between gap-4 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:border-blue-400" href="' . $kind . '/' . $this->h($fileName) . '"><span class="break-all font-medium text-slate-900">' . $this->h($fqcn) . '</span><span class="shrink-0 text-xs text-slate-500">' . $public . ' publicos</span></a></li>';
        }

        $title = $kind === 'services' ? 'Documentacao por Service' : 'Documentacao por Controller';
        $index = $this->pageStart($title, 1) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $index .= $this->optionalBackLink($config);
        $index .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">' . $this->h($title) . '</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Documentacao estatica gerada a partir dos arquivos PHP. Inclui metodos, chamadas, models detectadas, variaveis inferidas por retorno de metodos internos e leitura objetiva do que cada fluxo faz.</p></header>';
        $index .= $this->metricCards([ucfirst($kind) => count($classes), 'Metodos' => $totalMethods, 'Publicos' => $totalPublic, 'Pasta' => $kind . '/' . $kind]);
        $index .= '<section class="mt-8"><h2 class="text-lg font-semibold">Indice</h2><ul class="mt-3 grid gap-2 lg:grid-cols-2">' . ($indexItems ?: '<li class="rounded border bg-white px-3 py-2 text-sm text-slate-500">Nenhum item detectado.</li>') . '</ul></section>';
        $index .= '<section class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">' . $cards . '</section></main></body></html>';
        file_put_contents($base . '/index.html', $index);
        $files++;

        foreach ($classes as $fqcn => $class) {
            file_put_contents($items . '/' . $this->fileName($fqcn), $this->renderClassPage($fqcn, $class, $kind, $config, $returnIndex, $usage[$fqcn] ?? [], $routes));
            $files++;
        }

        return $files;
    }

    private function renderClassPage(string $fqcn, array $class, string $kind, array $config, array $returnIndex, array $usedBy, array $routes): string
    {
        $imports = $class['imports']
            ? '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($i) => '<li><code>' . $this->h($i) . '</code></li>', $class['imports'])) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">Nenhum import direto detectado.</p>';
        $usageHtml = $usedBy
            ? '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($i) => '<li><code>' . $this->h($i) . '</code></li>', $usedBy)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">Nenhum uso textual detectado em app/.</p>';
        $dependencies = $this->dependencyInjections($class);
        $dependenciesHtml = $dependencies
            ? '<ul class="mt-2 space-y-2 text-sm text-slate-700">' . implode('', array_map(fn ($dependency) => '<li><code>' . $this->h($dependency['type'] . ' $' . $dependency['name']) . '</code><span class="ml-2 text-slate-500">' . $this->h($dependency['where']) . '</span></li>', $dependencies)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">Nenhuma injecao por construtor ou metodo tipado detectada.</p>';

        $sections = '';
        foreach ($class['methods'] as $method) {
            $inferred = $this->inferVariableModels($method, $class, $returnIndex);
            $models = $this->detectModels($method, $class, $returnIndex, $config);
            $actions = $this->detectActions($method['body'], $config);
            $calls = $this->detectCalls($method['body']);
            $purpose = $this->explicitPurpose($method, $models, $inferred, $config);
            $methodRoutes = $routes[$fqcn . '@' . $method['name']] ?? [];
            $routesHtml = $methodRoutes
                ? '<ul class="space-y-1">' . implode('', array_map(fn ($r) => '<li><code>' . $this->h(($r['method'] ?? '') . ' ' . ($r['uri'] ?? '')) . '</code>' . (! empty($r['name']) ? '<span class="ml-2 text-slate-500">' . $this->h($r['name']) . '</span>' : '') . '</li>', $methodRoutes)) . '</ul>'
                : '<span class="text-slate-400">Sem rota direta; chamado internamente, por service, job, observer ou legado.</span>';
            $varsHtml = $inferred
                ? '<ul class="mt-2 space-y-1">' . implode('', array_map(fn ($var, $meta) => '<li><code>$' . $this->h($var) . '</code> => <strong>' . $this->h($meta['model']) . '</strong><span class="text-slate-500"> (' . $this->h($meta['source']) . ')</span></li>', array_keys($inferred), $inferred)) . '</ul>'
                : '<p class="mt-2 text-sm text-slate-500">Nenhuma variavel com model inferida por retorno interno.</p>';

            $rows = '';
            foreach ($this->lineExplanations($method, $inferred) as $row) {
                $rows .= '<tr class="align-top"><td class="w-20 whitespace-nowrap border-t px-3 py-2 text-xs text-slate-500">' . $this->h($row['line']) . '</td><td class="border-t px-3 py-2 text-xs"><code>' . $this->h($row['code']) . '</code></td><td class="border-t px-3 py-2 text-sm text-slate-700">' . $this->h($row['explanation']) . '</td></tr>';
            }

            $sections .= '<section class="rounded-lg border border-slate-200 bg-white p-5"><div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between"><div><h2 class="text-lg font-semibold">' . $this->h($method['name']) . '</h2><p class="mt-1 text-xs text-slate-500">' . $this->h($method['visibility'] . ($method['static'] ? ' static' : '')) . ' - linhas ' . $this->h($method['startLine']) . ' a ' . $this->h($method['endLine']) . '</p></div><span class="text-xs font-semibold text-slate-500">' . $this->h($class['path']) . '</span></div>';
            $sections .= '<p class="mt-4 text-sm leading-6 text-slate-700">' . $this->h($purpose) . '</p>';
            $sections .= '<div class="mt-4 grid gap-4 lg:grid-cols-3"><div><h3 class="text-xs font-semibold uppercase text-slate-500">Rotas ligadas</h3><div class="mt-2 text-sm">' . $routesHtml . '</div></div><div><h3 class="text-xs font-semibold uppercase text-slate-500">Models/entidades</h3><p class="mt-2 text-sm text-slate-600">' . $this->h($models ? implode(', ', $models) : 'Nenhum model direto ou inferido') . '</p></div><div><h3 class="text-xs font-semibold uppercase text-slate-500">Acoes</h3><p class="mt-2 text-sm text-slate-600">' . $this->h(implode(' | ', $actions)) . '</p></div></div>';
            $sections .= '<div class="mt-4 rounded-lg bg-slate-50 p-4"><h3 class="text-xs font-semibold uppercase text-slate-500">Variaveis inferidas como model</h3>' . $varsHtml . '</div>';
            $sections .= '<div class="mt-4"><h3 class="text-xs font-semibold uppercase text-slate-500">Chamadas internas</h3><p class="mt-2 text-sm text-slate-600">' . $this->h($calls ? implode(' | ', array_slice($calls, 0, 24)) : 'Sem chamadas relevantes detectadas') . '</p></div>';
            $sections .= '<div class="mt-5 overflow-hidden rounded-lg border"><table class="min-w-full border-collapse bg-white"><thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Linha</th><th class="px-3 py-2">Codigo</th><th class="px-3 py-2">Leitura do fluxo</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">Metodo sem corpo operacional relevante.</td></tr>') . '</tbody></table></div></section>';
        }

        $html = $this->pageStart($fqcn, 2) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="../index.html" class="text-sm font-semibold text-blue-700">Voltar ao indice</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Documentacao por ' . $this->h(rtrim($kind, 's')) . '</p><h1 class="mt-2 break-words text-3xl font-semibold">' . $this->h($fqcn) . '</h1><p class="mt-3 text-sm text-slate-600"><code>' . $this->h($class['path']) . '</code></p></header>';
        $html .= $this->metricCards(['Metodos' => count($class['methods']), 'Publicos' => count(array_filter($class['methods'], fn ($m) => $m['visibility'] === 'public')), 'Usos detectados' => count($usedBy), 'Linhas' => $class['lines']]);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Onde aparece</h2>' . $usageHtml . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Dependencias importadas</h2>' . $imports . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Injecao de dependencias</h2>' . $dependenciesHtml . '</section>';
        $html .= '<section class="mt-6 space-y-5">' . $sections . '</section></main></body></html>';

        return $html;
    }

    private function generateModelDocs(array $models, array $allClasses, array $migrations, array $joins, array $config, string $output): int
    {
        $base = $output . '/models';
        $items = $base . '/models';
        $this->ensureDirectory($items);

        $usage = $this->usageMap($models, $this->phpFiles((string) ($config['app_dir'] ?? app_path())));
        $files = 0;
        $cards = '';
        $indexItems = '';

        foreach ($models as $fqcn => $model) {
            $table = $this->modelTableName($model);
            $relations = $this->modelRelations($model, $config);
            $fileName = $this->fileName($fqcn);
            $cards .= '<a class="block rounded-lg border border-slate-200 bg-white p-4 hover:border-blue-400" href="models/' . $this->h($fileName) . '">';
            $cards .= '<p class="break-words text-sm font-semibold text-slate-900">' . $this->h($fqcn) . '</p>';
            $cards .= '<p class="mt-2 text-xs text-slate-500">Tabela inferida: <code>' . $this->h($table) . '</code></p>';
            $cards .= '<div class="mt-3 flex flex-wrap gap-2 text-xs"><span class="rounded bg-blue-50 px-2 py-1 text-blue-700">' . count($relations) . ' relations</span><span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">' . count($usage[$fqcn] ?? []) . ' usos</span><span class="rounded bg-violet-50 px-2 py-1 text-violet-700">' . count($migrations['tables'][$table]['columns'] ?? []) . ' colunas</span></div>';
            $cards .= '</a>';
            $indexItems .= '<li><a class="flex items-center justify-between gap-4 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:border-blue-400" href="models/' . $this->h($fileName) . '"><span class="break-all font-medium text-slate-900">' . $this->h($fqcn) . '</span><code class="shrink-0 text-xs text-slate-500">' . $this->h($table) . '</code></a></li>';
        }

        $index = $this->pageStart('Documentacao por Model', 1) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $index .= $this->optionalBackLink($config);
        $index .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Documentacao por Model</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Models detectadas por namespace, pasta, heranca ou padrao de Eloquent. Inclui tabela inferida, relations declaradas, usos no codigo e schema vindo das migrations.</p></header>';
        $index .= $this->metricCards(['Models' => count($models), 'Tabelas em migrations' => count($migrations['tables']), 'Relations' => array_sum(array_map(fn ($model) => count($this->modelRelations($model, $config)), $models)), 'Joins inferidos' => count($joins)]);
        $index .= '<section class="mt-8"><h2 class="text-lg font-semibold">Indice</h2><ul class="mt-3 grid gap-2 lg:grid-cols-2">' . ($indexItems ?: '<li class="rounded border bg-white px-3 py-2 text-sm text-slate-500">Nenhuma model detectada.</li>') . '</ul></section>';
        $index .= '<section class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">' . $cards . '</section></main></body></html>';
        file_put_contents($base . '/index.html', $index);
        $files++;

        foreach ($models as $fqcn => $model) {
            file_put_contents($items . '/' . $this->fileName($fqcn), $this->renderModelPage($fqcn, $model, $usage[$fqcn] ?? [], $migrations, $joins, $config));
            $files++;
        }

        return $files;
    }

    private function renderModelPage(string $fqcn, array $model, array $usedBy, array $migrations, array $joins, array $config): string
    {
        $table = $this->modelTableName($model);
        $schema = $migrations['tables'][$table] ?? ['columns' => [], 'foreign_keys' => [], 'migrations' => []];
        $relations = $this->modelRelations($model, $config);
        $tableJoins = array_values(array_filter($joins, fn ($join) => in_array($table, [$join['base_table'], $join['join_table']], true)));

        $columnsRows = '';
        foreach ($schema['columns'] as $column) {
            $columnsRows .= '<tr><td class="border-t px-3 py-2"><code>' . $this->h($column['name']) . '</code></td><td class="border-t px-3 py-2">' . $this->h($column['type']) . '</td><td class="border-t px-3 py-2 text-slate-500">' . $this->h($column['source']) . '</td></tr>';
        }
        $fkRows = '';
        foreach ($schema['foreign_keys'] as $fk) {
            $fkRows .= '<tr><td class="border-t px-3 py-2"><code>' . $this->h($fk['column']) . '</code></td><td class="border-t px-3 py-2"><code>' . $this->h($fk['references_table'] . '.' . $fk['references_column']) . '</code></td><td class="border-t px-3 py-2 text-slate-500">' . $this->h($fk['source']) . '</td></tr>';
        }
        $relationItems = $relations
            ? '<ul class="mt-2 grid gap-2 text-sm text-slate-700 md:grid-cols-2">' . implode('', array_map(fn ($r) => '<li><code>' . $this->h($r['method']) . '()</code> ' . $this->h($r['type']) . ' <strong>' . $this->h($r['target']) . '</strong><span class="text-slate-500"> ' . $this->h($r['keys']) . '</span></li>', $relations)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">Nenhuma relation Eloquent declarada detectada.</p>';
        $usageHtml = $usedBy
            ? '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($i) => '<li><code>' . $this->h($i) . '</code></li>', $usedBy)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">Nenhum uso textual detectado em app/.</p>';
        $joinsHtml = $tableJoins
            ? '<ul class="mt-2 space-y-2 text-sm text-slate-700">' . implode('', array_map(fn ($join) => '<li><code>' . $this->h($join['base_table'] . ' join ' . $join['join_table']) . '</code><span class="ml-2 text-slate-500">' . $this->h($join['condition'] . ' em ' . $join['class'] . '@' . $join['method']) . '</span></li>', $tableJoins)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">Nenhum join textual envolvendo esta tabela foi inferido no codigo analisado.</p>';

        $html = $this->pageStart($fqcn, 2) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="../index.html" class="text-sm font-semibold text-blue-700">Voltar ao indice</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Documentacao por Model</p><h1 class="mt-2 break-words text-3xl font-semibold">' . $this->h($fqcn) . '</h1><p class="mt-3 text-sm text-slate-600"><code>' . $this->h($model['path']) . '</code></p></header>';
        $html .= $this->metricCards(['Tabela' => $table, 'Colunas' => count($schema['columns']), 'FKs' => count($schema['foreign_keys']), 'Relations' => count($relations)]);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Relations na model</h2>' . $relationItems . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Colunas em migrations</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Coluna</th><th class="px-3 py-2">Tipo</th><th class="px-3 py-2">Origem</th></tr></thead><tbody>' . ($columnsRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">Nenhuma coluna detectada para a tabela inferida.</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Foreign keys em migrations</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Coluna</th><th class="px-3 py-2">Referencia</th><th class="px-3 py-2">Origem</th></tr></thead><tbody>' . ($fkRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">Nenhuma foreign key detectada para a tabela inferida.</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Joins inferidos no codigo</h2>' . $joinsHtml . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Onde aparece</h2>' . $usageHtml . '</section></main></body></html>';

        return $html;
    }

    private function generateDatabaseDocs(array $migrations, array $models, array $joins, array $config, string $output): int
    {
        $base = $output . '/database';
        $this->ensureDirectory($base);
        $modelTables = [];
        foreach ($models as $model) {
            $modelTables[$this->modelTableName($model)][] = $model['fqcn'];
        }

        $cards = '';
        $indexItems = '<li><a class="flex items-center justify-between gap-4 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-sm hover:border-blue-400" href="diagram.html"><span class="font-medium text-blue-900">Diagrama do banco</span><span class="text-xs text-blue-700">visual</span></a></li>';
        foreach ($migrations['tables'] as $table => $schema) {
            $relatedModels = $modelTables[$table] ?? [];
            $anchor = 'table-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $table);
            $indexItems .= '<li><a class="flex items-center justify-between gap-4 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:border-blue-400" href="#' . $this->h($anchor) . '"><span class="break-all font-medium text-slate-900">' . $this->h($table) . '</span><span class="shrink-0 text-xs text-slate-500">' . count($schema['columns']) . ' colunas</span></a></li>';
            $cards .= '<section id="' . $this->h($anchor) . '" class="scroll-mt-24 rounded-lg border bg-white p-5"><div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between"><h2 class="break-words text-xl font-semibold">' . $this->h($table) . '</h2><span class="text-xs text-slate-500">' . count($schema['migrations']) . ' migrations</span></div>';
            $cards .= '<div class="mt-3 flex flex-wrap gap-2 text-xs"><span class="rounded bg-blue-50 px-2 py-1 text-blue-700">' . count($schema['columns']) . ' colunas</span><span class="rounded bg-rose-50 px-2 py-1 text-rose-700">' . count($schema['foreign_keys']) . ' FKs</span><span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">' . count($relatedModels) . ' models</span></div>';
            $cards .= '<p class="mt-3 text-sm text-slate-600">Models: ' . $this->h($relatedModels ? implode(', ', $relatedModels) : 'nenhuma model direta inferida') . '</p>';
            if ($schema['foreign_keys']) {
                $cards .= '<ul class="mt-3 space-y-1 text-sm text-slate-700">' . implode('', array_map(fn ($fk) => '<li><code>' . $this->h($fk['column']) . '</code> referencia <code>' . $this->h($fk['references_table'] . '.' . $fk['references_column']) . '</code></li>', $schema['foreign_keys'])) . '</ul>';
            }
            $cards .= '</section>';
        }

        $html = $this->pageStart('Documentacao do Banco', 1) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $html .= $this->optionalBackLink($config);
        $html .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Documentacao do Banco</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Schema inferido a partir das migrations, foreign keys declaradas e joins encontrados nos metodos da aplicacao.</p></header>';
        $html .= $this->metricCards(['Tabelas' => count($migrations['tables']), 'Colunas' => array_sum(array_map(fn ($t) => count($t['columns']), $migrations['tables'])), 'FKs' => array_sum(array_map(fn ($t) => count($t['foreign_keys']), $migrations['tables'])), 'Joins inferidos' => count($joins)]);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><h2 class="text-lg font-semibold">Diagrama do banco</h2><p class="mt-1 text-sm text-slate-600">Mapa visual gerado a partir das tabelas e foreign keys detectadas nas migrations.</p></div><a class="inline-flex w-fit rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" href="diagram.html">Abrir diagrama</a></div></section>';
        $html .= '<section class="mt-8"><h2 class="text-lg font-semibold">Indice</h2><ul class="mt-3 grid gap-2 lg:grid-cols-2">' . $indexItems . '</ul></section>';
        $html .= '<section class="mt-8 grid gap-4 lg:grid-cols-2">' . ($cards ?: '<p class="text-sm text-slate-500">Nenhuma migration encontrada.</p>') . '</section></main></body></html>';
        file_put_contents($base . '/index.html', $html);
        file_put_contents($base . '/diagram.html', $this->renderDatabaseDiagramPage($migrations, $models, $config));

        return 2;
    }

    private function renderDatabaseDiagramPage(array $migrations, array $models, array $config): string
    {
        $diagram = $this->databaseDiagramData($migrations, $models);
        $nodes = $diagram['nodes'];
        $edges = $diagram['edges'];
        $width = $diagram['width'];
        $height = $diagram['height'];

        $lines = '';
        foreach ($edges as $edge) {
            $from = $nodes[$edge['from']] ?? null;
            $to = $nodes[$edge['to']] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            $x1 = $from['x'] + $from['width'];
            $y1 = $from['y'] + 34;
            $x2 = $to['x'];
            $y2 = $to['y'] + 34;
            if ($x1 > $x2) {
                $x1 = $from['x'];
                $x2 = $to['x'] + $to['width'];
            }
            $mid = (int) (($x1 + $x2) / 2);
            $labelX = (int) (($x1 + $x2) / 2);
            $labelY = (int) (($y1 + $y2) / 2) - 6;
            $path = 'M ' . $x1 . ' ' . $y1 . ' C ' . $mid . ' ' . $y1 . ', ' . $mid . ' ' . $y2 . ', ' . $x2 . ' ' . $y2;
            $lines .= '<path d="' . $this->h($path) . '" fill="none" stroke="#2563eb" stroke-width="2" marker-end="url(#arrow)" opacity="0.75"></path>';
            $lines .= '<text x="' . $labelX . '" y="' . $labelY . '" text-anchor="middle" class="fill-slate-600 text-[11px]">' . $this->h($edge['column']) . '</text>';
        }

        $cards = '';
        foreach ($nodes as $node) {
            $columns = array_slice($node['columns'], 0, 14);
            $columnItems = '';
            foreach ($columns as $column) {
                $columnItems .= '<li class="flex items-center justify-between gap-3 border-t border-slate-100 px-3 py-1.5"><code class="truncate text-xs text-slate-700">' . $this->h($column['name']) . '</code><span class="shrink-0 text-[11px] text-slate-500">' . $this->h($column['type']) . '</span></li>';
            }
            if (count($node['columns']) > count($columns)) {
                $columnItems .= '<li class="border-t border-slate-100 px-3 py-1.5 text-xs text-slate-500">+' . (count($node['columns']) - count($columns)) . ' colunas</li>';
            }
            if ($columnItems === '') {
                $columnItems = '<li class="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">Sem colunas detectadas.</li>';
            }
            $modelLabel = $node['models'] ? implode(', ', $node['models']) : 'sem model direta';
            $cards .= '<section class="absolute overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm" style="left:' . $node['x'] . 'px;top:' . $node['y'] . 'px;width:' . $node['width'] . 'px">';
            $cards .= '<header class="border-b border-slate-200 bg-slate-900 px-3 py-2 text-white"><h2 class="truncate text-sm font-semibold">' . $this->h($node['name']) . '</h2><p class="mt-0.5 truncate text-[11px] text-slate-300">' . $this->h($modelLabel) . '</p></header>';
            $cards .= '<ul class="max-h-72 overflow-hidden bg-white">' . $columnItems . '</ul></section>';
        }

        $empty = $nodes
            ? ''
            : '<section class="rounded-lg border bg-white p-5 text-sm text-slate-500">Nenhuma tabela encontrada para desenhar.</section>';

        $script = <<<'HTML'
<script>
(() => {
  const viewport = document.getElementById('diagramViewport');
  const canvas = document.getElementById('diagramCanvas');
  const zoomLabel = document.getElementById('diagramZoomLabel');
  if (!viewport || !canvas || !zoomLabel) return;
  let scale = 1;
  let x = 0;
  let y = 0;
  let dragging = false;
  let startX = 0;
  let startY = 0;
  let originX = 0;
  let originY = 0;
  const render = () => {
    canvas.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
    zoomLabel.textContent = `${Math.round(scale * 100)}%`;
  };
  const setScale = (next) => {
    scale = Math.min(2.5, Math.max(0.35, next));
    render();
  };
  document.getElementById('diagramZoomIn')?.addEventListener('click', () => setScale(scale + 0.15));
  document.getElementById('diagramZoomOut')?.addEventListener('click', () => setScale(scale - 0.15));
  document.getElementById('diagramReset')?.addEventListener('click', () => {
    scale = 1;
    x = 0;
    y = 0;
    render();
  });
  viewport.addEventListener('wheel', (event) => {
    if (!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    setScale(scale + (event.deltaY < 0 ? 0.1 : -0.1));
  }, { passive: false });
  viewport.addEventListener('pointerdown', (event) => {
    dragging = true;
    startX = event.clientX;
    startY = event.clientY;
    originX = x;
    originY = y;
    viewport.setPointerCapture(event.pointerId);
    viewport.classList.add('cursor-grabbing');
  });
  viewport.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    x = originX + event.clientX - startX;
    y = originY + event.clientY - startY;
    render();
  });
  const stop = (event) => {
    dragging = false;
    viewport.classList.remove('cursor-grabbing');
    if (event.pointerId) viewport.releasePointerCapture(event.pointerId);
  };
  viewport.addEventListener('pointerup', stop);
  viewport.addEventListener('pointercancel', stop);
  render();
})();
</script>
HTML;

        $html = $this->pageStart('Diagrama do Banco', 1) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="index.html" class="text-sm font-semibold text-blue-700">Voltar ao banco</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Diagrama do Banco</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Diagrama HTML gerado a partir das tabelas, colunas, models inferidas e foreign keys detectadas nas migrations.</p></header>';
        $html .= $this->metricCards(['Tabelas' => count($nodes), 'Relacoes' => count($edges), 'Largura' => $width . 'px', 'Altura' => $height . 'px']);
        $html .= '<section class="mt-8 rounded-lg border bg-white"><div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3"><button id="diagramZoomOut" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" type="button">-</button><span id="diagramZoomLabel" class="min-w-14 text-center text-sm font-semibold text-slate-700">100%</span><button id="diagramZoomIn" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" type="button">+</button><button id="diagramReset" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" type="button">Reset</button><span class="text-xs text-slate-500">Arraste o diagrama para navegar. Use Ctrl + scroll para zoom.</span></div><div id="diagramViewport" class="h-[72vh] overflow-hidden bg-slate-100 cursor-grab touch-none"><div id="diagramCanvas" class="relative origin-top-left bg-slate-50" style="width:' . $width . 'px;height:' . $height . 'px"><svg class="absolute inset-0" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><defs><marker id="arrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="#2563eb"></path></marker></defs>' . $lines . '</svg>' . $cards . '</div></div></section>' . $empty . '</main>' . $script . '</body></html>';

        return $html;
    }

    private function databaseDiagramData(array $migrations, array $models): array
    {
        $modelTables = [];
        foreach ($models as $model) {
            $modelTables[$this->modelTableName($model)][] = $model['fqcn'];
        }

        $tableNames = array_keys($migrations['tables']);
        foreach ($migrations['tables'] as $schema) {
            foreach ($schema['foreign_keys'] as $fk) {
                $tableNames[] = $fk['references_table'];
            }
        }
        $tableNames = array_values(array_unique($tableNames));
        sort($tableNames);

        $nodeWidth = 260;
        $xGap = 80;
        $yGap = 70;
        $columns = max(1, min(3, (int) ceil(sqrt(max(1, count($tableNames))))));
        $nodes = [];

        foreach ($tableNames as $index => $table) {
            $schema = $migrations['tables'][$table] ?? ['columns' => [], 'foreign_keys' => []];
            $row = intdiv($index, $columns);
            $column = $index % $columns;
            $nodeHeight = 76 + (min(14, max(1, count($schema['columns']))) * 30);
            $nodes[$table] = [
                'name' => $table,
                'x' => 40 + ($column * ($nodeWidth + $xGap)),
                'y' => 40 + ($row * ($nodeHeight + $yGap)),
                'width' => $nodeWidth,
                'height' => $nodeHeight,
                'columns' => $schema['columns'],
                'models' => $modelTables[$table] ?? [],
            ];
        }

        $edges = [];
        foreach ($migrations['tables'] as $table => $schema) {
            foreach ($schema['foreign_keys'] as $fk) {
                $edges[] = [
                    'from' => $table,
                    'to' => $fk['references_table'],
                    'column' => $fk['column'] . ' -> ' . $fk['references_column'],
                ];
            }
        }

        $width = 1200;
        $height = 520;
        foreach ($nodes as $node) {
            $width = max($width, $node['x'] + $node['width'] + 60);
            $height = max($height, $node['y'] + $node['height'] + 60);
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'width' => $width, 'height' => $height];
    }

    private function writeRootIndex(string $output, array $config, bool $hasServices, bool $hasControllers, bool $hasModels, bool $hasDatabase): void
    {
        $this->ensureDirectory($output);
        $cards = '';
        $indexItems = '';
        if ($hasServices) {
            $cards .= '<a class="rounded-lg border bg-white p-5 hover:border-blue-400" href="services/index.html"><h2 class="text-xl font-semibold">Services</h2><p class="mt-2 text-sm text-slate-600">Fluxos de services, actions e use cases.</p></a>';
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="services/index.html"><span>Services</span><span class="text-xs text-slate-500">fluxos</span></a></li>';
        }
        if ($hasControllers) {
            $cards .= '<a class="rounded-lg border bg-white p-5 hover:border-blue-400" href="controllers/index.html"><h2 class="text-xl font-semibold">Controllers</h2><p class="mt-2 text-sm text-slate-600">Rotas, actions e fluxos de entrada HTTP.</p></a>';
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="controllers/index.html"><span>Controllers</span><span class="text-xs text-slate-500">HTTP</span></a></li>';
        }
        if ($hasModels) {
            $cards .= '<a class="rounded-lg border bg-white p-5 hover:border-blue-400" href="models/index.html"><h2 class="text-xl font-semibold">Models</h2><p class="mt-2 text-sm text-slate-600">Models, relations, tabela inferida e usos no codigo.</p></a>';
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="models/index.html"><span>Models</span><span class="text-xs text-slate-500">Eloquent</span></a></li>';
        }
        if ($hasDatabase) {
            $cards .= '<a class="rounded-lg border bg-white p-5 hover:border-blue-400" href="database/index.html"><h2 class="text-xl font-semibold">Banco de dados</h2><p class="mt-2 text-sm text-slate-600">Migrations, colunas, foreign keys e joins inferidos.</p></a>';
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="database/index.html"><span>Banco de dados</span><span class="text-xs text-slate-500">schema</span></a></li><li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="database/diagram.html"><span>Diagrama do banco</span><span class="text-xs text-slate-500">visual</span></a></li>';
        }
        $html = $this->pageStart('Flow Docs', 0) . '<main class="mx-auto max-w-5xl px-6 py-10"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Flow Docs</h1><p class="mt-3 text-sm leading-6 text-slate-600">Documentacao estatica gerada por galase/laravel-flow-docs.</p><section class="mt-8"><h2 class="text-lg font-semibold">Indice</h2><ul class="mt-3 grid gap-2 md:grid-cols-2">' . $indexItems . '</ul></section><section class="mt-8 grid gap-4 md:grid-cols-2">' . $cards . '</section></main></body></html>';
        file_put_contents($output . '/index.html', $html);
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

    private function isControllerClass(array $class, array $config): bool
    {
        return $this->startsWithAnyNamespace($class['namespace'], $config['controller_namespaces'] ?? [])
            || str_ends_with($class['class'], 'Controller')
            || preg_match('#(^|/)(Http/)?Controllers(/|$)#', $class['path']) === 1;
    }

    private function isServiceClass(array $class, array $config): bool
    {
        if ($this->isControllerClass($class, $config) || $this->isModelClass($class, $config)) {
            return false;
        }

        return $this->startsWithAnyNamespace($class['namespace'], $config['service_namespaces'] ?? [])
            || preg_match('/(Service|Action|UseCase|Handler|Manager)$/', $class['class']) === 1
            || preg_match('#(^|/)(Services|Actions|UseCases|Domain|Application)(/|$)#', $class['path']) === 1;
    }

    private function isModelClass(array $class, array $config): bool
    {
        if ($this->isControllerClass($class, $config)) {
            return false;
        }

        $extends = $this->baseName($class['extends'] ?? '');

        return $this->isConfiguredModelImport($class['fqcn'], $config)
            || $extends === 'Model'
            || str_contains($class['code'], 'extends Model')
            || preg_match('#(^|/)Models(/|$)#', $class['path']) === 1;
    }

    private function dependencyInjections(array $class): array
    {
        $dependencies = [];
        foreach ($class['methods'] as $method) {
            foreach ($this->signatureParameters($method['signature']) as $parameter) {
                if ($parameter['type'] === '') {
                    continue;
                }
                $where = $method['name'] === '__construct'
                    ? 'injetado no construtor'
                    : 'injetado no metodo ' . $method['name'] . '()';
                if ($method['name'] === '__construct' && preg_match('/(public|protected|private)\s+[^$]*\$' . preg_quote($parameter['name'], '/') . '\b/', $parameter['raw'])) {
                    $where .= ' como propriedade promovida';
                } elseif ($method['name'] === '__construct' && preg_match('/\$this->[A-Za-z_][A-Za-z0-9_]*\s*=\s*\$' . preg_quote($parameter['name'], '/') . '\b/', $method['body'])) {
                    $where .= ' e atribuido em propriedade';
                }
                $dependencies[] = ['type' => $parameter['type'], 'name' => $parameter['name'], 'where' => $where];
            }
        }

        return array_values(array_unique($dependencies, SORT_REGULAR));
    }

    private function signatureParameters(string $signature): array
    {
        if (! preg_match('/\((.*)\)/s', $signature, $match)) {
            return [];
        }

        $parameters = [];
        foreach ($this->splitArguments($match[1]) as $raw) {
            if (! preg_match('/(?:^|[\s&])\$([A-Za-z_][A-Za-z0-9_]*)/', $raw, $nameMatch)) {
                continue;
            }
            $beforeVar = trim(substr($raw, 0, strpos($raw, '$' . $nameMatch[1])));
            $beforeVar = preg_replace('/\b(public|protected|private|readonly|static)\b/', '', $beforeVar) ?? '';
            $beforeVar = trim(str_replace(['&', '...'], '', $beforeVar));
            $type = trim(preg_replace('/\s+/', ' ', $beforeVar) ?? '');
            if ($type !== '' && ! preg_match('/^(array|callable|bool|float|int|iterable|mixed|object|string)$/i', ltrim($type, '?'))) {
                $parameters[] = ['type' => ltrim($type, '?'), 'name' => $nameMatch[1], 'raw' => $raw];
            }
        }

        return $parameters;
    }

    private function splitArguments(string $arguments): array
    {
        $items = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $arguments[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if (in_array($char, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']'], true)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $items[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $items[] = trim($buffer);
        }

        return $items;
    }

    private function analyzeMigrations(array $config): array
    {
        $tables = [];
        foreach ($this->migrationFiles($config) as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\);/s', $code, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                $table = $block[1];
                $body = $block[2];
                $tables[$table] ??= ['name' => $table, 'columns' => [], 'foreign_keys' => [], 'migrations' => []];
                $tables[$table]['migrations'][] = $this->relativePath($file);
                foreach ($this->migrationColumns($body, $file) as $column) {
                    $tables[$table]['columns'][$column['name']] = $column;
                }
                foreach ($this->migrationForeignKeys($body, $table, $file) as $fk) {
                    $tables[$table]['foreign_keys'][] = $fk;
                }
            }
        }

        foreach ($tables as $table => $schema) {
            $tables[$table]['columns'] = array_values($schema['columns']);
            $tables[$table]['foreign_keys'] = array_values($schema['foreign_keys']);
            $tables[$table]['migrations'] = array_values(array_unique($schema['migrations']));
        }
        ksort($tables);

        return ['tables' => $tables];
    }

    private function migrationFiles(array $config): array
    {
        $dirs = $config['migration_dirs'] ?? [$this->databasePath('migrations')];
        $files = [];
        foreach ($dirs as $dir) {
            foreach ($this->phpFiles((string) $dir) as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function databasePath(string $path = ''): string
    {
        if (function_exists('database_path')) {
            return database_path($path);
        }

        return rtrim(base_path('database'), '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    private function migrationColumns(string $body, string $file): array
    {
        $columns = [];
        preg_match_all('/\$table->([A-Za-z_][A-Za-z0-9_]*)\(\s*(.*?)\)\s*(?:->[^;]+)?;/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $type = $match[1];
            if (in_array($type, ['foreign', 'primary', 'unique', 'index'], true)) {
                continue;
            }
            $args = $this->splitArguments($match[2]);
            $name = $type === 'id' ? 'id' : trim($args[0] ?? '', "'\" ");
            if ($name === '' && str_ends_with($type, 'Timestamps')) {
                $name = $type;
            }
            if ($name === '') {
                continue;
            }
            $columns[] = ['name' => $name, 'type' => $type, 'source' => $this->relativePath($file)];
        }

        return $columns;
    }

    private function migrationForeignKeys(string $body, string $table, string $file): array
    {
        $fks = [];
        preg_match_all('/\$table->foreignId\(\s*[\'"]([^\'"]+)[\'"]\s*\)([^;]*);/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $column = $match[1];
            $tail = $match[2];
            $referencesTable = null;
            if (preg_match('/->constrained\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $tail, $tableMatch)) {
                $referencesTable = $tableMatch[1];
            } elseif (preg_match('/->constrained\(\s*\)/', $tail)) {
                $referencesTable = $this->pluralize($this->snakeCase(preg_replace('/_id$/', '', $column) ?? $column));
            }
            if ($referencesTable) {
                $fks[] = ['column' => $column, 'references_table' => $referencesTable, 'references_column' => 'id', 'source' => $this->relativePath($file)];
            }
        }

        preg_match_all('/\$table->foreign\(\s*[\'"]([^\'"]+)[\'"]\s*\)->references\(\s*[\'"]([^\'"]+)[\'"]\s*\)->on\(\s*[\'"]([^\'"]+)[\'"]\s*\)/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $fks[] = ['column' => $match[1], 'references_table' => $match[3], 'references_column' => $match[2], 'source' => $this->relativePath($file)];
        }

        return $fks;
    }

    private function modelRelations(array $model, array $config): array
    {
        $relations = [];
        foreach ($model['methods'] as $method) {
            if (! preg_match('/return\s+\$this->(belongsTo|hasOne|hasMany|belongsToMany|morphOne|morphMany|morphTo|morphToMany)\((.*?)\)\s*;/s', $method['body'], $match)) {
                continue;
            }
            $args = $this->splitArguments($match[2]);
            $target = $args[0] ?? '';
            if (preg_match('/([A-Z][A-Za-z0-9_\\\\]+)::class/', $target, $targetMatch)) {
                $target = $this->baseName($targetMatch[1]);
            } else {
                $target = trim($target, "'\" ");
            }
            $keys = array_slice(array_map(fn ($arg) => trim($arg, "'\" "), $args), 1);
            $relations[] = [
                'method' => $method['name'],
                'type' => $match[1],
                'target' => $target !== '' ? $target : 'inferido dinamicamente',
                'keys' => $keys ? '(' . implode(', ', $keys) . ')' : '',
            ];
        }

        return $relations;
    }

    private function modelTableName(array $model): string
    {
        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $model['code'], $match)) {
            return $match[1];
        }

        return $this->pluralize($this->snakeCase($model['class']));
    }

    private function inferJoins(array $classes): array
    {
        $joins = [];
        foreach ($classes as $class) {
            foreach ($class['methods'] as $method) {
                $baseTable = null;
                if (preg_match('/DB::table\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $method['body'], $baseMatch)) {
                    $baseTable = $baseMatch[1];
                }
                preg_match_all('/->join\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^)]*)\)/s', $method['body'], $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $joins[] = [
                        'class' => $class['fqcn'],
                        'method' => $method['name'],
                        'base_table' => $baseTable ?? 'query/modelo de origem',
                        'join_table' => $match[1],
                        'condition' => trim(preg_replace('/\s+/', ' ', $match[2]) ?? ''),
                    ];
                }
            }
        }

        return $joins;
    }

    private function methodReturnIndex(array $classes, array $config): array
    {
        $index = [];
        foreach ($classes as $fqcn => $class) {
            $aliases = $this->importAliases($class['imports']);
            foreach ($class['methods'] as $method) {
                $models = $this->inferDirectReturnModels($method, $aliases, $config);
                if ($models) {
                    $index[$fqcn . '@' . $method['name']] = $models;
                    $index[$class['class'] . '::' . $method['name']] = $models;
                }
            }
        }

        return $index;
    }

    private function inferDirectReturnModels(array $method, array $aliases, array $config): array
    {
        $models = [];
        $body = $method['body'];
        if (! empty($method['returnType'])) {
            $return = $this->baseName($method['returnType']);
            if ($this->looksLikeModel($return, $config)) {
                $models[] = $return;
            }
        }
        preg_match_all('/return\s+(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::(?:where|query|find|findOrFail|first|create|select|with|join)\b/s', $body, $matches);
        foreach ($matches[1] ?? [] as $class) {
            if ($this->looksLikeModel($class, $config)) {
                $models[] = $this->normalizeModelName($class, $aliases);
            }
        }
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::(?:where|query|find|findOrFail|first|create|select|with|join)\b/s', $body, $assignments, PREG_SET_ORDER);
        $variableModels = [];
        foreach ($assignments as $assignment) {
            $model = $this->normalizeModelName($assignment[2], $aliases);
            if ($this->looksLikeModel($model, $config)) {
                $variableModels[$assignment[1]] = $model;
            }
        }
        preg_match_all('/return\s+\$([A-Za-z_][A-Za-z0-9_]*)\s*;/', $body, $returns);
        foreach ($returns[1] ?? [] as $var) {
            if (isset($variableModels[$var])) {
                $models[] = $variableModels[$var];
            }
        }

        return array_values(array_unique($models));
    }

    private function inferVariableModels(array $method, array $class, array $returnIndex): array
    {
        $vars = [];
        $body = $method['body'];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::(?:where|query|find|findOrFail|first|create|select|with|join)\b/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $vars[$match[1]] = ['model' => $match[2], 'source' => 'atribuicao direta por query em ' . $match[2]];
        }
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $class['fqcn'] . '@' . $match[2];
            if (! empty($returnIndex[$key])) {
                $vars[$match[1]] = ['model' => implode('|', $returnIndex[$key]), 'source' => 'retorno inferido de $this->' . $match[2] . '()'];
            }
        }
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*self::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $class['fqcn'] . '@' . $match[2];
            if (! empty($returnIndex[$key])) {
                $vars[$match[1]] = ['model' => implode('|', $returnIndex[$key]), 'source' => 'retorno inferido de self::' . $match[2] . '()'];
            }
        }

        return $vars;
    }

    private function detectModels(array $method, array $class, array $returnIndex, array $config): array
    {
        $models = [];
        $body = $method['body'];
        $aliases = $this->importAliases($class['imports']);
        foreach ($class['imports'] as $import) {
            if ($this->isConfiguredModelImport($import, $config)) {
                $base = $this->baseName(preg_split('/\s+as\s+/i', $import)[0]);
                if ($this->looksLikeModel($base, $config) && preg_match('/\b' . preg_quote($base, '/') . '\b/', $body)) {
                    $models[] = $base;
                }
            }
        }
        preg_match_all('/\b([A-Z][A-Za-z0-9_]+)::(?:where|find|findOrFail|create|update|insert|select|query|join|with|orderBy|whereIn|whereNotIn)\b/', $body, $matches);
        foreach ($matches[1] ?? [] as $model) {
            $model = $this->normalizeModelName($model, $aliases);
            if ($this->looksLikeModel($model, $config)) {
                $models[] = $model;
            }
        }
        foreach ($this->inferDirectReturnModels($method, $aliases, $config) as $model) {
            $models[] = $model;
        }
        foreach ($this->inferVariableModels($method, $class, $returnIndex) as $inferred) {
            foreach (explode('|', $inferred['model']) as $model) {
                if ($this->looksLikeModel($model, $config)) {
                    $models[] = $model;
                }
            }
        }

        return array_values(array_unique($models));
    }

    private function detectActions(string $body, array $config): array
    {
        $rules = [
            '/DB::|->join\(|->select\(|->where\(|->whereIn\(|->groupBy\(|->orderBy\(/' => 'consulta dados com filtros, joins ou ordenacao',
            '/::create\(|->save\(|->update\(|::insert\(|->delete\(|::destroy\(/' => 'grava, atualiza ou remove registros',
            '/return\s+response\(\)->json|return\s+\[|json_encode\(/' => 'retorna estrutura para AJAX/API',
            '/view\(|compact\(/' => 'prepara dados para tela Blade',
            '/Excel::|download\(|Storage::|Upload|Arquivo|file|pdf|PDF|streamDownload/' => 'manipula arquivo, exportacao ou download',
            '/Mail::|Notification|dispatch\(|Job|event\(/' => 'dispara email, evento, job ou notificacao',
            '/Auth::|auth\(\)|hashAccess|account_id|user_id/' => 'usa usuario autenticado, conta ou escopo de acesso',
            '/try\s*\{|catch\s*\(/' => 'possui tratamento de excecao',
            '/foreach\s*\(|for\s*\(|while\s*\(/' => 'percorre colecoes para montar ou transformar dados',
            '/if\s*\(|switch\s*\(/' => 'aplica regras condicionais de negocio',
            '/Carbon|now\(\)|date\(|strtotime\(/' => 'calcula ou filtra datas',
        ];
        $actions = [];
        foreach ($rules as $regex => $label) {
            if (preg_match($regex, $body)) {
                $actions[] = $label;
            }
        }
        if ($this->bodyHasTerms($body, 'payload', $config) || preg_match('/data\s*=\s*\[/', $body)) {
            $actions[] = 'constroi payload ou estrutura de dados';
        }
        if ($this->bodyHasTerms($body, 'gateway', $config) || $this->bodyNamedGateway($body, $config)) {
            $actions[] = 'atua no fluxo de pagamento, gateway ou integracao externa';
        }

        return array_values(array_unique($actions)) ?: ['fluxo simples ou delegacao direta'];
    }

    private function explicitPurpose(array $method, array $models, array $inferredVars, array $config): string
    {
        $name = $method['name'];
        $body = $method['body'];
        $subject = $this->humanize($name);
        $gateway = $this->bodyNamedGateway($body, $config);
        if ($gateway && ($this->bodyHasTerms($body, 'payload', $config) || preg_match('/\[[^\]]*=>/s', $body))) {
            return 'Metodo ' . $name . ' constroi o payload para o gateway ' . $gateway . ' e prepara os dados necessarios para a chamada externa.';
        }
        if ($this->bodyHasTerms($body, 'gateway', $config) && ($this->bodyHasTerms($body, 'payload', $config) || preg_match('/\[[^\]]*=>/s', $body))) {
            return 'Metodo ' . $name . ' constroi o payload do fluxo de gateway/integracao externa e organiza os dados antes do envio.';
        }
        if (preg_match('/Excel::|download\(|export|Export/', $body)) {
            return 'Metodo ' . $name . ' gera uma exportacao ou arquivo de download para o fluxo "' . $subject . '".';
        }
        if (preg_match('/::create\(|->save\(|::insert\(/', $body)) {
            return 'Metodo ' . $name . ' cria novos registros' . ($models ? ' em ' . implode(', ', array_slice($models, 0, 3)) : '') . ' a partir dos dados recebidos.';
        }
        if (preg_match('/->update\(|::update\(/', $body)) {
            return 'Metodo ' . $name . ' atualiza dados existentes' . ($models ? ' em ' . implode(', ', array_slice($models, 0, 3)) : '') . ' dentro do fluxo "' . $subject . '".';
        }
        if (preg_match('/->where\(|::where\(|DB::|->join\(/', $body)) {
            return 'Metodo ' . $name . ' consulta e filtra dados' . ($models ? ' de ' . implode(', ', array_slice($models, 0, 3)) : '') . ' para atender o fluxo "' . $subject . '".';
        }
        if ($inferredVars) {
            $items = [];
            foreach ($inferredVars as $var => $meta) {
                $items[] = '$' . $var . ' como ' . $meta['model'];
            }
            return 'Metodo ' . $name . ' orquestra dados internos e usa retornos inferidos de model, incluindo ' . implode(', ', array_slice($items, 0, 4)) . '.';
        }
        if ($models) {
            return 'Metodo ' . $name . ' executa a regra "' . $subject . '" usando dados de ' . implode(', ', array_slice($models, 0, 4)) . '.';
        }

        return 'Metodo ' . $name . ' executa a etapa "' . $subject . '" do fluxo.';
    }

    private function lineExplanations(array $method, array $inferredVars): array
    {
        $rows = [];
        foreach (explode("\n", $method['body']) as $index => $line) {
            $trim = trim($line);
            $explanation = $this->explainLine($trim, $inferredVars);
            if (! $explanation) {
                continue;
            }
            $rows[] = ['line' => $method['startLine'] + $index + 1, 'code' => strlen($trim) > 190 ? substr($trim, 0, 187) . '...' : $trim, 'explanation' => $explanation];
            if (count($rows) >= 45) {
                $rows[] = ['line' => '', 'code' => '...', 'explanation' => 'Metodo longo; a documentacao destaca as primeiras etapas relevantes do fluxo.'];
                break;
            }
        }

        return $rows;
    }

    private function explainLine(string $line, array $inferredVars): ?string
    {
        if ($line === '' || $line === '{' || $line === '}') {
            return null;
        }
        foreach ($inferredVars as $var => $meta) {
            if (preg_match('/\$' . preg_quote($var, '/') . '\b/', $line)) {
                return 'Usa $' . $var . ' como ' . $meta['model'] . ', inferido por ' . $meta['source'] . '.';
            }
        }
        if (strpos($line, '//') === 0) return 'Comentario de apoio do proprio codigo.';
        if (preg_match('/^\$[A-Za-z0-9_]+\s*=\s*new\s+([A-Za-z0-9_\\\\]+)/', $line, $match)) return 'Instancia ' . $match[1] . ' para delegar parte da regra.';
        if (preg_match('/^\$[A-Za-z0-9_]+\s*=\s*\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $match)) return 'Recebe o retorno do metodo interno ' . $match[1] . '() e segue o fluxo com essa variavel.';
        if (preg_match('/^\$[A-Za-z0-9_]+\s*=/', $line)) return 'Monta uma variavel intermediaria usada nas proximas etapas do fluxo.';
        if (preg_match('/return\s+response\(\)->json/', $line)) return 'Devolve resposta JSON para consumo de tela ou API.';
        if (preg_match('/^return\b/', $line)) return 'Encerra o metodo devolvendo o resultado calculado.';
        if (preg_match('/->where\(|::where\(/', $line)) return 'Aplica filtro na consulta.';
        if (preg_match('/->join\(|::join\(/', $line)) return 'Relaciona tabelas para compor a consulta.';
        if (preg_match('/->select\(|::select\(/', $line)) return 'Define os campos retornados pela consulta.';
        if (preg_match('/->get\(|->first\(|->paginate\(|->pluck\(/', $line)) return 'Executa a consulta e materializa o resultado.';
        if (preg_match('/create\(|save\(|update\(|insert\(|delete\(|destroy\(/', $line)) return 'Persiste alteracao no banco de dados.';
        if (preg_match('/if\s*\(/', $line)) return 'Abre uma regra condicional para decidir o proximo passo.';
        if (preg_match('/else\b/', $line)) return 'Define o caminho alternativo da regra anterior.';
        if (preg_match('/foreach\s*\(|for\s*\(|while\s*\(/', $line)) return 'Percorre uma colecao ou intervalo de dados.';
        if (preg_match('/try\s*\{/', $line)) return 'Inicia bloco protegido contra falhas.';
        if (preg_match('/catch\s*\(/', $line)) return 'Trata excecao gerada no fluxo.';
        if (preg_match('/payload|Payload|\[[^\]]*=>/', $line)) return 'Monta parte do payload ou estrutura que sera enviada/adaptada pelo fluxo.';

        return 'Executa uma etapa operacional do fluxo.';
    }

    private function usageMap(array $targets, array $files): array
    {
        $usage = [];
        foreach ($targets as $fqcn => $target) {
            $usage[$fqcn] = [];
        }
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach ($targets as $fqcn => $target) {
                if ($file !== $target['file'] && preg_match('/\b' . preg_quote($target['class'], '/') . '\b/', $code)) {
                    $usage[$fqcn][] = $this->relativePath($file);
                }
            }
        }
        foreach ($usage as $fqcn => $found) {
            $usage[$fqcn] = array_slice(array_values(array_unique($found)), 0, 60);
        }

        return $usage;
    }

    private function detectCalls(string $body): array
    {
        $calls = [];
        preg_match_all('/new\s+([A-Z][A-Za-z0-9_\\\\]+)\s*\(/', $body, $matches);
        foreach ($matches[1] ?? [] as $call) $calls[] = 'instancia ' . $call;
        preg_match_all('/([A-Z][A-Za-z0-9_\\\\]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) $calls[] = $match[1] . '::' . $match[2] . '()';
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches);
        foreach (array_slice(array_unique($matches[1] ?? []), 0, 22) as $call) $calls[] = '->' . $call . '()';

        return array_values(array_unique($calls));
    }

    private function namespaceFromTokens(array $tokens): string
    {
        $namespace = '';
        $collect = false;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $collect = true;
                continue;
            }
            if ($collect) {
                if ($token === ';' || $token === '{') return trim($namespace);
                $namespace .= $this->tokenText($token);
            }
        }
        return '';
    }

    private function classFromTokens(array $tokens): string
    {
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) return $tokens[$j][1];
                }
            }
        }
        return '';
    }

    private function extendsFromTokens(array $tokens): string
    {
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_EXTENDS) {
                continue;
            }
            $extends = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '{' || (is_array($tokens[$j]) && $tokens[$j][0] === T_IMPLEMENTS)) {
                    break;
                }
                $extends .= $this->tokenText($tokens[$j]);
            }

            return trim($extends);
        }

        return '';
    }

    private function importsFromCode(string $code): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $code, $matches);
        return array_values(array_filter(array_map('trim', $matches[1] ?? [])));
    }

    private function importAliases(array $imports): array
    {
        $aliases = [];
        foreach ($imports as $import) {
            $parts = preg_split('/\s+as\s+/i', $import);
            $fqcn = trim($parts[0]);
            $alias = isset($parts[1]) ? trim($parts[1]) : $this->baseName($fqcn);
            $aliases[$alias] = $fqcn;
        }
        return $aliases;
    }

    private function isConfiguredModelImport(string $import, array $config): bool
    {
        $fqcn = trim(preg_split('/\s+as\s+/i', $import)[0]);
        foreach ($config['model_namespaces'] ?? [] as $prefix) {
            $prefix = trim($prefix, '\\');
            if ($fqcn === $prefix) return true;
            if ($prefix === 'App' && preg_match('/^App\\\\[A-Z][A-Za-z0-9_]+$/', $fqcn)) return true;
            if ($prefix !== 'App' && str_starts_with($fqcn, $prefix . '\\')) return true;
        }
        return false;
    }

    private function startsWithAnyNamespace(string $namespace, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '\\');
            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) return true;
        }
        return false;
    }

    private function bodyHasTerms(string $body, string $group, array $config): bool
    {
        $terms = $config['business_terms'][$group] ?? [];
        return $terms && preg_match('/(?:' . implode('|', array_map(fn ($term) => preg_quote($term, '/'), $terms)) . ')/', $body);
    }

    private function bodyNamedGateway(string $body, array $config): ?string
    {
        foreach ($config['named_gateways'] ?? [] as $gateway) {
            if (preg_match('/' . preg_quote($gateway, '/') . '/i', $body)) return $gateway;
        }
        return null;
    }

    private function looksLikeModel(string $class, array $config): bool
    {
        return ! in_array($this->baseName($class), $config['ignored_model_names'] ?? [], true);
    }

    private function normalizeModelName(string $class, array $aliases): string
    {
        $class = trim($class, '\\');
        return isset($aliases[$class]) ? $this->baseName($aliases[$class]) : $this->baseName($class);
    }

    private function baseName(string $class): string
    {
        return basename(str_replace('\\', '/', trim($class, '\\')));
    }

    private function tokenText($token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }

    private function humanize(string $name): string
    {
        return trim(strtolower(str_replace('_', ' ', preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name)));
    }

    private function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;
        return strtolower(str_replace(['-', ' '], '_', $value));
    }

    private function pluralize(string $value): string
    {
        if (str_ends_with($value, 'y')) {
            return substr($value, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $value)) {
            return $value . 'es';
        }

        return $value . 's';
    }

    private function fileName(string $fqcn): string
    {
        return str_replace('\\', '__', $fqcn) . '.html';
    }

    private function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function pageStart(string $title, int $depth = 0): string
    {
        $root = str_repeat('../', $depth);
        $nav = [
            'Inicio' => $root . 'index.html',
            'Services' => $root . 'services/index.html',
            'Controllers' => $root . 'controllers/index.html',
            'Models' => $root . 'models/index.html',
            'Banco' => $root . 'database/index.html',
            'Diagrama' => $root . 'database/diagram.html',
        ];
        $links = '';
        foreach ($nav as $label => $href) {
            $links .= '<a class="rounded px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950" href="' . $this->h($href) . '">' . $this->h($label) . '</a>';
        }

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $this->h($title) . '</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 text-slate-900"><nav class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur"><div class="mx-auto flex max-w-7xl flex-col gap-2 px-6 py-3 md:flex-row md:items-center md:justify-between"><a class="text-sm font-semibold text-slate-950" href="' . $this->h($root . 'index.html') . '">Flow Docs</a><div class="flex flex-wrap gap-1">' . $links . '</div></div></nav>';
    }

    private function metricCards(array $cards): string
    {
        $html = '<section class="mt-6 grid gap-4 md:grid-cols-4">';
        foreach ($cards as $label => $value) {
            $html .= '<div class="rounded-lg border bg-white p-4"><p class="text-xs uppercase text-slate-500">' . $this->h($label) . '</p><p class="mt-2 break-words text-2xl font-semibold">' . $this->h($value) . '</p></div>';
        }
        return $html . '</section>';
    }

    private function optionalBackLink(array $config): string
    {
        if (empty($config['back_link'])) {
            return '';
        }
        return '<a href="' . $this->h($config['back_link']) . '" class="text-sm font-semibold text-blue-700">Voltar</a>';
    }
}
