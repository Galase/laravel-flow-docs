<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Html;

final class HtmlPage
{
    public static function start(string $title, int $depth = 0): string
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
            $links .= '<a class="rounded px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950" href="' . Html::escape($href) . '">' . Html::escape($label) . '</a>';
        }

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . Html::escape($title) . '</title><script src="https://cdn.tailwindcss.com"></script><style>' . self::styles() . '</style></head><body class="bg-slate-50 text-slate-900"><nav class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur"><div class="mx-auto flex max-w-7xl flex-col gap-2 px-6 py-3 md:flex-row md:items-center md:justify-between"><a class="text-sm font-semibold text-slate-950" href="' . Html::escape($root . 'index.html') . '">Flow Docs</a><div class="flex flex-wrap gap-1">' . $links . '</div></div></nav>';
    }

    public static function metricCards(array $cards): string
    {
        $html = '<section class="mt-6 grid gap-4 md:grid-cols-4">';
        foreach ($cards as $label => $value) {
            $html .= '<div class="rounded-lg border bg-white p-4"><p class="text-xs uppercase text-slate-500">' . Html::escape($label) . '</p><p class="mt-2 break-words text-2xl font-semibold">' . Html::escape($value) . '</p></div>';
        }

        return $html . '</section>';
    }

    private static function styles(): string
    {
        return self::diagramStyles() . self::codeStyles();
    }

    private static function diagramStyles(): string
    {
        return '.diagram-card{transition:opacity .15s ease,box-shadow .15s ease,transform .15s ease,border-color .15s ease;}.diagram-card-dimmed{opacity:.24;}.diagram-card-active{opacity:1;border-color:#f97316;box-shadow:0 10px 24px rgba(15,23,42,.18);}.diagram-edge{transition:opacity .15s ease,filter .15s ease;}.diagram-edge-line{transition:stroke .15s ease,stroke-width .15s ease,opacity .15s ease;}.diagram-edge-label{transition:fill .15s ease,font-weight .15s ease,opacity .15s ease;paint-order:stroke;stroke:#f8fafc;stroke-width:4px;stroke-linejoin:round;}.diagram-edge:hover .diagram-edge-line,.diagram-edge-active .diagram-edge-line{stroke:#f97316;stroke-width:5;opacity:1;}.diagram-edge:hover .diagram-edge-label,.diagram-edge-active .diagram-edge-label{fill:#c2410c;font-weight:700;opacity:1;}.diagram-edge:hover,.diagram-edge-active{filter:drop-shadow(0 2px 4px rgba(249,115,22,.35));}.diagram-edge-dimmed{opacity:.18;}';
    }

    private static function codeStyles(): string
    {
        return '.code-dracula{background:#282a36;color:#f8f8f2;border-color:#44475a;}.code-dracula .tok-keyword{color:#ff79c6;}.code-dracula .tok-variable{color:#8be9fd;}.code-dracula .tok-string{color:#f1fa8c;}.code-dracula .tok-comment{color:#6272a4;font-style:italic;}.code-dracula .tok-number{color:#bd93f9;}.code-dracula .tok-name{color:#50fa7b;}.code-dracula .tok-operator{color:#ff79c6;}.code-dracula .tok-punctuation{color:#f8f8f2;}';
    }
}
