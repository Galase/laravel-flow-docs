<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Html;

final class Html
{
    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
