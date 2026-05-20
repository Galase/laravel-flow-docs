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

        $classes = $this->discoverClasses($this->phpFiles($appDir));
        $services = array_filter($classes, fn (array $class) => $this->startsWithAnyNamespace($class['namespace'], $config['service_namespaces'] ?? []));
        $controllers = array_filter($classes, fn (array $class) => $this->startsWithAnyNamespace($class['namespace'], $config['controller_namespaces'] ?? []));
        $routes = $withRoutes ? $this->routesByAction() : [];

        $files = 0;
        if (! $onlyControllers) {
            $files += $this->generateClassDocs($services, 'services', $classes, $config, $output, []);
        }

        if (! $onlyServices) {
            $files += $this->generateClassDocs($controllers, 'controllers', $classes, $config, $output, $routes);
        }

        $this->writeRootIndex($output, $config, !$onlyControllers, !$onlyServices);
        $files++;

        return [
            'services' => count($services),
            'controllers' => count($controllers),
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
        }

        $title = $kind === 'services' ? 'Documentacao por Service' : 'Documentacao por Controller';
        $index = $this->pageStart($title) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $index .= $this->optionalBackLink($config);
        $index .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">' . $this->h($title) . '</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Documentacao estatica gerada a partir dos arquivos PHP. Inclui metodos, chamadas, models detectadas, variaveis inferidas por retorno de metodos internos e leitura objetiva do que cada fluxo faz.</p></header>';
        $index .= $this->metricCards([ucfirst($kind) => count($classes), 'Metodos' => $totalMethods, 'Publicos' => $totalPublic, 'Pasta' => $kind . '/' . $kind]);
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

        $html = $this->pageStart($fqcn) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="../index.html" class="text-sm font-semibold text-blue-700">Voltar ao indice</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Documentacao por ' . $this->h(rtrim($kind, 's')) . '</p><h1 class="mt-2 break-words text-3xl font-semibold">' . $this->h($fqcn) . '</h1><p class="mt-3 text-sm text-slate-600"><code>' . $this->h($class['path']) . '</code></p></header>';
        $html .= $this->metricCards(['Metodos' => count($class['methods']), 'Publicos' => count(array_filter($class['methods'], fn ($m) => $m['visibility'] === 'public')), 'Usos detectados' => count($usedBy), 'Linhas' => $class['lines']]);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Onde aparece</h2>' . $usageHtml . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Dependencias importadas</h2>' . $imports . '</section>';
        $html .= '<section class="mt-6 space-y-5">' . $sections . '</section></main></body></html>';

        return $html;
    }

    private function writeRootIndex(string $output, array $config, bool $hasServices, bool $hasControllers): void
    {
        $this->ensureDirectory($output);
        $cards = '';
        if ($hasServices) {
            $cards .= '<a class="rounded-lg border bg-white p-5 hover:border-blue-400" href="services/index.html"><h2 class="text-xl font-semibold">Services</h2><p class="mt-2 text-sm text-slate-600">Fluxos de services, actions e use cases.</p></a>';
        }
        if ($hasControllers) {
            $cards .= '<a class="rounded-lg border bg-white p-5 hover:border-blue-400" href="controllers/index.html"><h2 class="text-xl font-semibold">Controllers</h2><p class="mt-2 text-sm text-slate-600">Rotas, actions e fluxos de entrada HTTP.</p></a>';
        }
        $html = $this->pageStart('Flow Docs') . '<main class="mx-auto max-w-5xl px-6 py-10"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . $this->h($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Flow Docs</h1><p class="mt-3 text-sm leading-6 text-slate-600">Documentacao estatica gerada por galase/laravel-flow-docs.</p><section class="mt-8 grid gap-4 md:grid-cols-2">' . $cards . '</section></main></body></html>';
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
            if ($fqcn === $prefix || str_starts_with($fqcn, $prefix . '\\')) return true;
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

    private function pageStart(string $title): string
    {
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $this->h($title) . '</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 text-slate-900">';
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
