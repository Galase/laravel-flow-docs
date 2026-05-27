<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Docs;

use Galase\FlowDocs\Support\Html\Html;
use Galase\FlowDocs\Support\Html\HtmlPage;
use Galase\FlowDocs\Support\I18n\Translator;

final class ClassDocsGenerator
{
    public static function generate(array $classes, string $kind, array $allClasses, array $config, string $output, array $routes, array $tools): int
    {
        $translator = Translator::fromConfig($config);
        $base = $output . '/' . $kind;
        $items = $base . '/' . $kind;
        self::ensureDirectory($items);

        $returnIndex = $tools['methodReturnIndex']($allClasses, $config);
        $usage = $tools['usageMap']($classes, $tools['phpFiles']((string) ($config['app_dir'] ?? app_path())));
        $files = 0;
        $indexItems = '';

        foreach ($classes as $fqcn => $class) {
            $fileName = $tools['fileName']($fqcn);
            $public = count(array_filter($class['methods'], fn (array $m) => $m['visibility'] === 'public'));
            $routeCount = 0;
            foreach ($class['methods'] as $method) {
                $routeCount += count($routes[$fqcn . '@' . $method['name']] ?? []);
            }
            $indexItems .= '<li><a class="flex items-center justify-between gap-4 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:border-blue-400" href="' . $kind . '/' . Html::escape($fileName) . '"><span class="break-all font-medium text-slate-900">' . Html::escape($fqcn) . '</span><span class="shrink-0 text-xs text-slate-500">' . Html::escape($translator->t('common.public_count', ['count' => $public])) . '</span></a></li>';
        }

        $title = $kind === 'services' ? $translator->t('class.service_title') : $translator->t('class.controller_title');
        $index = HtmlPage::start($title, 1, $config) . '<main class="mx-auto max-w-7xl px-6 py-8">';
        $index .= self::optionalBackLink($config);
        $index .= '<header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($config['project_name'] ?? 'Laravel') . '</p><h1 class="mt-2 text-3xl font-semibold">' . Html::escape($title) . '</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">' . Html::escape($translator->t('class.description')) . '</p></header>';
        $index .= '<section class="mt-8"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('common.index')) . '</h2><ul class="mt-3 grid gap-2 lg:grid-cols-2">' . ($indexItems ?: '<li class="rounded border bg-white px-3 py-2 text-sm text-slate-500">' . Html::escape($translator->t('class.no_items')) . '</li>') . '</ul></section>';
        $index .= '</main></body></html>';
        file_put_contents($base . '/index.html', $index);
        $files++;

        foreach ($classes as $fqcn => $class) {
            file_put_contents($items . '/' . $tools['fileName']($fqcn), self::renderPage($fqcn, $class, $kind, $config, $returnIndex, $usage[$fqcn] ?? [], $routes, $tools));
            $files++;
        }

        return $files;
    }

    private static function renderPage(string $fqcn, array $class, string $kind, array $config, array $returnIndex, array $usedBy, array $routes, array $tools): string
    {
        $translator = Translator::fromConfig($config);
        $imports = $class['imports']
            ? '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($i) => '<li><code>' . Html::escape($i) . '</code></li>', $class['imports'])) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('class.no_direct_imports')) . '</p>';
        $usageHtml = $usedBy
            ? '<ul class="mt-2 grid gap-1 text-xs text-slate-600 md:grid-cols-2">' . implode('', array_map(fn ($i) => '<li><code>' . Html::escape($i) . '</code></li>', $usedBy)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('class.no_textual_usage')) . '</p>';
        $dependencies = $tools['dependencyInjections']($class, $config);
        $dependenciesHtml = $dependencies
            ? '<ul class="mt-2 space-y-2 text-sm text-slate-700">' . implode('', array_map(fn ($dependency) => '<li><code>' . Html::escape($dependency['type'] . ' $' . $dependency['name']) . '</code><span class="ml-2 text-slate-500">' . Html::escape($dependency['where']) . '</span></li>', $dependencies)) . '</ul>'
            : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('class.no_injection')) . '</p>';

