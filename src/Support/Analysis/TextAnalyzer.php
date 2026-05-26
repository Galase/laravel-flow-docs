<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Analysis;

final class TextAnalyzer
{
    public static function splitArguments(string $arguments): array
    {
        $items = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $arguments[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if (in_array($char, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']'], true)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $items[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $items[] = trim($buffer);
        }

        return $items;
    }

    public static function baseName(string $class): string
    {
        return basename(str_replace('\\', '/', trim($class, '\\')));
    }

    public static function humanize(string $name): string
    {
        return trim(strtolower(str_replace('_', ' ', preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name)));
    }

    public static function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;
        return strtolower(str_replace(['-', ' '], '_', $value));
    }

    public static function pluralize(string $value): string
    {
        if (str_ends_with($value, 'y')) {
            return substr($value, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $value)) {
            return $value . 'es';
        }

        return $value . 's';
    }
}
