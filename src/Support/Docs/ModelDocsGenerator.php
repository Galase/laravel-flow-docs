<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Docs;

use Galase\FlowDocs\Support\Html\Html;
use Galase\FlowDocs\Support\Html\HtmlPage;
use Galase\FlowDocs\Support\I18n\Translator;

final class ModelDocsGenerator
{
    public static function generate(array $models, array $migrations, array $joins, array $config, string $output, array $tools): int
    {
        $translator = Translator::fromConfig($config);
        $base = $output . '/models';
        $items = $base . '/models';
        self::ensureDirectory($items);

        $usage = $tools['usageMap']($models, $tools['phpFiles']((string) ($config['app_dir'] ?? app_path())));
        $files = 0;
        $indexItems = '';

        foreach ($models as $fqcn => $model) {
            $table = $tools['modelTableName']($model);
            $fileName = $tools['fileName']($fqcn);
            $indexItems .= '<li><a class="flex items-center justify-between gap-4 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:border-blue-400" href="models/' . Html::escape($fileName) . '"><span class="break-all font-medium text-slate-900">' . Html::escape($fqcn) . '</span><code class="shrink-0 text-xs text-slate-500">' . Html::escape($table) . '</code></a></li>';
        }

        $index = HtmlPage::start($translator->t('model.title'), 1, $config) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $index .= self::optionalBackLink($config);
        $index .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">' . Html::escape($translator->t('model.title')) . '</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">' . Html::escape($translator->t('model.description')) . '</p></header>';
        $index .= '<section class="mt-8"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('common.index')) . '</h2><ul class="mt-3 grid gap-2 lg:grid-cols-2">' . ($indexItems ?: '<li class="rounded border bg-white px-3 py-2 text-sm text-slate-500">' . Html::escape($translator->t('model.no_models')) . '</li>') . '</ul></section>';
        $index .= '</main></body></html>';
        file_put_contents($base . '/index.html', $index);
        $files++;

        foreach ($models as $fqcn => $model) {
            file_put_contents($items . '/' . $tools['fileName']($fqcn), self::renderPage($fqcn, $model, $usage[$fqcn] ?? [], $migrations, $joins, $config, $tools));
            $files++;
        }

        return $files;
    }

    private static function renderPage(string $fqcn, array $model, array $usedBy, array $migrations, array $joins, array $config, array $tools): string
    {
        $translator = Translator::fromConfig($config);
        $table = $tools['modelTableName']($model);
        $schema = $migrations['tables'][$table] ?? ['columns' => [], 'foreign_keys' => [], 'migrations' => []];
        $relations = $tools['modelRelations']($model, $config);
        $tableJoins = array_values(array_filter($joins, fn ($join) => in_array($table, [$join['base_table'], $join['join_table']], true)));

        $columnsRows = '';
        foreach ($schema['columns'] as $column) {
            $columnsRows .= '<tr><td class="border-t px-3 py-2"><code>' . Html::escape($column['name']) . '</code></td><td class="border-t px-3 py-2">' . Html::escape($column['type']) . '</td><td class="border-t px-3 py-2 text-slate-500">' . Html::escape($column['source']) . '</td></tr>';
        }
        $fkRows = '';
        foreach ($schema['foreign_keys'] as $fk) {
            $fkRows .= '<tr><td class="border-t px-3 py-2"><code>' . Html::escape($fk['column']) . '</code></td><td class="border-t px-3 py-2"><code>' . Html::escape($fk['references_table'] . '.' . $fk['references_column']) . '</code></td><td class="border-t px-3 py-2 text-slate-500">' . Html::escape($fk['source']) . '</td></tr>';
        }
        $relationItems = $relations
            ? '<ul class="mt-2 grid gap-2 text-sm text-slate-700 md:grid-cols-2">' . implode('', array_map(fn ($r) => '<li><code>' . Html::escape($r['method']) . '()</code> ' . Html::escape($r['type']) . ' <strong>' . Html::escape($r['target']) . '</strong><span class="text-slate-500"> ' . Html::escape($r['keys']) . '</span></li>', $relations)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('model.no_relations')) . '</p>';
        $usageHtml = $usedBy
            ? '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($i) => '<li><code>' . Html::escape($i) . '</code></li>', $usedBy)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('class.no_textual_usage')) . '</p>';
        $joinsHtml = $tableJoins
            ? '<ul class="mt-2 space-y-2 text-sm text-slate-700">' . implode('', array_map(fn ($join) => '<li><code>' . Html::escape($join['base_table'] . ' join ' . $join['join_table']) . '</code><span class="ml-2 text-slate-500">' . Html::escape($join['condition'] . ' ' . $translator->t('common.in_context', ['context' => $join['class'] . '@' . $join['method']])) . '</span></li>', $tableJoins)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('model.no_joins')) . '</p>';

        $html = HtmlPage::start($fqcn, 2, $config) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="../index.html" class="text-sm font-semibold text-blue-700">' . Html::escape($translator->t('common.back_to_index')) . '</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($translator->t('model.title')) . '</p><h1 class="mt-2 break-words text-3xl font-semibold">' . Html::escape($fqcn) . '</h1><p class="mt-3 text-sm text-slate-600"><code>' . Html::escape($model['path']) . '</code></p></header>';
        $html .= HtmlPage::metricCards([$translator->t('metric.table') => $table, $translator->t('common.columns') => count($schema['columns']), $translator->t('metric.foreign_keys') => count($schema['foreign_keys']), $translator->t('metric.relations') => count($relations)], $config);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('model.relations')) . '</h2>' . $relationItems . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('model.columns_in_migrations')) . '</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">' . Html::escape($translator->t('common.column')) . '</th><th class="px-3 py-2">' . Html::escape($translator->t('common.type')) . '</th><th class="px-3 py-2">' . Html::escape($translator->t('common.source')) . '</th></tr></thead><tbody>' . ($columnsRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">' . Html::escape($translator->t('model.no_columns')) . '</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('model.foreign_keys_in_migrations')) . '</h2><div class="mt-3 overflow-hidden rounded-lg border"><table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">' . Html::escape($translator->t('common.column')) . '</th><th class="px-3 py-2">' . Html::escape($translator->t('common.reference')) . '</th><th class="px-3 py-2">' . Html::escape($translator->t('common.source')) . '</th></tr></thead><tbody>' . ($fkRows ?: '<tr><td colspan="3" class="px-3 py-3 text-sm text-slate-500">' . Html::escape($translator->t('model.no_foreign_keys')) . '</td></tr>') . '</tbody></table></div></section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('model.inferred_joins')) . '</h2>' . $joinsHtml . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('class.where_appears')) . '</h2>' . $usageHtml . '</section></main></body></html>';

        return $html;
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

        return '<a href="' . Html::escape($config['back_link']) . '" class="text-sm font-semibold text-blue-700">' . Html::escape(Translator::fromConfig($config)->t('common.back')) . '</a>';
    }
}
