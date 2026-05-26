<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Analysis;

final class CodeAnalyzer
{
    public static function isControllerClass(array $class, array $config): bool
    {
        return self::startsWithAnyNamespace($class['namespace'], $config['controller_namespaces'] ?? [])
            || str_ends_with($class['class'], 'Controller')
            || preg_match('#(^|/)(Http/)?Controllers(/|$)#', $class['path']) === 1;
    }

    public static function isServiceClass(array $class, array $config): bool
    {
        if (self::isControllerClass($class, $config) || self::isModelClass($class, $config)) {
            return false;
        }

        return self::startsWithAnyNamespace($class['namespace'], $config['service_namespaces'] ?? [])
            || preg_match('/(Service|Action|UseCase|Handler|Manager)$/', $class['class']) === 1
            || preg_match('#(^|/)(Services|Actions|UseCases|Domain|Application)(/|$)#', $class['path']) === 1;
    }

    public static function isModelClass(array $class, array $config): bool
    {
        if (self::isControllerClass($class, $config)) {
            return false;
        }

        $extends = TextAnalyzer::baseName($class['extends'] ?? '');

        return self::isConfiguredModelImport($class['fqcn'], $config)
            || $extends === 'Model'
            || str_contains($class['code'], 'extends Model')
            || preg_match('#(^|/)Models(/|$)#', $class['path']) === 1;
    }

