<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Html;

use Galase\FlowDocs\Support\I18n\Translator;

final class HtmlPage
{
    public static function start(string $title, int $depth = 0, array $config = []): string
    {
        $translator = Translator::fromConfig($config);
        $root = str_repeat('../', $depth);
        $nav = [
            $translator->t('nav.home') => $root . 'index.html',
            $translator->t('nav.services') => $root . 'services/index.html',
            $translator->t('nav.controllers') => $root . 'controllers/index.html',
            $translator->t('nav.models') => $root . 'models/index.html',
            $translator->t('nav.database') => $root . 'database/index.html',
            $translator->t('nav.diagram') => $root . 'database/diagram.html',
        ];
        $links = '';
        foreach ($nav as $label => $href) {
            $links .= '<a class="rounded px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white" href="' . Html::escape($href) . '">' . Html::escape($label) . '</a>';
        }

        return '<!doctype html><html lang="' . Html::escape($translator->htmlLang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . Html::escape($title) . '</title><script src="https://cdn.tailwindcss.com"></script>' . self::tailwindConfig() . self::themeScript($translator) . '<style>' . self::styles() . '</style></head><body class="bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100"><nav class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95"><div class="mx-auto flex max-w-7xl flex-col gap-2 px-6 py-3 md:flex-row md:items-center md:justify-between"><a class="text-sm font-semibold text-slate-950 dark:text-slate-50" href="' . Html::escape($root . 'index.html') . '">Flow Docs</a><div class="flex flex-wrap items-center gap-1">' . $links . self::themeToggle($translator) . '</div></div></nav>';
    }

    public static function metricCards(array $cards, array $config = []): string
    {
        $html = '<section class="mt-6 grid gap-4 md:grid-cols-4">';
        foreach ($cards as $label => $value) {
            $html .= '<div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><p class="text-xs uppercase text-slate-500 dark:text-slate-400">' . Html::escape($label) . '</p><p class="mt-2 break-words text-2xl font-semibold">' . Html::escape($value) . '</p></div>';
        }

        return $html . '</section>';
    }

    private static function tailwindConfig(): string
    {
        return <<<'HTML'
<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = { darkMode: 'class' };
</script>
HTML;
    }

    private static function themeScript(Translator $translator): string
    {
        $useLight = json_encode($translator->t('theme.use_light'), JSON_THROW_ON_ERROR);
        $useDark = json_encode($translator->t('theme.use_dark'), JSON_THROW_ON_ERROR);

        return <<<HTML
<script>
(() => {
  const useLightTitle = {$useLight};
  const useDarkTitle = {$useDark};
  const key = 'flow-docs-theme';
  const root = document.documentElement;
  const prefersDark = () => window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const storedTheme = () => {
    try {
      const stored = window.localStorage.getItem(key);
      return stored === 'dark' || stored === 'light' ? stored : null;
    } catch (error) {
      return null;
    }
  };
  const saveTheme = (theme) => {
    try {
      window.localStorage.setItem(key, theme);
    } catch (error) {
      return;
    }
  };
  const applyTheme = (theme) => {
    root.classList.toggle('dark', theme === 'dark');
    root.dataset.theme = theme;
  };
  const currentTheme = () => root.classList.contains('dark') ? 'dark' : 'light';
  applyTheme(storedTheme() || (prefersDark() ? 'dark' : 'light'));
  window.addEventListener('DOMContentLoaded', () => {
    const button = document.getElementById('flowDocsThemeToggle');
    if (!button) return;
    const sync = () => {
      const isDark = currentTheme() === 'dark';
      button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      button.setAttribute('title', isDark ? useLightTitle : useDarkTitle);
    };
    button.addEventListener('click', () => {
      const nextTheme = currentTheme() === 'dark' ? 'light' : 'dark';
      applyTheme(nextTheme);
      saveTheme(nextTheme);
      sync();
    });
    sync();
  });
})();
</script>
HTML;
    }

    private static function themeToggle(Translator $translator): string
    {
        return '<button id="flowDocsThemeToggle" class="inline-flex items-center rounded border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800" type="button" aria-label="' . Html::escape($translator->t('theme.toggle')) . '" aria-pressed="false"><span class="dark:hidden">' . Html::escape($translator->t('theme.dark')) . '</span><span class="hidden dark:inline">' . Html::escape($translator->t('theme.light')) . '</span></button>';
    }

    private static function styles(): string
    {
        return self::themeStyles() . self::diagramStyles() . self::codeStyles();
    }

    private static function themeStyles(): string
    {
        return <<<'CSS'
html{color-scheme:light;}html.dark{color-scheme:dark;}html.dark body{background:#020617;color:#e2e8f0;}html.dark .dark\:hidden{display:none;}html.dark .dark\:inline{display:inline;}html.dark .bg-white{background-color:#0f172a;}html.dark .bg-white\/90{background-color:rgb(15 23 42 / .9);}html.dark .bg-white\/95{background-color:rgb(15 23 42 / .95);}html.dark .bg-slate-50{background-color:#020617;}html.dark .bg-slate-100{background-color:#1e293b;}html.dark .bg-blue-50{background-color:#172554;}html.dark .border,html.dark .border-t,html.dark .border-b,html.dark .border-slate-100,html.dark .border-slate-200,html.dark .border-slate-300{border-color:#334155;}html.dark .border-blue-200{border-color:#1d4ed8;}html.dark .text-slate-950,html.dark .text-slate-900{color:#f8fafc;}html.dark .text-slate-800,html.dark .text-slate-700{color:#e2e8f0;}html.dark .text-slate-600{color:#cbd5e1;}html.dark .text-slate-500,html.dark .text-slate-400{color:#94a3b8;}html.dark .text-blue-900{color:#bfdbfe;}html.dark .text-blue-700{color:#93c5fd;}html.dark .fill-slate-600{fill:#cbd5e1;}html.dark code:not(pre code){color:#bfdbfe;}html.dark .hover\:bg-slate-50:hover,html.dark .hover\:bg-slate-100:hover{background-color:#1e293b;}html.dark .hover\:text-slate-950:hover{color:#fff;}html.dark .hover\:border-blue-400:hover{border-color:#60a5fa;}
CSS;
    }

    private static function diagramStyles(): string
    {
        return '.diagram-card{transition:opacity .15s ease,box-shadow .15s ease,transform .15s ease,border-color .15s ease;}.diagram-card-dimmed{opacity:.24;}.diagram-card-active{opacity:1;border-color:#f97316;box-shadow:0 10px 24px rgba(15,23,42,.18);}.diagram-edge{transition:opacity .15s ease,filter .15s ease;}.diagram-edge-line{transition:stroke .15s ease,stroke-width .15s ease,opacity .15s ease;}.diagram-edge-label{transition:fill .15s ease,font-weight .15s ease,opacity .15s ease;paint-order:stroke;stroke:#f8fafc;stroke-width:4px;stroke-linejoin:round;}.diagram-edge:hover .diagram-edge-line,.diagram-edge-active .diagram-edge-line{stroke:#f97316;stroke-width:5;opacity:1;}.diagram-edge:hover .diagram-edge-label,.diagram-edge-active .diagram-edge-label{fill:#c2410c;font-weight:700;opacity:1;}.diagram-edge:hover,.diagram-edge-active{filter:drop-shadow(0 2px 4px rgba(249,115,22,.35));}.diagram-edge-dimmed{opacity:.18;}html.dark .diagram-edge-label{fill:#dbeafe;stroke:#020617;}html.dark .diagram-edge:hover .diagram-edge-label,html.dark .diagram-edge-active .diagram-edge-label{fill:#fdba74;stroke:#020617;}';
    }

    private static function codeStyles(): string
    {
        return '.code-dracula{background:#282a36;color:#f8f8f2;border-color:#44475a;}.code-dracula .tok-keyword{color:#ff79c6;}.code-dracula .tok-variable{color:#8be9fd;}.code-dracula .tok-string{color:#f1fa8c;}.code-dracula .tok-comment{color:#6272a4;font-style:italic;}.code-dracula .tok-number{color:#bd93f9;}.code-dracula .tok-name{color:#50fa7b;}.code-dracula .tok-operator{color:#ff79c6;}.code-dracula .tok-punctuation{color:#f8f8f2;}';
    }
}
