<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Docs;

use Galase\FlowDocs\Support\Diagram\DatabaseDiagramRenderer;
use Galase\FlowDocs\Support\Html\Html;
use Galase\FlowDocs\Support\Html\HtmlPage;

final class DatabaseDocsGenerator
{
    public static function generate(array $migrations, array $models, array $joins, array $config, string $output, array $tools): int
    {
        $base = $output . '/database';
        $items = $base . '/tables';
        self::ensureDirectory($base);
        self::ensureDirectory($items);
        $modelTables = [];
        foreach ($models as $model) {
            $modelTables[$tools['modelTableName']($model)][] = $model['fqcn'];
        }

        $files = 0;
        $indexItems = '<li><a class="flex items-center justify-between gap-4 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-sm hover:border-blue-400" href="diagram.html"><span class="font-medium text-blue-900">Diagrama do banco</span><span class="text-xs text-blue-700">visual</span></a></li>';
        foreach ($migrations['tables'] as $table => $schema) {
            $tableFile = 'tables/' . $tools['tableFileName']($table);
            $indexItems .= '<li><a class="flex items-center justify-between gap-4 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:border-blue-400" href="' . Html::escape($tableFile) . '"><span class="break-all font-medium text-slate-900">' . Html::escape($table) . '</span><span class="shrink-0 text-xs text-slate-500">' . count($schema['columns']) . ' colunas</span></a></li>';
            file_put_contents($items . '/' . $tools['tableFileName']($table), self::renderTablePage($table, $schema, $migrations, $models, $joins, $config, $tools));
            $files++;
        }

        $html = HtmlPage::start('Documentacao do Banco', 1) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $html .= self::optionalBackLink($config);
        $html .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Documentacao do Banco</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Schema inferido a partir das migrations, foreign keys declaradas e joins encontrados nos metodos da aplicacao.</p></header>';
        $html .= '<section class="mt-8"><h2 class="text-lg font-semibold">Indice</h2><ul class="mt-3 grid gap-2 lg:grid-cols-2">' . $indexItems . '</ul></section>';
        $html .= '</main></body></html>';
        file_put_contents($base . '/index.html', $html);
        file_put_contents($base . '/diagram.html', DatabaseDiagramRenderer::render($migrations, $models, $config, $tools['modelTableName']));

        return $files + 2;
    }

