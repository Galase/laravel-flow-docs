<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Analysis;

use Galase\FlowDocs\Support\I18n\Translator;

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

    public static function dependencyInjections(array $class, array $config = []): array
    {
        $translator = Translator::fromConfig($config);
        $dependencies = [];
        foreach ($class['methods'] as $method) {
            foreach (self::signatureParameters($method['signature']) as $parameter) {
                if ($parameter['type'] === '') {
                    continue;
                }
                $where = $method['name'] === '__construct'
                    ? $translator->t('analysis.dependency_constructor')
                    : $translator->t('analysis.dependency_method', ['method' => $method['name']]);
                if ($method['name'] === '__construct' && preg_match('/(public|protected|private)\s+[^$]*\$' . preg_quote($parameter['name'], '/') . '\b/', $parameter['raw'])) {
                    $where .= $translator->t('analysis.dependency_promoted_property');
                } elseif ($method['name'] === '__construct' && preg_match('/\$this->[A-Za-z_][A-Za-z0-9_]*\s*=\s*\$' . preg_quote($parameter['name'], '/') . '\b/', $method['body'])) {
                    $where .= $translator->t('analysis.dependency_assigned_property');
                }
                $dependencies[] = ['type' => $parameter['type'], 'name' => $parameter['name'], 'where' => $where];
            }
        }

        return array_values(array_unique($dependencies, SORT_REGULAR));
    }

    public static function modelRelations(array $model, array $config): array
    {
        $translator = Translator::fromConfig($config);
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
                'target' => $target !== '' ? $target : $translator->t('analysis.dynamic_relation_target'),
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

    public static function inferJoins(array $classes, array $config = []): array
    {
        $translator = Translator::fromConfig($config);
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
                        'base_table' => $baseTable ?? $translator->t('analysis.origin_query_model'),
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

    public static function inferVariableModels(array $method, array $class, array $returnIndex, array $config = []): array
    {
        $translator = Translator::fromConfig($config);
        $vars = [];
        $body = $method['body'];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::(?:where|query|find|findOrFail|first|create|select|with|join)\b/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $vars[$match[1]] = ['model' => $match[2], 'source' => $translator->t('analysis.source_direct_query', ['model' => $match[2]])];
        }
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $class['fqcn'] . '@' . $match[2];
            if (! empty($returnIndex[$key])) {
                $vars[$match[1]] = ['model' => implode('|', $returnIndex[$key]), 'source' => $translator->t('analysis.source_this_return', ['method' => $match[2]])];
            }
        }
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*self::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $class['fqcn'] . '@' . $match[2];
            if (! empty($returnIndex[$key])) {
                $vars[$match[1]] = ['model' => implode('|', $returnIndex[$key]), 'source' => $translator->t('analysis.source_self_return', ['method' => $match[2]])];
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
        foreach (self::inferVariableModels($method, $class, $returnIndex, $config) as $inferred) {
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
        $translator = Translator::fromConfig($config);
        $rules = [
            '/DB::|->join\(|->select\(|->where\(|->whereIn\(|->groupBy\(|->orderBy\(/' => 'analysis.action_query',
            '/::create\(|->save\(|->update\(|::insert\(|->delete\(|::destroy\(/' => 'analysis.action_write',
            '/return\s+response\(\)->json|return\s+\[|json_encode\(/' => 'analysis.action_response',
            '/view\(|compact\(/' => 'analysis.action_view',
            '/Excel::|download\(|Storage::|Upload|Arquivo|file|pdf|PDF|streamDownload/' => 'analysis.action_file',
            '/Mail::|Notification|dispatch\(|Job|event\(/' => 'analysis.action_async',
            '/Auth::|auth\(\)|hashAccess|account_id|user_id/' => 'analysis.action_auth',
            '/try\s*\{|catch\s*\(/' => 'analysis.action_try',
            '/foreach\s*\(|for\s*\(|while\s*\(/' => 'analysis.action_loop',
            '/if\s*\(|switch\s*\(/' => 'analysis.action_condition',
            '/Carbon|now\(\)|date\(|strtotime\(/' => 'analysis.action_date',
        ];
        $actions = [];
        foreach ($rules as $regex => $label) {
            if (preg_match($regex, $body)) {
                $actions[] = $translator->t($label);
            }
        }
        if (self::bodyHasTerms($body, 'payload', $config) || preg_match('/data\s*=\s*\[/', $body)) {
            $actions[] = $translator->t('analysis.action_payload');
        }
        if (self::bodyHasTerms($body, 'gateway', $config) || self::bodyNamedGateway($body, $config)) {
            $actions[] = $translator->t('analysis.action_gateway');
        }

        return array_values(array_unique($actions)) ?: [$translator->t('analysis.action_default')];
    }

    public static function explicitPurpose(array $method, array $models, array $inferredVars, array $config): string
    {
        $translator = Translator::fromConfig($config);
        $name = $method['name'];
        $body = $method['body'];
        $subject = TextAnalyzer::humanize($name);
        $gateway = self::bodyNamedGateway($body, $config);
        if ($gateway && (self::bodyHasTerms($body, 'payload', $config) || preg_match('/\[[^\]]*=>/s', $body))) {
            return $translator->t('analysis.purpose_gateway_payload', ['method' => $name, 'gateway' => $gateway]);
        }
        if (self::bodyHasTerms($body, 'gateway', $config) && (self::bodyHasTerms($body, 'payload', $config) || preg_match('/\[[^\]]*=>/s', $body))) {
            return $translator->t('analysis.purpose_gateway_payload_generic', ['method' => $name]);
        }
        if (preg_match('/Excel::|download\(|export|Export/', $body)) {
            return $translator->t('analysis.purpose_export', ['method' => $name, 'subject' => $subject]);
        }
        if (preg_match('/::create\(|->save\(|::insert\(/', $body)) {
            return $translator->t('analysis.purpose_create', ['method' => $name, 'models' => $models ? $translator->t('analysis.models_in', ['models' => implode(', ', array_slice($models, 0, 3))]) : '']);
        }
        if (preg_match('/->update\(|::update\(/', $body)) {
            return $translator->t('analysis.purpose_update', ['method' => $name, 'models' => $models ? $translator->t('analysis.models_in', ['models' => implode(', ', array_slice($models, 0, 3))]) : '', 'subject' => $subject]);
        }
        if (preg_match('/->where\(|::where\(|DB::|->join\(/', $body)) {
            return $translator->t('analysis.purpose_query', ['method' => $name, 'models' => $models ? $translator->t('analysis.models_from', ['models' => implode(', ', array_slice($models, 0, 3))]) : '', 'subject' => $subject]);
        }
        if ($inferredVars) {
            $items = [];
            foreach ($inferredVars as $var => $meta) {
                $items[] = $translator->t('analysis.as_model', ['var' => $var, 'model' => $meta['model']]);
            }
            return $translator->t('analysis.purpose_inferred', ['method' => $name, 'items' => implode(', ', array_slice($items, 0, 4))]);
        }
        if ($models) {
            return $translator->t('analysis.purpose_models', ['method' => $name, 'subject' => $subject, 'models' => implode(', ', array_slice($models, 0, 4))]);
        }

        return $translator->t('analysis.purpose_default', ['method' => $name, 'subject' => $subject]);
    }

    public static function lineExplanations(array $method, array $inferredVars, array $config = []): array
    {
        $translator = Translator::fromConfig($config);
        $rows = [];
        $lines = explode("\n", $method['body']);
        foreach ($lines as $index => $line) {
            $trim = trim($line);
            $explanation = self::explainLine(self::analysisLine($lines, $index), $inferredVars, $translator);
            if (! $explanation) {
                continue;
            }
            $rows[] = ['line' => $method['startLine'] + $index + 1, 'code' => strlen($trim) > 190 ? substr($trim, 0, 187) . '...' : $trim, 'explanation' => $explanation];
            if (count($rows) >= 45) {
                $rows[] = ['line' => '', 'code' => '...', 'explanation' => $translator->t('analysis.long_method')];
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

    public static function detectCalls(string $body, array $config = []): array
    {
        $translator = Translator::fromConfig($config);
        $calls = [];
        preg_match_all('/new\s+([A-Z][A-Za-z0-9_\\\\]+)\s*\(/', $body, $matches);
        foreach ($matches[1] ?? [] as $call) {
            $calls[] = $translator->t('analysis.call_new_instance', ['class' => $call]);
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

    private static function explainLine(string $line, array $inferredVars, Translator $translator): ?string
    {
        if ($line === '' || $line === '{' || $line === '}') {
            return null;
        }
        if (strpos($line, '//') === 0) {
            return $translator->t('analysis.own_comment');
        }

        $line = self::trimStatement($line);

        if ($explanation = self::explainArrayEntry($line, $inferredVars, $translator)) {
            return $explanation;
        }
        if (preg_match('/^return\s+(.+)$/s', $line, $match)) {
            return self::explainReturnExpression($match[1], $inferredVars, $translator);
        }
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$/s', $line, $match)) {
            return self::explainAssignment($match[1], $match[2], $inferredVars, $translator);
        }
        if ($explanation = self::explainStandaloneExpression($line, $inferredVars, $translator)) {
            return $explanation;
        }
        if (preg_match('/^if\s*\((.+)\)$/s', $line, $match)) {
            return $translator->t('analysis.if', ['condition' => self::describeCondition($match[1], $translator)]);
        }
        if (preg_match('/^elseif\s*\((.+)\)$/s', $line, $match)) {
            return $translator->t('analysis.elseif', ['condition' => self::describeCondition($match[1], $translator)]);
        }
        if (preg_match('/^else\b/', $line)) {
            return $translator->t('analysis.else');
        }
        if (preg_match('/^foreach\s*\((.+)\s+as\s+(.+)\)$/s', $line, $match)) {
            return $translator->t('analysis.foreach', ['source' => self::describeValue($match[1], $inferredVars, $translator), 'target' => trim($match[2])]);
        }
        if (preg_match('/^(for|while)\s*\(/', $line)) {
            return $translator->t('analysis.loop');
        }
        if (preg_match('/^try\b/', $line)) {
            return $translator->t('analysis.try');
        }
        if (preg_match('/^catch\s*\(([^)]+)\)/', $line, $match)) {
            return $translator->t('analysis.catch', ['exception' => trim($match[1])]);
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

    private static function explainArrayEntry(string $line, array $inferredVars, Translator $translator): ?string
    {
        if (! preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*(.+)$/s', $line, $match)) {
            return null;
        }

        $key = $match[1];
        $value = self::trimStatement($match[2]);

        return $translator->t('analysis.array_entry', ['key' => $key, 'value' => self::describeValue($value, $inferredVars, $translator)]);
    }

    private static function explainReturnExpression(string $expression, array $inferredVars, Translator $translator): string
    {
        $expression = self::trimStatement($expression);
        if (preg_match('/^response\(\)->json\((.*)\)$/s', $expression, $match)) {
            return $translator->t('analysis.return_json', ['value' => self::describeValue($match[1], $inferredVars, $translator)]);
        }
        if (preg_match('/^view\((.*)\)$/s', $expression, $match)) {
            $args = TextAnalyzer::splitArguments($match[1]);
            $view = isset($args[0]) ? self::cleanArgument($args[0]) : $translator->t('analysis.given_view');

            return $translator->t('analysis.render_view', ['view' => $view, 'with' => isset($args[1]) ? $translator->t('analysis.with_value', ['value' => self::describeValue($args[1], $inferredVars, $translator)]) : '']);
        }

        return $translator->t('analysis.return_value', ['value' => self::describeValue($expression, $inferredVars, $translator)]);
    }

    private static function explainAssignment(string $var, string $expression, array $inferredVars, Translator $translator): string
    {
        $expression = self::trimStatement($expression);
        $description = $translator->t('analysis.assign_value', ['var' => $var, 'value' => self::describeValue($expression, $inferredVars, $translator)]);

        return self::appendInferredModel($description, $var, $inferredVars, $translator);
    }

    private static function explainStandaloneExpression(string $line, array $inferredVars, Translator $translator): ?string
    {
        if ($explanation = self::explainStandaloneFluentChain($line, $translator)) {
            return $explanation;
        }
        if (preg_match('/^(?:\$this->[A-Za-z_][A-Za-z0-9_]*->|\$this->|self::|[A-Z][A-Za-z0-9_\\\\]*::)/', $line)) {
            return $translator->t('analysis.standalone_execute', ['value' => self::describeValue($line, $inferredVars, $translator)]);
        }

        return null;
    }

    private static function explainStandaloneFluentChain(string $line, Translator $translator): ?string
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
            if ($filter = self::queryFilterDescription($call[1], TextAnalyzer::splitArguments($call[2]), $translator)) {
                $filters[] = $filter;
                continue;
            }
            $step = self::fluentOperationDescription($call[1], TextAnalyzer::splitArguments($call[2]), $translator);
            if ($step !== null) {
                $steps[] = $step;
            }
        }
        if ($filters && $steps === []) {
            return $translator->t('analysis.apply_filter', ['target' => $match[1], 'filters' => self::joinReadableParts($filters, $translator)]);
        }
        if ($filters) {
            array_unshift($steps, $translator->t('analysis.fluent_filter_short', ['filters' => self::joinReadableParts($filters, $translator)]));
        }

        return $steps ? $translator->t('analysis.update_chain', ['target' => $match[1], 'steps' => self::joinReadableParts($steps, $translator)]) : null;
    }

    private static function describeValue(string $expression, array $inferredVars, Translator $translator): string
    {
        $expression = self::trimStatement($expression);
        if ($expression === '') {
            return $translator->t('analysis.empty_value');
        }
        if ($expression === '[') {
            return $translator->t('analysis.array_next_lines');
        }
        if ($expression === '[]') {
            return $translator->t('analysis.empty_array');
        }
        if ($query = self::describeQueryExpression($expression, $translator)) {
            return $query;
        }
        if ($chain = self::describeFluentChainExpression($expression, $translator)) {
            return $chain;
        }
        if (preg_match('/^\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return $translator->t('analysis.this_property_call', ['property' => $match[1], 'call' => self::callSignature($match[2], $match[3])]);
        }
        if (preg_match('/^\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return $translator->t('analysis.this_call', ['call' => self::callSignature($match[1], $match[2])]);
        }
        if (preg_match('/^self::([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return $translator->t('analysis.self_call', ['call' => self::callSignature($match[1], $match[2])]);
        }
        if (preg_match('/^([A-Z][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expression, $match)) {
            return $translator->t('analysis.static_call', ['class' => TextAnalyzer::baseName($match[1]), 'call' => self::callSignature($match[2], $match[3])]);
        }
        if (preg_match('/^new\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*\((.*)\)$/s', $expression, $match)) {
            return $translator->t('analysis.new_instance', ['class' => TextAnalyzer::baseName($match[1]), 'args' => self::argumentsSuffix($match[2], $translator)]);
        }
        if (preg_match('/^\[([^\]]*)\]$/s', $expression, $match)) {
            $keys = self::arrayKeys($match[1]);

            return $keys ? $translator->t('analysis.array_fields', ['fields' => implode(', ', array_slice($keys, 0, 8))]) : $translator->t('analysis.array_inline');
        }
        if (preg_match('/^compact\((.*)\)$/s', $expression, $match)) {
            $keys = array_map(fn (string $arg) => trim(self::cleanArgument($arg), '$'), TextAnalyzer::splitArguments($match[1]));

            return $translator->t('analysis.compact', ['keys' => implode(', ', array_filter($keys))]);
        }
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $match)) {
            $var = $match[1];
            if (isset($inferredVars[$var])) {
                return $translator->t('analysis.inferred_var_value', ['var' => $var, 'model' => $inferredVars[$var]['model']]);
            }

            return $translator->t('analysis.var_value', ['var' => $var]);
        }
        if (preg_match('/^(true|false|null)$/i', $expression)) {
            return $translator->t('analysis.fixed_value', ['value' => strtolower($expression)]);
        }
        if (preg_match('/^[\'"].*[\'"]$/s', $expression)) {
            return $translator->t('analysis.fixed_text', ['value' => self::cleanArgument($expression)]);
        }
        if (is_numeric($expression)) {
            return $translator->t('analysis.fixed_number', ['value' => $expression]);
        }

        return $translator->t('analysis.expression_result', ['expression' => $expression]);
    }

    private static function describeQueryExpression(string $expression, Translator $translator): ?string
    {
        $expression = self::trimStatement($expression);
        if (preg_match('/^(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?([A-Z][A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)(.*)$/s', $expression, $match)) {
            if (! self::isQueryMethod($match[2])) {
                return null;
            }

            $operations = self::queryOperations($match[2], $match[3], $match[4]);

            return $translator->t('analysis.query_model', ['model' => $match[1], 'operations' => self::describeQueryOperations($operations, $translator)]);
        }
        if (preg_match('/^DB::table\((.*?)\)(.*)$/s', $expression, $match)) {
            $operations = self::queryOperations('table', $match[1], $match[2]);

            return $translator->t('analysis.query_table', ['table' => self::cleanArgument($match[1]), 'operations' => self::describeQueryOperations($operations, $translator)]);
        }

        return null;
    }

    private static function describeFluentChainExpression(string $expression, Translator $translator): ?string
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
            if ($filter = self::queryFilterDescription($call[1], TextAnalyzer::splitArguments($call[2]), $translator)) {
                $filters[] = $filter;
                continue;
            }
            $step = self::fluentOperationDescription($call[1], TextAnalyzer::splitArguments($call[2]), $translator);
            if ($step !== null) {
                $steps[] = $step;
            }
        }
        if ($filters) {
            array_unshift($steps, $translator->t('analysis.fluent_filter', ['filters' => self::joinReadableParts($filters, $translator)]));
        }

        return $steps ? $match[1] . ' ' . self::joinReadableParts($steps, $translator) : null;
    }

    private static function fluentOperationDescription(string $method, array $args, Translator $translator): ?string
    {
        if ($method === 'orderBy' && isset($args[0])) {
            return $translator->t('analysis.fluent_order_by', ['field' => self::cleanArgument($args[0]), 'direction' => isset($args[1]) ? ' ' . self::cleanArgument($args[1]) : '']);
        }
        if ($method === 'latest') {
            return $translator->t('analysis.fluent_latest', ['field' => isset($args[0]) ? $translator->t('analysis.by_argument', ['value' => self::cleanArgument($args[0])]) : '']);
        }
        if ($method === 'oldest') {
            return $translator->t('analysis.fluent_oldest', ['field' => isset($args[0]) ? $translator->t('analysis.by_argument', ['value' => self::cleanArgument($args[0])]) : '']);
        }
        if ($method === 'paginate') {
            return $translator->t('analysis.fluent_paginated', ['per_page' => isset($args[0]) ? $translator->t('analysis.by_argument', ['value' => self::cleanArgument($args[0])]) : '']);
        }
        if ($method === 'simplePaginate') {
            return $translator->t('analysis.fluent_simple_paginated', ['per_page' => isset($args[0]) ? $translator->t('analysis.by_argument', ['value' => self::cleanArgument($args[0])]) : '']);
        }
        if ($method === 'withQueryString') {
            return $translator->t('analysis.fluent_with_query_string');
        }
        if ($method === 'appends' && isset($args[0])) {
            return $translator->t('analysis.fluent_appends', ['value' => self::cleanArgument($args[0])]);
        }
        if ($method === 'select' && $args) {
            return $translator->t('analysis.fluent_select', ['fields' => implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($args, 0, 6)))]);
        }
        if ($method === 'with' && $args) {
            return $translator->t('analysis.fluent_with', ['relations' => implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($args, 0, 6)))]);
        }
        if ($method === 'get') {
            return $translator->t('analysis.fluent_get');
        }
        if ($method === 'first') {
            return $translator->t('analysis.fluent_first');
        }
        if ($method === 'count') {
            return $translator->t('analysis.fluent_count');
        }
        if ($method === 'pluck' && isset($args[0])) {
            return $translator->t('analysis.fluent_pluck', ['field' => self::cleanArgument($args[0])]);
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

    private static function describeQueryOperations(array $operations, Translator $translator): string
    {
        $filters = [];
        $joins = [];
        $selects = [];
        $relations = [];
        $ordering = [];

        foreach ($operations as $operation) {
            $method = $operation['method'];
            $args = $operation['args'];
            if ($filter = self::queryFilterDescription($method, $args, $translator)) {
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
            $parts[] = $translator->t('analysis.filter_records', ['filters' => self::joinReadableParts(array_slice($filters, 0, 4), $translator)]);
        }
        if ($joins) {
            $parts[] = $translator->t('analysis.join_tables', ['tables' => implode(', ', array_slice($joins, 0, 4))]);
        }
        if ($selects) {
            $parts[] = $translator->t('analysis.select_fields', ['fields' => implode(', ', $selects)]);
        }
        if ($relations) {
            $parts[] = $translator->t('analysis.load_relations', ['relations' => implode(', ', $relations)]);
        }
        if ($ordering) {
            $parts[] = $translator->t('analysis.order_by', ['fields' => implode(', ', $ordering)]);
        }

        $result = self::queryResultDescription($operations, $translator);
        if ($parts === []) {
            return $result;
        }

        return implode(', ', $parts) . ' ' . $translator->t('common.and') . ' ' . $result;
    }

    private static function queryFilterDescription(string $method, array $args, Translator $translator): ?string
    {
        if (in_array($method, ['where', 'orWhere'], true) && count($args) >= 2) {
            $field = self::cleanArgument($args[0]);
            if (count($args) === 2) {
                return $translator->t('analysis.filter_equals', ['field' => $field, 'value' => self::cleanArgument($args[1])]);
            }

            return $field . ' ' . self::describeOperator(self::cleanArgument($args[1]), self::cleanArgument($args[2]), $translator);
        }
        if (in_array($method, ['whereIn', 'whereNotIn'], true) && count($args) >= 2) {
            return $translator->t($method === 'whereIn' ? 'analysis.filter_in' : 'analysis.filter_not_in', ['field' => self::cleanArgument($args[0]), 'value' => self::cleanArgument($args[1])]);
        }
        if (in_array($method, ['whereBetween', 'whereNotBetween'], true) && count($args) >= 2) {
            return $translator->t($method === 'whereBetween' ? 'analysis.filter_between' : 'analysis.filter_not_between', ['field' => self::cleanArgument($args[0]), 'value' => self::cleanArgument($args[1])]);
        }
        if (in_array($method, ['whereNull', 'whereNotNull'], true) && isset($args[0])) {
            return $translator->t($method === 'whereNull' ? 'analysis.filter_null' : 'analysis.filter_not_null', ['field' => self::cleanArgument($args[0])]);
        }
        if ($method === 'whereColumn' && count($args) >= 2) {
            if (count($args) === 2) {
                return $translator->t('analysis.filter_column_equals', ['field' => self::cleanArgument($args[0]), 'value' => self::cleanArgument($args[1])]);
            }

            return self::cleanArgument($args[0]) . ' ' . self::describeOperator(self::cleanArgument($args[1]), $translator->t('analysis.column_value', ['value' => self::cleanArgument($args[2])]), $translator);
        }
        if ($method === 'whereDate' && count($args) >= 2) {
            return $translator->t('analysis.filter_date', ['field' => self::cleanArgument($args[0]), 'value' => self::cleanArgument(end($args) ?: '')]);
        }
        if (in_array($method, ['whereYear', 'whereMonth', 'whereDay', 'whereTime'], true) && count($args) >= 2) {
            $unit = match ($method) {
                'whereYear' => $translator->t('analysis.unit_year'),
                'whereMonth' => $translator->t('analysis.unit_month'),
                'whereDay' => $translator->t('analysis.unit_day'),
                default => $translator->t('analysis.unit_time'),
            };

            return $translator->t('analysis.filter_date_part', ['unit' => $unit, 'field' => self::cleanArgument($args[0]), 'value' => self::cleanArgument(end($args) ?: '')]);
        }
        if ($method === 'whereJsonContains' && count($args) >= 2) {
            return $translator->t('analysis.filter_json_contains', ['field' => self::cleanArgument($args[0]), 'value' => self::cleanArgument($args[1])]);
        }
        if (in_array($method, ['whereHas', 'orWhereHas'], true) && isset($args[0])) {
            return $translator->t('analysis.filter_relation_has', ['relation' => self::cleanArgument($args[0])]);
        }
        if (in_array($method, ['whereDoesntHave', 'orWhereDoesntHave', 'doesntHave'], true) && isset($args[0])) {
            return $translator->t('analysis.filter_relation_missing', ['relation' => self::cleanArgument($args[0])]);
        }
        if ($method === 'has' && isset($args[0])) {
            return $translator->t('analysis.filter_relation_exists', ['relation' => self::cleanArgument($args[0])]);
        }
        if ($method === 'whereRelation' && count($args) >= 3) {
            return $translator->t('analysis.filter_relation_field', ['relation' => self::cleanArgument($args[0]), 'field' => self::cleanArgument($args[1]), 'comparison' => self::describeOperator(self::cleanArgument($args[2]), self::cleanArgument($args[3] ?? ''), $translator)]);
        }
        if ($method === 'find' || $method === 'findOrFail') {
            return $translator->t('analysis.filter_id', ['value' => self::cleanArgument($args[0] ?? '')]);
        }

        return null;
    }

    private static function describeOperator(string $operator, string $value, Translator $translator): string
    {
        return match (strtolower($operator)) {
            '=', '==' => $translator->t('analysis.op_eq', ['value' => $value]),
            '!=', '<>' => $translator->t('analysis.op_ne', ['value' => $value]),
            '>' => $translator->t('analysis.op_gt', ['value' => $value]),
            '>=' => $translator->t('analysis.op_gte', ['value' => $value]),
            '<' => $translator->t('analysis.op_lt', ['value' => $value]),
            '<=' => $translator->t('analysis.op_lte', ['value' => $value]),
            'like', 'ilike' => $translator->t('analysis.op_like', ['value' => $value]),
            'not like', 'not ilike' => $translator->t('analysis.op_not_like', ['value' => $value]),
            default => $operator . ' ' . $value,
        };
    }

    private static function queryResultDescription(array $operations, Translator $translator): string
    {
        $methods = array_map(fn (array $operation) => $operation['method'], $operations);
        if (in_array('count', $methods, true)) {
            return $translator->t('analysis.query_counts');
        }
        if (in_array('exists', $methods, true)) {
            return $translator->t('analysis.query_exists');
        }
        if (in_array('first', $methods, true) || in_array('find', $methods, true) || in_array('findOrFail', $methods, true)) {
            return $translator->t('analysis.query_first');
        }
        if (in_array('paginate', $methods, true)) {
            return $translator->t('analysis.query_paginated');
        }
        if (in_array('pluck', $methods, true)) {
            return $translator->t('analysis.query_pluck');
        }
        if (in_array('sum', $methods, true) || in_array('avg', $methods, true) || in_array('min', $methods, true) || in_array('max', $methods, true)) {
            return $translator->t('analysis.query_aggregate');
        }
        if (in_array('create', $methods, true) || in_array('insert', $methods, true)) {
            return $translator->t('analysis.query_create');
        }
        if (in_array('update', $methods, true)) {
            return $translator->t('analysis.query_update');
        }
        if (in_array('delete', $methods, true) || in_array('destroy', $methods, true)) {
            return $translator->t('analysis.query_delete');
        }
        if (in_array('get', $methods, true)) {
            return $translator->t('analysis.query_get');
        }

        return $translator->t('analysis.query_builds');
    }

    private static function joinReadableParts(array $parts, ?Translator $translator = null): string
    {
        $translator ??= new Translator();
        $parts = array_values(array_filter($parts));
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        return implode(', ', array_slice($parts, 0, -1)) . ' ' . $translator->t('common.and') . ' ' . end($parts);
    }

    private static function describeCondition(string $condition, Translator $translator): string
    {
        $condition = trim($condition);
        $replacements = [
            '===' => $translator->t('analysis.condition_exact'),
            '!==' => $translator->t('analysis.condition_not_exact'),
            '==' => $translator->t('analysis.condition_equal'),
            '!=' => $translator->t('analysis.condition_not_equal'),
            '>=' => $translator->t('analysis.condition_gte'),
            '<=' => $translator->t('analysis.condition_lte'),
            '&&' => $translator->t('analysis.condition_and'),
            '||' => $translator->t('analysis.condition_or'),
            '!' => $translator->t('analysis.condition_not'),
        ];

        return trim(str_replace(array_keys($replacements), array_values($replacements), $condition));
    }

    private static function appendInferredModel(string $description, string $var, array $inferredVars, Translator $translator): string
    {
        if (! isset($inferredVars[$var])) {
            return $description;
        }

        return rtrim($description, '.') . $translator->t('analysis.treated_as_model', ['model' => $inferredVars[$var]['model']]);
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

    private static function argumentsSuffix(string $args, ?Translator $translator = null): string
    {
        $translator ??= new Translator();
        $arguments = TextAnalyzer::splitArguments($args);

        return $arguments ? $translator->t('analysis.new_instance_args', ['args' => implode(', ', array_map(fn (string $arg) => self::cleanArgument($arg), array_slice($arguments, 0, 4)))]) : '';
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
