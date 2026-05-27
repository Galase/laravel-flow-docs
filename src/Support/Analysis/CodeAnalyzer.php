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
        $lines = explode("\n", $method['body']);
        foreach ($lines as $index => $line) {
            $trim = trim($line);
            $explanation = self::explainLine(self::analysisLine($lines, $index), $inferredVars);
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
        if (strpos($line, '//') === 0) {
            return 'Comentario de apoio do proprio codigo.';
        }

        $line = self::trimStatement($line);

        if ($explanation = self::explainArrayEntry($line, $inferredVars)) {
            return $explanation;
        }
        if (preg_match('/^return\s+(.+)$/s', $line, $match)) {
            return self::explainReturnExpression($match[1], $inferredVars);
        }
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$/s', $line, $match)) {
            return self::explainAssignment($match[1], $match[2], $inferredVars);
        }
        if ($explanation = self::explainStandaloneExpression($line, $inferredVars)) {
            return $explanation;
        }
        if (preg_match('/^if\s*\((.+)\)$/s', $line, $match)) {
            return 'Avalia se ' . self::describeCondition($match[1]) . ' para decidir o proximo caminho do fluxo.';
        }
        if (preg_match('/^elseif\s*\((.+)\)$/s', $line, $match)) {
            return 'Avalia a condicao alternativa em que ' . self::describeCondition($match[1]) . '.';
        }
        if (preg_match('/^else\b/', $line)) {
            return 'Executa o caminho alternativo quando as condicoes anteriores nao forem atendidas.';
        }
        if (preg_match('/^foreach\s*\((.+)\s+as\s+(.+)\)$/s', $line, $match)) {
            return 'Percorre ' . self::describeValue($match[1], $inferredVars) . ' e disponibiliza cada item como ' . trim($match[2]) . '.';
        }
        if (preg_match('/^(for|while)\s*\(/', $line)) {
            return 'Inicia uma repeticao controlada pela condicao informada.';
        }
        if (preg_match('/^try\b/', $line)) {
            return 'Inicia um bloco protegido para capturar falhas desta etapa.';
        }
        if (preg_match('/^catch\s*\(([^)]+)\)/', $line, $match)) {
            return 'Captura e trata a excecao ' . trim($match[1]) . ' gerada no bloco anterior.';
        }

        return null;
    }

    private static function analysisLine(array $lines, int $index): string
    {
        $line = trim($lines[$index] ?? '');
        if (! self::shouldCollectStatement($line)) {
            return $line;
        }

        $statement = $line;
        for ($i = $index + 1, $count = count($lines); $i < $count; $i++) {
            $next = trim($lines[$i]);
            if ($next === '') {
                continue;
            }
            $statement .= ' ' . $next;
            if (self::statementLooksComplete($next)) {
                break;
            }
        }

        return $statement;
    }

    private static function shouldCollectStatement(string $line): bool
    {
        if ($line === '' || self::statementLooksComplete($line) || str_ends_with($line, '[')) {
            return false;
        }

        return preg_match('/^(return\b|\$[A-Za-z_][A-Za-z0-9_]*\s*=|[A-Z][A-Za-z0-9_\\\\]*::|DB::table\()/', $line) === 1;
    }

    private static function statementLooksComplete(string $line): bool
    {
        return str_ends_with(rtrim($line), ';');
    }

    private static function explainArrayEntry(string $line, array $inferredVars): ?string
    {
        if (! preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*(.+)$/s', $line, $match)) {
            return null;
        }

        $key = $match[1];
        $value = self::trimStatement($match[2]);

        return 'Preenche o campo ' . $key . ' com ' . self::describeValue($value, $inferredVars) . '.';
    }

    private static function explainReturnExpression(string $expression, array $inferredVars): string
    {
        $expression = self::trimStatement($expression);
        if (preg_match('/^response\(\)->json\((.*)\)$/s', $expression, $match)) {
            return 'Devolve uma resposta JSON usando ' . self::describeValue($match[1], $inferredVars) . '.';
        }
        if (preg_match('/^view\((.*)\)$/s', $expression, $match)) {
            $args = TextAnalyzer::splitArguments($match[1]);
            $view = isset($args[0]) ? self::cleanArgument($args[0]) : 'a view informada';

            return 'Renderiza a view ' . $view . (isset($args[1]) ? ' com ' . self::describeValue($args[1], $inferredVars) : '') . '.';
        }

        return 'Retorna ' . self::describeValue($expression, $inferredVars) . ' para quem chamou o metodo.';
    }

    private static function explainAssignment(string $var, string $expression, array $inferredVars): string
    {
        $expression = self::trimStatement($expression);
        $description = 'Atribui a $' . $var . ' ' . self::describeValue($expression, $inferredVars) . '.';

        return self::appendInferredModel($description, $var, $inferredVars);
    }

    private static function explainStandaloneExpression(string $line, array $inferredVars): ?string
    {
        if ($explanation = self::explainStandaloneFluentChain($line)) {
            return $explanation;
        }
        if (preg_match('/^(?:\$this->[A-Za-z_][A-Za-z0-9_]*->|\$this->|self::|[A-Z][A-Za-z0-9_\\\\]*::)/', $line)) {
            return 'Executa ' . self::describeValue($line, $inferredVars) . '.';
        }

        return null;
    }

    private static function explainStandaloneFluentChain(string $line): ?string
    {
        if (! preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)(->.+)$/s', $line, $match)) {
            return null;
        }
        if ($match[1] === '$this') {
            return null;
        }

        $filters = [];
        $steps = [];
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)/s', $match[2], $calls, PREG_SET_ORDER);
        foreach ($calls as $call) {
            if ($filter = self::queryFilterDescription($call[1], TextAnalyzer::splitArguments($call[2]))) {
                $filters[] = $filter;
                continue;
            }
            $step = self::fluentOperationDescription($call[1], TextAnalyzer::splitArguments($call[2]));
            if ($step !== null) {
                $steps[] = $step;
            }
        }
        if ($filters && $steps === []) {
            return 'Aplica em ' . $match[1] . ' filtro em que ' . self::joinReadableParts($filters) . '.';
        }
        if ($filters) {
            array_unshift($steps, 'filtro em que ' . self::joinReadableParts($filters));
        }

        return $steps ? 'Atualiza ' . $match[1] . ' com ' . self::joinReadableParts($steps) . '.' : null;
    }

    private static function describeValue(string $expression, array $inferredVars): string
    {
        $expression = self::trimStatement($expression);
        if ($expression === '') {
            return 'o valor calculado nesta etapa';
        }
        if ($expression === '[') {
            return 'um array montado nas linhas seguintes';
        }
        if ($expression === '[]') {
            return 'um array vazio';
        }
        if ($query = self::describeQueryExpression($expression)) {
            return $query;
        }
        if ($chain = self::describeFluentChainExpression($expression)) {
            return $chain;
        }
        if (preg_match('/^\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return 'o retorno de $this->' . $match[1] . '->' . self::callSignature($match[2], $match[3]) . ', acessando a propriedade ' . $match[1] . ' da instancia atual';
        }
        if (preg_match('/^\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return 'o retorno de $this->' . self::callSignature($match[1], $match[2]) . ', executado na instancia atual';
        }
        if (preg_match('/^self::([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return 'o retorno de self::' . self::callSignature($match[1], $match[2]) . ', executado no proprio service/classe';
        }
        if (preg_match('/^([A-Z][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return 'o retorno de ' . TextAnalyzer::baseName($match[1]) . '::' . self::callSignature($match[2], $match[3]);
        }
        if (preg_match('/^new\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*\((.*)\)$/s', $expression, $match)) {
            return 'uma nova instancia de ' . TextAnalyzer::baseName($match[1]) . self::argumentsSuffix($match[2]);
        }
        if (preg_match('/^\[([^\]]*)\]$/s', $expression, $match)) {
            $keys = self::arrayKeys($match[1]);

            return $keys ? 'um array com os campos ' . implode(', ', array_slice($keys, 0, 8)) : 'um array montado nesta linha';
        }
        if (preg_match('/^compact\((.*)\)$/s', $expression, $match)) {
            $keys = array_map(fn (string $arg) => trim(self::cleanArgument($arg), '$'), TextAnalyzer::splitArguments($match[1]));

            return 'um array criado por compact() com ' . implode(', ', array_filter($keys));
        }
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $match)) {
            $var = $match[1];
            if (isset($inferredVars[$var])) {
                return 'o valor de $' . $var . ', tratado como ' . $inferredVars[$var]['model'] . ' pelas inferencias';
            }

            return 'o valor ja calculado em $' . $var;
        }
        if (preg_match('/^(true|false|null)$/i', $expression)) {
            return 'o valor fixo ' . strtolower($expression);
        }
        if (preg_match('/^[\'"].*[\'"]$/s', $expression)) {
            return 'o texto fixo ' . self::cleanArgument($expression);
        }
        if (is_numeric($expression)) {
            return 'o numero fixo ' . $expression;
        }

        return 'o resultado de ' . $expression;
    }

    private static function describeQueryExpression(string $expression): ?string
    {
        $expression = self::trimStatement($expression);
        if (preg_match('/^(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)(.*)$/s', $expression, $match)) {
            if (! self::isQueryMethod($match[2])) {
                return null;
            }

            $operations = self::queryOperations($match[2], $match[3], $match[4]);

            return 'o resultado de uma query em ' . $match[1] . ' que ' . self::describeQueryOperations($operations);
        }
        if (preg_match('/^DB::table\((.*?)\)(.*)$/s', $expression, $match)) {
            $operations = self::queryOperations('table', $match[1], $match[2]);

            return 'o resultado de uma query na tabela ' . self::cleanArgument($match[1]) . ' que ' . self::describeQueryOperations($operations);
        }

        return null;
    }

    private static function describeFluentChainExpression(string $expression): ?string
    {
        if (! preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)(->.+)$/s', $expression, $match)) {
            return null;
        }
        if ($match[1] === '$this') {
            return null;
        }

        $filters = [];
        $steps = [];
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)/s', $match[2], $calls, PREG_SET_ORDER);
        foreach ($calls as $call) {
            if ($filter = self::queryFilterDescription($call[1], TextAnalyzer::splitArguments($call[2]))) {
                $filters[] = $filter;
                continue;
            }
            $step = self::fluentOperationDescription($call[1], TextAnalyzer::splitArguments($call[2]));
            if ($step !== null) {
                $steps[] = $step;
            }
        }
        if ($filters) {
            array_unshift($steps, 'filtrado em que ' . self::joinReadableParts($filters));
        }

        return $steps ? $match[1] . ' ' . self::joinReadableParts($steps) : null;
    }

    private static function fluentOperationDescription(string $method, array $args): ?string
    {
        if ($method === 'orderBy' && isset($args[0])) {
            return 'ordenado por ' . self::cleanArgument($args[0]) . (isset($args[1]) ? ' ' . self::cleanArgument($args[1]) : '');
        }
        if ($method === 'latest') {
            return 'ordenado do mais recente' . (isset($args[0]) ? ' por ' . self::cleanArgument($args[0]) : '');
        }
        if ($method === 'oldest') {
            return 'ordenado do mais antigo' . (isset($args[0]) ? ' por ' . self::cleanArgument($args[0]) : '');
        }
        if ($method === 'paginate') {
            return 'paginado' . (isset($args[0]) ? ' por ' . self::cleanArgument($args[0]) : '');
        }
        if ($method === 'simplePaginate') {
            return 'paginado de forma simples' . (isset($args[0]) ? ' por ' . self::cleanArgument($args[0]) : '');
        }
        if ($method === 'withQueryString') {
            return 'com query string';
        }
        if ($method === 'appends' && isset($args[0])) {
            return 'com parametros de query adicionais de ' . self::cleanArgument($args[0]);
        }
        if ($method === 'select' && $args) {
            return 'selecionando ' . implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($args, 0, 6)));
        }
        if ($method === 'with' && $args) {
            return 'com relacoes ' . implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($args, 0, 6)));
        }
        if ($method === 'get') {
            return 'materializado como colecao';
        }
        if ($method === 'first') {
            return 'limitado ao primeiro resultado';
        }
        if ($method === 'count') {
            return 'contando o total';
        }
        if ($method === 'pluck' && isset($args[0])) {
            return 'extraindo ' . self::cleanArgument($args[0]);
        }

        return null;
    }

    private static function queryOperations(string $firstMethod, string $firstArgs, string $tail): array
    {
        $operations = [['method' => $firstMethod, 'args' => TextAnalyzer::splitArguments($firstArgs)]];
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)/s', $tail, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $operations[] = ['method' => $match[1], 'args' => TextAnalyzer::splitArguments($match[2])];
        }

        return $operations;
    }

    private static function describeQueryOperations(array $operations): string
    {
        $filters = [];
        $joins = [];
        $selects = [];
        $relations = [];
        $ordering = [];

        foreach ($operations as $operation) {
            $method = $operation['method'];
            $args = $operation['args'];
            if ($filter = self::queryFilterDescription($method, $args)) {
                $filters[] = $filter;
            }
            if (in_array($method, ['join', 'leftJoin', 'rightJoin'], true) && isset($args[0])) {
                $joins[] = self::cleanArgument($args[0]);
            }
            if ($method === 'select' && $args) {
                $selects[] = implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($args, 0, 6)));
            }
            if ($method === 'with' && $args) {
                $relations[] = implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($args, 0, 6)));
            }
            if ($method === 'orderBy' && isset($args[0])) {
                $ordering[] = self::cleanArgument($args[0]) . (isset($args[1]) ? ' ' . self::cleanArgument($args[1]) : '');
            }
        }

        $parts = [];
        if ($filters) {
            $parts[] = 'filtra registros em que ' . implode(' e ', array_slice($filters, 0, 4));
        }
        if ($joins) {
            $parts[] = 'relaciona com ' . implode(', ', array_slice($joins, 0, 4));
        }
        if ($selects) {
            $parts[] = 'seleciona ' . implode(', ', $selects);
        }
        if ($relations) {
            $parts[] = 'carrega as relacoes ' . implode(', ', $relations);
        }
        if ($ordering) {
            $parts[] = 'ordena por ' . implode(', ', $ordering);
        }

        $result = self::queryResultDescription($operations);
        if ($parts === []) {
            return $result;
        }

        return implode(', ', $parts) . ' e ' . $result;
    }

    private static function queryFilterDescription(string $method, array $args): ?string
    {
        if (in_array($method, ['where', 'orWhere'], true) && count($args) >= 2) {
            $field = self::cleanArgument($args[0]);
            if (count($args) === 2) {
                return $field . ' seja ' . self::cleanArgument($args[1]);
            }

            return $field . ' ' . self::describeOperator(self::cleanArgument($args[1]), self::cleanArgument($args[2]));
        }
        if (in_array($method, ['whereIn', 'whereNotIn'], true) && count($args) >= 2) {
            return self::cleanArgument($args[0]) . ($method === 'whereIn' ? ' esteja em ' : ' nao esteja em ') . self::cleanArgument($args[1]);
        }
        if (in_array($method, ['whereBetween', 'whereNotBetween'], true) && count($args) >= 2) {
            return self::cleanArgument($args[0]) . ($method === 'whereBetween' ? ' esteja entre ' : ' nao esteja entre ') . self::cleanArgument($args[1]);
        }
        if (in_array($method, ['whereNull', 'whereNotNull'], true) && isset($args[0])) {
            return self::cleanArgument($args[0]) . ($method === 'whereNull' ? ' seja nulo' : ' nao seja nulo');
        }
        if ($method === 'whereColumn' && count($args) >= 2) {
            if (count($args) === 2) {
                return self::cleanArgument($args[0]) . ' seja igual a coluna ' . self::cleanArgument($args[1]);
            }

            return self::cleanArgument($args[0]) . ' ' . self::describeOperator(self::cleanArgument($args[1]), 'coluna ' . self::cleanArgument($args[2]));
        }
        if ($method === 'whereDate' && count($args) >= 2) {
            return 'a data ' . self::cleanArgument($args[0]) . ' seja ' . self::cleanArgument(end($args) ?: '');
        }
        if (in_array($method, ['whereYear', 'whereMonth', 'whereDay', 'whereTime'], true) && count($args) >= 2) {
            $unit = match ($method) {
                'whereYear' => 'ano',
                'whereMonth' => 'mes',
                'whereDay' => 'dia',
                default => 'horario',
            };

            return $unit . ' de ' . self::cleanArgument($args[0]) . ' seja ' . self::cleanArgument(end($args) ?: '');
        }
        if ($method === 'whereJsonContains' && count($args) >= 2) {
            return self::cleanArgument($args[0]) . ' contenha ' . self::cleanArgument($args[1]);
        }
        if (in_array($method, ['whereHas', 'orWhereHas'], true) && isset($args[0])) {
            return 'a relacao ' . self::cleanArgument($args[0]) . ' exista e atenda ao filtro informado';
        }
        if (in_array($method, ['whereDoesntHave', 'orWhereDoesntHave', 'doesntHave'], true) && isset($args[0])) {
            return 'a relacao ' . self::cleanArgument($args[0]) . ' nao exista';
        }
        if ($method === 'has' && isset($args[0])) {
            return 'a relacao ' . self::cleanArgument($args[0]) . ' exista';
        }
        if ($method === 'whereRelation' && count($args) >= 3) {
            return 'a relacao ' . self::cleanArgument($args[0]) . ' tenha ' . self::cleanArgument($args[1]) . ' ' . self::describeOperator(self::cleanArgument($args[2]), self::cleanArgument($args[3] ?? ''));
        }
        if ($method === 'find' || $method === 'findOrFail') {
            return 'id seja ' . self::cleanArgument($args[0] ?? '');
        }

        return null;
    }

    private static function describeOperator(string $operator, string $value): string
    {
        return match (strtolower($operator)) {
            '=', '==' => 'seja ' . $value,
            '!=', '<>' => 'seja diferente de ' . $value,
            '>' => 'seja maior que ' . $value,
            '>=' => 'seja maior ou igual a ' . $value,
            '<' => 'seja menor que ' . $value,
            '<=' => 'seja menor ou igual a ' . $value,
            'like', 'ilike' => 'corresponda a ' . $value,
            'not like', 'not ilike' => 'nao corresponda a ' . $value,
            default => $operator . ' ' . $value,
        };
    }

    private static function queryResultDescription(array $operations): string
    {
        $methods = array_map(fn (array $operation) => $operation['method'], $operations);
        if (in_array('count', $methods, true)) {
            return 'conta o total encontrado';
        }
        if (in_array('exists', $methods, true)) {
            return 'verifica se existe algum registro encontrado';
        }
        if (in_array('first', $methods, true) || in_array('find', $methods, true) || in_array('findOrFail', $methods, true)) {
            return 'retorna um unico registro encontrado';
        }
        if (in_array('paginate', $methods, true)) {
            return 'retorna resultados paginados';
        }
        if (in_array('pluck', $methods, true)) {
            return 'extrai uma lista de valores';
        }
        if (in_array('sum', $methods, true) || in_array('avg', $methods, true) || in_array('min', $methods, true) || in_array('max', $methods, true)) {
            return 'calcula um valor agregado';
        }
        if (in_array('create', $methods, true) || in_array('insert', $methods, true)) {
            return 'cria registros no banco';
        }
        if (in_array('update', $methods, true)) {
            return 'atualiza os registros encontrados';
        }
        if (in_array('delete', $methods, true) || in_array('destroy', $methods, true)) {
            return 'remove os registros encontrados';
        }
        if (in_array('get', $methods, true)) {
            return 'retorna a colecao encontrada';
        }

        return 'monta a consulta para uso posterior';
    }

    private static function joinReadableParts(array $parts): string
    {
        $parts = array_values(array_filter($parts));
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        return implode(', ', array_slice($parts, 0, -1)) . ' e ' . end($parts);
    }

    private static function describeCondition(string $condition): string
    {
        $condition = trim($condition);
        $replacements = [
            '===' => 'seja exatamente',
            '!==' => 'seja diferente de',
            '==' => 'seja igual a',
            '!=' => 'seja diferente de',
            '>=' => 'seja maior ou igual a',
            '<=' => 'seja menor ou igual a',
            '&&' => 'e',
            '||' => 'ou',
            '!' => 'nao',
        ];

        return trim(str_replace(array_keys($replacements), array_values($replacements), $condition));
    }

    private static function appendInferredModel(string $description, string $var, array $inferredVars): string
    {
        if (! isset($inferredVars[$var])) {
            return $description;
        }

        return rtrim($description, '.') . '; o valor e tratado como ' . $inferredVars[$var]['model'] . ' pelas inferencias.';
    }

    private static function arrayKeys(string $items): array
    {
        $keys = [];
        foreach (TextAnalyzer::splitArguments($items) as $item) {
            if (preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>/', $item, $match)) {
                $keys[] = $match[1];
            }
        }

        return $keys;
    }

    private static function callSignature(string $method, string $args): string
    {
        $arguments = array_map(fn (string $arg) => self::cleanArgument($arg), TextAnalyzer::splitArguments($args));

        return $method . '(' . implode(', ', array_slice($arguments, 0, 4)) . ')';
    }

    private static function argumentsSuffix(string $args): string
    {
        $arguments = TextAnalyzer::splitArguments($args);

        return $arguments ? ' com ' . implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($arguments, 0, 4))) : '';
    }

    private static function cleanArgument(string $argument): string
    {
        $argument = self::trimStatement($argument);
        $argument = trim($argument, " \t\n\r\0\x0B");
        if (preg_match('/^[\'"](.*)[\'"]$/s', $argument, $match)) {
            return $match[1];
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_\\\\]+)::class$/', $argument, $match)) {
            return TextAnalyzer::baseName($match[1]);
        }
        if (strlen($argument) > 80) {
            return substr($argument, 0, 77) . '...';
        }

        return $argument;
    }

    private static function trimStatement(string $value): string
    {
        $value = trim($value);
        while ($value !== '' && (str_ends_with($value, ';') || str_ends_with($value, ','))) {
            $value = trim(substr($value, 0, -1));
        }

        return $value;
    }

    private static function isQueryMethod(string $method): bool
    {
        return in_array($method, [
            'query',
            'where',
            'orWhere',
            'whereIn',
            'whereNotIn',
            'whereNull',
            'whereNotNull',
            'whereDate',
            'find',
            'findOrFail',
            'first',
            'create',
            'insert',
            'update',
            'delete',
            'destroy',
            'select',
            'with',
            'join',
            'leftJoin',
            'rightJoin',
            'orderBy',
            'groupBy',
            'get',
            'paginate',
            'pluck',
            'count',
            'exists',
            'sum',
            'avg',
            'min',
            'max',
        ], true);
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