    private static function renderTablePage(string $table, array $schema, array $migrations, array $models, array $joins, array $config, array $tools): string
    {
        $relatedModels = array_values(array_filter($models, fn ($model) => $tools['modelTableName']($model) === $table));
        $incoming = self::incomingForeignKeys($table, $migrations);
        $tableJoins = array_values(array_filter($joins, fn ($join) => in_array($table, [$join['base_table'], $join['join_table']], true)));

        $columnsRows = '';
        foreach ($schema['columns'] as $column) {
            $columnsRows .= '<tr><td class="border-t px-3 py-2"><code>' . Html::escape($column['name']) . '</code></td><td class="border-t px-3 py-2">' . Html::escape($column['type']) . '</td><td class="border-t px-3 py-2 text-slate-500">' . Html::escape($column['source']) . '</td></tr>';
        }

        $fkRows = '';
        foreach ($schema['foreign_keys'] as $fk) {
            $fkRows .= '<tr><td class="border-t px-3 py-2"><code>' . Html::escape($fk['column']) . '</code></td><td class="border-t px-3 py-2"><code>' . Html::escape($fk['references_table'] . '.' . $fk['references_column']) . '</code></td><td class="border-t px-3 py-2 text-slate-500">' . Html::escape($fk['source']) . '</td></tr>';
        }

        $incomingRows = '';
        foreach ($incoming as $fk) {
            $incomingRows .= '<tr><td class="border-t px-3 py-2"><code>' . Html::escape($fk['table'] . '.' . $fk['column']) . '</code></td><td class="border-t px-3 py-2"><code>' . Html::escape($table . '.' . $fk['references_column']) . '</code></td><td class="border-t px-3 py-2 text-slate-500">' . Html::escape($fk['source']) . '</td></tr>';
        }

        $modelItems = '';
        foreach ($relatedModels as $model) {
            $relations = $tools['modelRelations']($model, $config);
            $modelItems .= '<li class="rounded border border-slate-200 bg-white p-3"><p class="break-all text-sm font-semibold text-slate-900">' . Html::escape($model['fqcn']) . '</p><p class="mt-1 text-xs text-slate-500">' . Html::escape($model['path']) . '</p><p class="mt-2 text-xs text-slate-600">' . count($relations) . ' relations declaradas</p></li>';
        }

        $joinItems = '';
        foreach ($tableJoins as $join) {
            $joinItems .= '<li><code>' . Html::escape($join['base_table'] . ' join ' . $join['join_table']) . '</code><span class="ml-2 text-slate-500">' . Html::escape($join['condition'] . ' em ' . $join['class'] . '@' . $join['method']) . '</span></li>';
        }

        $migrationItems = '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($file) => '<li><code>' . Html::escape($file) . '</code></li>', $schema['migrations'])) . '</ul>';

        $html = HtmlPage::start('Tabela ' . $table, 2) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="../index.html" class="text-sm font-semibold text-blue-700">Voltar ao banco</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Tabela do banco</p><h1 class="mt-2 break-words text-3xl font-semibold">' . Html::escape($table) . '</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Descricao estatica completa da tabela com base nas migrations, foreign keys, models associadas e joins inferidos no codigo.</p></header>';
        $html .= HtmlPage::metricCards(['Colunas' => count($schema['columns']), 'FKs de saida' => count($schema['foreign_keys']), 'Referencias recebidas' => count($incoming), 'Models' => count($relatedModels)]);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Migrations de origem</h2>' . $migrationItems . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Colunas</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Coluna</th><th class="px-3 py-2">Tipo</th><th class="px-3 py-2">Origem</th></tr></thead><tbody>' . ($columnsRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">Nenhuma coluna detectada.</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Foreign keys de saida</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Coluna</th><th class="px-3 py-2">Referencia</th><th class="px-3 py-2">Origem</th></tr></thead><tbody>' . ($fkRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">Nenhuma foreign key saindo desta tabela.</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Referencias recebidas</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Tabela/coluna</th><th class="px-3 py-2">Referencia local</th><th class="px-3 py-2">Origem</th></tr></thead><tbody>' . ($incomingRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">Nenhuma foreign key apontando para esta tabela.</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Models associadas</h2><ul class="mt-3 grid gap-3 md:grid-cols-2">' . ($modelItems ?: '<li class="text-sm text-slate-500">Nenhuma model direta inferida para esta tabela.</li>') . '</ul></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">Joins inferidos no codigo</h2><ul class="mt-2 space-y-2 text-sm text-slate-700">' . ($joinItems ?: '<li class="text-slate-500">Nenhum join textual envolvendo esta tabela foi inferido.</li>') . '</ul></section></main></body></html>';

        return $html;
    }

    private static function incomingForeignKeys(string $table, array $migrations): array
    {
        $incoming = [];
        foreach ($migrations['tables'] as $sourceTable => $schema) {
            foreach ($schema['foreign_keys'] as $fk) {
                if ($fk['references_table'] !== $table) {
                    continue;
                }
                $incoming[] = [
                    'table' => $sourceTable,
                    'column' => $fk['column'],
                    'references_column' => $fk['references_column'],
                    'source' => $fk['source'],
                ];
            }
        }

        return $incoming;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private static function optionalBackLink(array $config): string
    {
        if (empty($config['back_link'])) {
            return '';
        }

        return '<a href="' . Html::escape($config['back_link']) . '" class="text-sm font-semibold text-blue-700">Voltar</a>';
    }
}
