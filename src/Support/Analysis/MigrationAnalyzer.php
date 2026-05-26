<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Analysis;

final class MigrationAnalyzer
{
    public static function analyze(array $config): array
    {
        $tables = [];
        foreach (self::migrationFiles($config) as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\);/s', $code, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                $table = $block[1];
                $body = $block[2];
                $tables[$table] ??= ['name' => $table, 'columns' => [], 'foreign_keys' => [], 'migrations' => []];
                $tables[$table]['migrations'][] = PhpSourceAnalyzer::relativePath($file);
                foreach (self::columns($body, $file) as $column) {
                    $tables[$table]['columns'][$column['name']] = $column;
                }
                foreach (self::foreignKeys($body, $file) as $fk) {
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

    private static function migrationFiles(array $config): array
    {
        $dirs = $config['migration_dirs'] ?? [self::databasePath('migrations')];
        $files = [];
        foreach ($dirs as $dir) {
            foreach (PhpSourceAnalyzer::phpFiles((string) $dir) as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private static function databasePath(string $path = ''): string
    {
        if (function_exists('database_path')) {
            return database_path($path);
        }

        return rtrim(base_path('database'), '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    private static function columns(string $body, string $file): array
    {
        $columns = [];
        preg_match_all('/\$table->([A-Za-z_][A-Za-z0-9_]*)\(\s*(.*?)\)\s*(?:->[^;]+)?;/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $type = $match[1];
            if (in_array($type, ['foreign', 'primary', 'unique', 'index'], true)) {
                continue;
            }
            $args = TextAnalyzer::splitArguments($match[2]);
            $name = $type === 'id' ? 'id' : trim($args[0] ?? '', "'\" ");
            if ($name === '' && str_ends_with($type, 'Timestamps')) {
                $name = $type;
            }
            if ($name === '') {
                continue;
            }
            $columns[] = ['name' => $name, 'type' => $type, 'source' => PhpSourceAnalyzer::relativePath($file)];
        }

        return $columns;
    }

    private static function foreignKeys(string $body, string $file): array
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
                $referencesTable = TextAnalyzer::pluralize(TextAnalyzer::snakeCase(preg_replace('/_id$/', '', $column) ?? $column));
            }
            if ($referencesTable) {
                $fks[] = ['column' => $column, 'references_table' => $referencesTable, 'references_column' => 'id', 'source' => PhpSourceAnalyzer::relativePath($file)];
            }
        }

        preg_match_all('/\$table->foreign\(\s*[\'"]([^\'"]+)[\'"]\s*\)->references\(\s*[\'"]([^\'"]+)[\'"]\s*\)->on\(\s*[\'"]([^\'"]+)[\'"]\s*\)/s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $fks[] = ['column' => $match[1], 'references_table' => $match[3], 'references_column' => $match[2], 'source' => PhpSourceAnalyzer::relativePath($file)];
        }

        return $fks;
    }
}