        $sections = '';
        foreach ($class['methods'] as $method) {
            $inferred = $tools['inferVariableModels']($method, $class, $returnIndex, $config);
            $models = $tools['detectModels']($method, $class, $returnIndex, $config);
            $actions = $tools['detectActions']($method['body'], $config);
            $calls = $tools['detectCalls']($method['body'], $config);
            $purpose = $tools['explicitPurpose']($method, $models, $inferred, $config);
            $methodRoutes = $routes[$fqcn . '@' . $method['name']] ?? [];
            $routesHtml = $methodRoutes
                ? '<ul class="space-y-1">' . implode('', array_map(fn ($r) => '<li><code>' . Html::escape(($r['method'] ?? '') . ' ' . ($r['uri'] ?? '')) . '</code>' . (! empty($r['name']) ? '<span class="ml-2 text-slate-500">' . Html::escape($r['name']) . '</span>' : '') . '</li>', $methodRoutes)) . '</ul>'
                : '<span class="text-slate-400">' . Html::escape($translator->t('class.no_direct_route')) . '</span>';
            $varsHtml = $inferred
                ? '<ul class="mt-2 space-y-1">' . implode('', array_map(fn ($var, $meta) => '<li><code>$' . Html::escape($var) . '</code> => <strong>' . Html::escape($meta['model']) . '</strong><span class="text-slate-500"> (' . Html::escape($meta['source']) . ')</span></li>', array_keys($inferred), $inferred)) . '</ul>'
                : '<p class="mt-2 text-sm text-slate-500">' . Html::escape($translator->t('class.no_inferred_model_var')) . '</p>';

            $flowCode = self::annotatedMethodCode($method, $tools['lineExplanations']($method, $inferred, $config), $config);

            $sections .= '<section class="rounded-lg border border-slate-200 bg-white p-5"><div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between"><div><h2 class="text-lg font-semibold">' . Html::escape($method['name']) . '</h2><p class="mt-1 text-xs text-slate-500">' . Html::escape($translator->t('class.method_meta', ['visibility' => $method['visibility'] . ($method['static'] ? ' static' : ''), 'start' => $method['startLine'], 'end' => $method['endLine']])) . '</p></div><span class="text-xs font-semibold text-slate-500">' . Html::escape($class['path']) . '</span></div>';
            $sections .= '<p class="mt-4 text-sm leading-6 text-slate-700">' . Html::escape($purpose) . '</p>';
            $sections .= '<div class="mt-4 grid gap-4 lg:grid-cols-3"><div><h3 class="text-xs font-semibold uppercase text-slate-500">' . Html::escape($translator->t('common.routes')) . '</h3><div class="mt-2 text-sm">' . $routesHtml . '</div></div><div><h3 class="text-xs font-semibold uppercase text-slate-500">' . Html::escape($translator->t('common.models_entities')) . '</h3><p class="mt-2 text-sm text-slate-600">' . Html::escape($models ? implode(', ', $models) : $translator->t('class.no_direct_model')) . '</p></div><div><h3 class="text-xs font-semibold uppercase text-slate-500">' . Html::escape($translator->t('common.actions')) . '</h3><p class="mt-2 text-sm text-slate-600">' . Html::escape(implode(' | ', $actions)) . '</p></div></div>';
            $sections .= '<div class="mt-4 rounded-lg bg-slate-50 p-4"><h3 class="text-xs font-semibold uppercase text-slate-500">' . Html::escape($translator->t('class.variables_as_model')) . '</h3>' . $varsHtml . '</div>';
            $sections .= '<div class="mt-4"><h3 class="text-xs font-semibold uppercase text-slate-500">' . Html::escape($translator->t('class.internal_calls')) . '</h3><p class="mt-2 text-sm text-slate-600">' . Html::escape($calls ? implode(' | ', array_slice($calls, 0, 24)) : $translator->t('class.no_relevant_calls')) . '</p></div>';
            $sections .= '<div class="mt-5"><h3 class="text-xs font-semibold uppercase text-slate-500">' . Html::escape($translator->t('class.annotated_code')) . '</h3><pre class="code-dracula mt-2 overflow-x-auto rounded-lg border p-4 text-xs leading-6"><code class="language-php">' . self::highlightPhp($flowCode) . '</code></pre></div></section>';
        }