    public static function dependencyInjections(array $class): array
    {
        $dependencies = [];
        foreach ($class['methods'] as $method) {
            foreach (self::signatureParameters($method['signature']) as $parameter) {
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

    public static function modelRelations(array $model, array $config): array
    {
        $relations = [];
        foreach ($model['methods'] as $method) {
            if (! preg_match('/return\s+\$this->(belongsTo|hasOne|hasMany|belongsToMany|morphOne|morphMany|morphTo|morphToMany)\((.*?)\)\s*;/s', $method['body'], $match)) {
                continue;
            }
            $args = TextAnalyzer::splitArguments($match[2]);
            $target = $args[0] ?? '';
            if (preg_match('/([A-Z][A-Za-z0-9_\\\\]+)::class/', $target, $targetMatch)) {
                $target = TextAnalyzer::baseName($targetMatch[1]);
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

    public static function modelTableName(array $model): string
    {
        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $model['code'], $match)) {
            return $match[1];
        }

        return TextAnalyzer::pluralize(TextAnalyzer::snakeCase($model['class']));
    }

    public static function inferJoins(array $classes): array
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

    public static function methodReturnIndex(array $classes, array $config): array
    {
        $index = [];
        foreach ($classes as $fqcn => $class) {
            $aliases = self::importAliases($class['imports']);
            foreach ($class['methods'] as $method) {
                $models = self::inferDirectReturnModels($method, $aliases, $config);
                if ($models) {
                    $index[$fqcn . '@' . $method['name']] = $models;
                    $index[$class['class'] . '::' . $method['name']] = $models;
                }
            }
        }

        return $index;
    }

    public static function inferVariableModels(array $method, array $class, array $returnIndex): array
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

    public static function detectModels(array $method, array $class, array $returnIndex, array $config): array
    {
        $models = [];
        $body = $method['body'];
        $aliases = self::importAliases($class['imports']);
        foreach ($class['imports'] as $import) {
            if (self::isConfiguredModelImport($import, $config)) {
                $base = TextAnalyzer::baseName(preg_split('/\s+as\s+/i', $import)[0]);
                if (self::looksLikeModel($base, $config) && preg_match('/\b' . preg_quote($base, '/') . '\b/', $body)) {
                    $models[] = $base;
                }
            }
        }
        preg_match_all('/\b([A-Z][A-Za-z0-9_]+)::(?:where|find|findOrFail|create|update|insert|select|query|join|with|orderBy|whereIn|whereNotIn)\b/', $body, $matches);
        foreach ($matches[1] ?? [] as $model) {
            $model = self::normalizeModelName($model, $aliases);
            if (self::looksLikeModel($model, $config)) {
                $models[] = $model;
            }
        }
        foreach (self::inferDirectReturnModels($method, $aliases, $config) as $model) {
            $models[] = $model;
        }
        foreach (self::inferVariableModels($method, $class, $returnIndex) as $inferred) {
            foreach (explode('|', $inferred['model']) as $model) {
                if (self::looksLikeModel($model, $config)) {
                    $models[] = $model;
                }
            }
        }

        return array_values(array_unique($models));
    }

    public static function detectActions(string $body, array $config): array
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
        if (self::bodyHasTerms($body, 'payload', $config) || preg_match('/data\s*=\s*\[/', $body)) {
            $actions[] = 'constroi payload ou estrutura de dados';
        }
        if (self::bodyHasTerms($body, 'gateway', $config) || self::bodyNamedGateway($body, $config)) {
            $actions[] = 'atua no fluxo de pagamento, gateway ou integracao externa';
        }

        return array_values(array_unique($actions)) ?: ['fluxo simples ou delegacao direta'];
    }

    public static function explicitPurpose(array $method, array $models, array $inferredVars, array $config): string
    {
        $name = $method['name'];
        $body = $method['body'];
        $subject = TextAnalyzer::humanize($name);
        $gateway = self::bodyNamedGateway($body, $config);
        if ($gateway && (self::bodyHasTerms($body, 'payload', $config) || preg_match('/\[[^\]]*=>/s', $body))) {
            return 'Metodo ' . $name . ' constroi o payload para o gateway ' . $gateway . ' e prepara os dados necessarios para a chamada externa.';
        }
        if (self::bodyHasTerms($body, 'gateway', $config) && (self::bodyHasTerms($body, 'payload', $config) || preg_match('/\[[^\]]*=>/s', $body))) {
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

    public static function lineExplanations(array $method, array $inferredVars): array
    {
        $rows = [];
        foreach (explode("\n", $method['body']) as $index => $line) {
            $trim = trim($line);
            $explanation = self::explainLine($trim, $inferredVars);
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

    public static function usageMap(array $targets, array $files): array
    {
        $usage = [];
        foreach ($targets as $fqcn => $target) {
            $usage[$fqcn] = [];
        }
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach ($targets as $fqcn => $target) {
                if ($file !== $target['file'] && preg_match('/\b' . preg_quote($target['class'], '/') . '\b/', $code)) {
                    $usage[$fqcn][] = PhpSourceAnalyzer::relativePath($file);
                }
            }
        }
        foreach ($usage as $fqcn => $found) {
            $usage[$fqcn] = array_slice(array_values(array_unique($found)), 0, 60);
        }

        return $usage;
    }

    public static function detectCalls(string $body): array
    {
        $calls = [];
        preg_match_all('/new\s+([A-Z][A-Za-z0-9_\\\\]+)\s*\(/', $body, $matches);
        foreach ($matches[1] ?? [] as $call) {
            $calls[] = 'instancia ' . $call;
        }
        preg_match_all('/([A-Z][A-Za-z0-9_\\\\]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $calls[] = $match[1] . '::' . $match[2] . '()';
        }
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches);
        foreach (array_slice(array_unique($matches[1] ?? []), 0, 22) as $call) {
            $calls[] = '->' . $call . '()';
        }

        return array_values(array_unique($calls));
    }

    private static function signatureParameters(string $signature): array
    {
        if (! preg_match('/\((.*)\)/s', $signature, $match)) {
            return [];
        }

        $parameters = [];
        foreach (TextAnalyzer::splitArguments($match[1]) as $raw) {
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

    private static function inferDirectReturnModels(array $method, array $aliases, array $config): array
    {
        $models = [];
        $body = $method['body'];
        if (! empty($method['returnType'])) {
            $return = TextAnalyzer::baseName($method['returnType']);
            if (self::looksLikeModel($return, $config)) {
                $models[] = $return;
            }
        }
        preg_match_all('/return\s+(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::(?:where|query|find|findOrFail|first|create|select|with|join)\b/s', $body, $matches);
        foreach ($matches[1] ?? [] as $class) {
            if (self::looksLikeModel($class, $config)) {
                $models[] = self::normalizeModelName($class, $aliases);
            }
        }
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::(?:where|query|find|findOrFail|first|create|select|with|join)\b/s', $body, $assignments, PREG_SET_ORDER);
        $variableModels = [];
        foreach ($assignments as $assignment) {
            $model = self::normalizeModelName($assignment[2], $aliases);
            if (self::looksLikeModel($model, $config)) {
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

    private static function explainLine(string $line, array $inferredVars): ?string
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

    private static function importAliases(array $imports): array
    {
        $aliases = [];
        foreach ($imports as $import) {
            $parts = preg_split('/\s+as\s+/i', $import);
            $fqcn = trim($parts[0]);
            $alias = isset($parts[1]) ? trim($parts[1]) : TextAnalyzer::baseName($fqcn);
            $aliases[$alias] = $fqcn;
        }

        return $aliases;
    }

    private static function isConfiguredModelImport(string $import, array $config): bool
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

    private static function startsWithAnyNamespace(string $namespace, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '\\');
            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) return true;
        }

        return false;
    }

    private static function bodyHasTerms(string $body, string $group, array $config): bool
    {
        $terms = $config['business_terms'][$group] ?? [];
        return $terms && preg_match('/(?:' . implode('|', array_map(fn ($term) => preg_quote($term, '/'), $terms)) . ')/', $body);
    }

    private static function bodyNamedGateway(string $body, array $config): ?string
    {
        foreach ($config['named_gateways'] ?? [] as $gateway) {
            if (preg_match('/' . preg_quote($gateway, '/') . '/i', $body)) {
                return $gateway;
            }
        }

        return null;
    }

    private static function looksLikeModel(string $class, array $config): bool
    {
        return ! in_array(TextAnalyzer::baseName($class), $config['ignored_model_names'] ?? [], true);
    }

    private static function normalizeModelName(string $class, array $aliases): string
    {
        $class = trim($class, '\\');
        return isset($aliases[$class]) ? TextAnalyzer::baseName($aliases[$class]) : TextAnalyzer::baseName($class);
    }
}
