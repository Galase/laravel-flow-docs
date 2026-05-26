<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Diagram;

use Galase\FlowDocs\Support\Html\Html;
use Galase\FlowDocs\Support\Html\HtmlPage;

final class DatabaseDiagramRenderer
{
    public static function render(array $migrations, array $models, array $config, callable $modelTableName): string
    {
        $diagram = DatabaseDiagramBuilder::build($migrations, $models, $modelTableName);
        $nodes = $diagram['nodes'];
        $edges = $diagram['edges'];
        $width = $diagram['width'];
        $height = $diagram['height'];

        $lines = self::renderEdges($edges, $nodes);
        $cards = self::renderCards($nodes);
        $empty = $nodes
            ? ''
            : '<section class="rounded-lg border bg-white p-5 text-sm text-slate-500">Nenhuma tabela encontrada para desenhar.</section>';

        $html = HtmlPage::start('Diagrama do Banco', 1) . '<main class="px-6 py-8"><a href="index.html" class="text-sm font-semibold text-blue-700">Voltar ao banco</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">Diagrama do Banco</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">Diagrama HTML gerado a partir das tabelas, colunas, models inferidas e foreign keys detectadas nas migrations.</p></header>';
        $html .= HtmlPage::metricCards(['Tabelas' => count($nodes), 'Relacoes' => count($edges)]);
        $html .= '<section class="mt-8 rounded-lg border bg-white"><div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3"><button id="diagramZoomOut" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" type="button">-</button><span id="diagramZoomLabel" class="min-w-14 text-center text-sm font-semibold text-slate-700">100%</span><button id="diagramZoomIn" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" type="button">+</button><button id="diagramReset" class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" type="button">Reset</button></div><div id="diagramViewport" class="relative h-[82vh] min-h-[640px] overflow-hidden bg-slate-100 touch-none"><aside class="pointer-events-none absolute left-4 top-4 z-20 max-w-sm rounded-lg border border-slate-300 bg-white/90 p-4 text-sm leading-6 text-slate-800 shadow-md backdrop-blur"><p class="font-semibold text-slate-950">Como navegar</p><ul class="mt-2 list-disc space-y-1 pl-5"><li>Use os botoes ou Ctrl + scroll para aproximar e afastar.</li><li>Arraste com o botao direito para mover o diagrama.</li><li>Use o botao esquerdo para selecionar textos dos cards.</li><li>Passe o mouse em um card para destacar suas conexoes.</li></ul></aside><div id="diagramCanvas" class="relative origin-top-left bg-slate-50" style="width:' . $width . 'px;height:' . $height . 'px"><svg class="absolute inset-0" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><defs><marker id="arrow" markerWidth="14" markerHeight="14" refX="0" refY="0" orient="auto" markerUnits="userSpaceOnUse" viewBox="0 -7 14 14"><path d="M0,-7 L14,0 L0,7 z" fill="context-stroke"></path></marker></defs>' . $lines . '</svg>' . $cards . '</div></div></section>' . $empty . '</main>' . self::script() . '</body></html>';

        return $html;
    }

    private static function renderEdges(array $edges, array $nodes): string
    {
        $lines = '';
        foreach ($edges as $edge) {
            $from = $nodes[$edge['from']] ?? null;
            $to = $nodes[$edge['to']] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            $fromCenterX = $from['x'] + (int) ($from['width'] / 2);
            $toCenterX = $to['x'] + (int) ($to['width'] / 2);
            $fromRight = $fromCenterX <= $toCenterX;
            $arrowBaseGap = 14;
            $curve = 120;
            $x1 = $fromCenterX <= $toCenterX ? $from['x'] + $from['width'] : $from['x'];
            $y1 = self::columnY($from, $edge['from_column']);
            $x2 = $fromCenterX <= $toCenterX ? $to['x'] - $arrowBaseGap : $to['x'] + $to['width'] + $arrowBaseGap;
            $y2 = self::columnY($to, $edge['to_column']);
            $mid = (int) (($x1 + $x2) / 2);
            $labelX = (int) (($x1 + $x2) / 2);
            $labelY = (int) (($y1 + $y2) / 2) - 6;
            $control1X = $fromRight ? $x1 + $curve : $x1 - $curve;
            $control2X = $fromRight ? $x2 - $curve : $x2 + $curve;
            if (($fromRight && $control1X > $mid) || (! $fromRight && $control1X < $mid)) {
                $control1X = $mid;
            }
            if (($fromRight && $control2X < $mid) || (! $fromRight && $control2X > $mid)) {
                $control2X = $mid;
            }
            $path = 'M ' . $x1 . ' ' . $y1 . ' C ' . $control1X . ' ' . $y1 . ', ' . $control2X . ' ' . $y2 . ', ' . $x2 . ' ' . $y2;
            $label = $edge['type'] . ' ' . $edge['from_column'] . ' -> ' . $edge['to_column'];
            $title = $edge['type'] . ' ' . $edge['from'] . '.' . $edge['from_column'] . ' -> ' . $edge['to'] . '.' . $edge['to_column'];
            $lines .= '<g class="diagram-edge cursor-pointer" data-from="' . Html::escape($edge['from']) . '" data-to="' . Html::escape($edge['to']) . '"><title>' . Html::escape($title) . '</title><path class="diagram-edge-hit" d="' . Html::escape($path) . '" fill="none" stroke="transparent" stroke-width="18"></path><path class="diagram-edge-line" d="' . Html::escape($path) . '" fill="none" stroke="#2563eb" stroke-width="2" marker-end="url(#arrow)" opacity="0.75"></path>';
            $lines .= '<text x="' . $labelX . '" y="' . $labelY . '" text-anchor="middle" class="diagram-edge-label fill-slate-600 text-[11px]">' . Html::escape($label) . '</text></g>';
        }

        return $lines;
    }