        $kindLabel = $kind === 'services' ? $translator->t('class.kind_service') : $translator->t('class.kind_controller');
        $html = HtmlPage::start($fqcn, 2, $config) . '<main class="mx-auto max-w-7xl px-6 py-8"><a href="../index.html" class="text-sm font-semibold text-blue-700">' . Html::escape($translator->t('common.back_to_index')) . '</a><header class="mt-4 border-b border-slate-200 pb-6"><p class="text-sm font-semibold uppercase tracking-wide text-blue-700">' . Html::escape($translator->t('class.doc_by_kind', ['kind' => $kindLabel])) . '</p><h1 class="mt-2 break-words text-3xl font-semibold">' . Html::escape($fqcn) . '</h1><p class="mt-3 text-sm text-slate-600"><code>' . Html::escape($class['path']) . '</code></p></header>';
        $html .= HtmlPage::metricCards([$translator->t('metric.methods') => count($class['methods']), $translator->t('metric.public') => count(array_filter($class['methods'], fn ($m) => $m['visibility'] === 'public')), $translator->t('metric.usages') => count($usedBy), $translator->t('metric.lines') => $class['lines']], $config);
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('class.where_appears')) . '</h2>' . $usageHtml . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('class.imported_dependencies')) . '</h2>' . $imports . '</section>';
        $html .= '<section class="mt-6 rounded-lg border bg-white p-5"><h2 class="text-lg font-semibold">' . Html::escape($translator->t('class.dependency_injection')) . '</h2>' . $dependenciesHtml . '</section>';
        $html .= '<section class="mt-6 space-y-5">' . $sections . '</section></main></body></html>';

        return $html;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private static function annotatedMethodCode(array $method, array $explanations, array $config): string
    {
        $translator = Translator::fromConfig($config);
        $commentsByLine = [];
        foreach ($explanations as $row) {
            if (($row['line'] ?? '') === '') {
                continue;
            }
            $commentsByLine[(int) $row['line']][] = $row['explanation'];
        }

        [$lines, $firstLineOffset] = self::methodBodyLines($method['body']);
        $code = [];
        $declaration = trim(($method['visibility'] ?? 'public') . (! empty($method['static']) ? ' static ' : ' ') . ($method['signature'] ?? 'function'));
        $code[] = $declaration;
        $code[] = '{';

        if (trim($method['body']) === '') {
            $code[] = '    // ' . $translator->t('analysis.empty_method');
        } else {
            foreach ($lines as $index => $line) {
                $sourceLine = ((int) ($method['startLine'] ?? 0)) + $firstLineOffset + $index + 1;
                $code[] = self::annotatedLine($line, $commentsByLine[$sourceLine] ?? []);
            }
        }

        $code[] = '}';

        return implode("\n", $code);
    }

    private static function annotatedLine(string $line, array $comments): string
    {
        $line = '    ' . rtrim($line);
        $trimmed = trim($line);
        if ($comments === [] || $trimmed === '' || str_starts_with($trimmed, '//')) {
            return $line;
        }

        return $line . ' // ' . implode(' ', array_unique($comments));
    }

    private static function methodBodyLines(string $body): array
    {
        $lines = explode("\n", rtrim($body, "\n"));
        $first = 0;
        $last = count($lines) - 1;

        while ($first <= $last && trim($lines[$first]) === '') {
            $first++;
        }
        while ($last >= $first && trim($lines[$last]) === '') {
            $last--;
        }
        if ($first > $last) {
            return [[], 0];
        }

        $lines = array_slice($lines, $first, $last - $first + 1);
        $commonIndent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^\s*/', $line, $match);
            $indent = strlen($match[0] ?? '');
            $commonIndent = $commonIndent === null ? $indent : min($commonIndent, $indent);
        }
        if ($commonIndent) {
            $lines = array_map(fn (string $line) => substr($line, $commonIndent), $lines);
        }

        return [$lines, $first];
    }

    private static function highlightPhp(string $code): string
    {
        $tokens = token_get_all("<?php\n" . $code);
        $html = '';
        $skipOpenTag = true;
        foreach ($tokens as $token) {
            if ($skipOpenTag && is_array($token) && $token[0] === T_OPEN_TAG) {
                $skipOpenTag = false;
                continue;
            }
            if (is_array($token)) {
                $html .= self::highlightToken($token[0], $token[1]);
                continue;
            }
            $html .= self::highlightSymbol($token);
        }

        return ltrim($html, "\n");
    }

    private static function highlightToken(int $type, string $text): string
    {
        $class = match (true) {
            in_array($type, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FUNCTION, T_RETURN, T_IF, T_ELSE, T_FOREACH, T_FOR, T_WHILE, T_TRY, T_CATCH, T_NEW, T_AS, T_ARRAY], true) => 'tok-keyword',
            $type === T_VARIABLE => 'tok-variable',
            in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) => 'tok-string',
            in_array($type, [T_COMMENT, T_DOC_COMMENT], true) => 'tok-comment',
            in_array($type, [T_LNUMBER, T_DNUMBER], true) => 'tok-number',
            in_array($type, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_DOUBLE_ARROW], true) => 'tok-operator',
            $type === T_STRING => 'tok-name',
            default => '',
        };

        return $class === ''
            ? Html::escape($text)
            : '<span class="' . $class . '">' . Html::escape($text) . '</span>';
    }

    private static function highlightSymbol(string $symbol): string
    {
        $class = in_array($symbol, ['=', '=>', '->', '::', '+', '-', '*', '/', '.', '?', ':'], true)
            ? 'tok-operator'
            : (preg_match('/^[{}()[\],;]$/', $symbol) ? 'tok-punctuation' : '');

        return $class === ''
            ? Html::escape($symbol)
            : '<span class="' . $class . '">' . Html::escape($symbol) . '</span>';
    }

    private static function optionalBackLink(array $config): string
    {
        if (empty($config['back_link'])) {
            return '';
        }

        return '<a href="' . Html::escape($config['back_link']) . '" class="text-sm font-semibold text-blue-700">' . Html::escape(Translator::fromConfig($config)->t('common.back')) . '</a>';
    }
}
