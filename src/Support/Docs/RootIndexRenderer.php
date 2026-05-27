<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Docs;

use Galase\FlowDocs\Support\Html\Html;
use Galase\FlowDocs\Support\Html\HtmlPage;
use Galase\FlowDocs\Support\I18n\Translator;

final class RootIndexRenderer
{
    public static function write(string $output, array $config, bool $hasServices, bool $hasControllers, bool $hasModels, bool $hasDatabase): void
    {
        $translator = Translator::fromConfig($config);
        if (! is_dir($output)) {
            mkdir($output, 0777, true);
        }

        $indexItems = '';
        if ($hasServices) {
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="services/index.html"><span>' . Html::escape($translator->t('nav.services')) . '</span><span class="text-xs text-slate-500">' . Html::escape($translator->t('root.services_badge')) . '</span></a></li>';
        }
        if ($hasControllers) {
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="controllers/index.html"><span>' . Html::escape($translator->t('nav.controllers')) . '</span><span class="text-xs text-slate-500">' . Html::escape($translator->t('root.controllers_badge')) . '</span></a></li>';
        }
        if ($hasModels) {
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="models/index.html"><span>' . Html::escape($translator->t('nav.models')) . '</span><span class="text-xs text-slate-500">' . Html::escape($translator->t('root.models_badge')) . '</span></a></li>';
        }
        if ($hasDatabase) {
            $indexItems .= '<li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="database/index.html"><span>' . Html::escape($translator->t('root.database')) . '</span><span class="text-xs text-slate-500">' . Html::escape($translator->t('root.database_badge')) . '</span></a></li><li><a class="flex items-center justify-between rounded border bg-white px-3 py-2 text-sm hover:border-blue-400" href="database/diagram.html"><span>' . Html::escape($translator->t('root.diagram')) . '</span><span class="text-xs text-slate-500">' . Html::escape($translator->t('root.diagram_badge')) . '</span></a></li>';
        }

        $html = HtmlPage::start('Flow Docs', 0, $config) . '<main class="mx-auto max-w-5xl px-6 py-10"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Flow Docs</h1><p class="mt-3 text-sm leading-6 text-slate-600">' . Html::escape($translator->t('root.description')) . '</p><section class="mt-8"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('common.index')) . '</h2><ul class="mt-3 grid gap-2 md:grid-cols-2">' . $indexItems . '</ul></section></main></body></html>';
        file_put_contents($output . '/index.html', $html);
    }
}