    private static function renderCards(array $nodes): string
    {
        $cards = '';
        foreach ($nodes as $node) {
            $columns = array_slice($node['columns'], 0, 14);
            $columnItems = '';
            foreach ($columns as $column) {
                $columnItems .= '<li class="flex items-center justify-between gap-3 border-t border-slate-100 px-3 py-1.5"><code class="truncate text-xs text-slate-700">' . Html::escape($column['name']) . '</code><span class="shrink-0 text-[11px] text-slate-500">' . Html::escape($column['type']) . '</span></li>';
            }
            if (count($node['columns']) > count($columns)) {
                $columnItems .= '<li class="border-t border-slate-100 px-3 py-1.5 text-xs text-slate-500">+' . (count($node['columns']) - count($columns)) . ' colunas</li>';
            }
            if ($columnItems === '') {
                $columnItems = '<li class="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">Sem colunas detectadas.</li>';
            }
            $modelLabel = $node['models'] ? implode(', ', $node['models']) : 'sem model direta';
            $cards .= '<section class="diagram-card absolute overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm outline-none" tabindex="0" data-table="' . Html::escape($node['name']) . '" style="left:' . $node['x'] . 'px;top:' . $node['y'] . 'px;width:' . $node['width'] . 'px">';
            $cards .= '<header class="border-b border-slate-200 bg-slate-900 px-3 py-2 text-white"><h2 class="truncate text-sm font-semibold">' . Html::escape($node['name']) . '</h2><p class="mt-0.5 truncate text-[11px] text-slate-300">' . Html::escape($modelLabel) . '</p></header>';
            $cards .= '<ul class="max-h-72 overflow-hidden bg-white">' . $columnItems . '</ul></section>';
        }

        return $cards;
    }

    private static function columnY(array $node, string $column): int
    {
        $headerHeight = 56;
        $rowHeight = 30;
        $index = 0;
        foreach ($node['columns'] as $position => $candidate) {
            if (($candidate['name'] ?? '') === $column) {
                $index = (int) $position;
                break;
            }
        }

        return $node['y'] + $headerHeight + ($index * $rowHeight) + (int) ($rowHeight / 2);
    }

    private static function script(): string
    {
        return <<<'HTML'
<script>
(() => {
  const viewport = document.getElementById('diagramViewport');
  const canvas = document.getElementById('diagramCanvas');
  const zoomLabel = document.getElementById('diagramZoomLabel');
  if (!viewport || !canvas || !zoomLabel) return;
  const cards = Array.from(canvas.querySelectorAll('.diagram-card'));
  const edges = Array.from(canvas.querySelectorAll('.diagram-edge'));
  let scale = 1;
  let x = 0;
  let y = 0;
  let dragging = false;
  let startX = 0;
  let startY = 0;
  let originX = 0;
  let originY = 0;
  const render = () => {
    canvas.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
    zoomLabel.textContent = `${Math.round(scale * 100)}%`;
  };
  const setScale = (next) => {
    scale = Math.min(2.5, Math.max(0.35, next));
    render();
  };
  document.getElementById('diagramZoomIn')?.addEventListener('click', () => setScale(scale + 0.15));
  document.getElementById('diagramZoomOut')?.addEventListener('click', () => setScale(scale - 0.15));
  document.getElementById('diagramReset')?.addEventListener('click', () => {
    scale = 1;
    x = 0;
    y = 0;
    render();
  });
  viewport.addEventListener('wheel', (event) => {
    if (!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    setScale(scale + (event.deltaY < 0 ? 0.1 : -0.1));
  }, { passive: false });
  viewport.addEventListener('pointerdown', (event) => {
    if (event.button !== 2) return;
    event.preventDefault();
    dragging = true;
    startX = event.clientX;
    startY = event.clientY;
    originX = x;
    originY = y;
    viewport.setPointerCapture(event.pointerId);
    viewport.classList.add('cursor-grabbing');
  });
  viewport.addEventListener('contextmenu', (event) => event.preventDefault());
  viewport.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    x = originX + event.clientX - startX;
    y = originY + event.clientY - startY;
    render();
  });
  const stop = (event) => {
    dragging = false;
    viewport.classList.remove('cursor-grabbing');
    if (event.pointerId) viewport.releasePointerCapture(event.pointerId);
  };
  viewport.addEventListener('pointerup', stop);
  viewport.addEventListener('pointercancel', stop);
  const clearHighlight = () => {
    cards.forEach((card) => {
      card.classList.remove('diagram-card-active', 'diagram-card-dimmed');
    });
    edges.forEach((edge) => {
      edge.classList.remove('diagram-edge-active', 'diagram-edge-dimmed');
    });
  };
  const highlightCard = (table) => {
    const connected = new Set([table]);
    edges.forEach((edge) => {
      const isActive = edge.dataset.from === table || edge.dataset.to === table;
      edge.classList.toggle('diagram-edge-active', isActive);
      edge.classList.toggle('diagram-edge-dimmed', !isActive);
      if (isActive && edge.dataset.from) connected.add(edge.dataset.from);
      if (isActive && edge.dataset.to) connected.add(edge.dataset.to);
    });
    cards.forEach((card) => {
      const isActive = connected.has(card.dataset.table || '');
      card.classList.toggle('diagram-card-active', isActive);
      card.classList.toggle('diagram-card-dimmed', !isActive);
    });
  };
  cards.forEach((card) => {
    card.addEventListener('mouseenter', () => highlightCard(card.dataset.table || ''));
    card.addEventListener('focus', () => highlightCard(card.dataset.table || ''));
    card.addEventListener('mouseleave', clearHighlight);
    card.addEventListener('blur', clearHighlight);
  });
  render();
})();
</script>
HTML;
    }
}
