<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\I18n;

final class Translator
{
    private const DEFAULT_LANGUAGE = 'pt_BR';

    private const LANGUAGES = [
        'pt' => 'pt_BR',
        'pt_br' => 'pt_BR',
        'pt-BR' => 'pt_BR',
        'pt_BR' => 'pt_BR',
        'en' => 'en',
        'en_us' => 'en',
        'en-US' => 'en',
        'en_gb' => 'en',
        'en-GB' => 'en',
        'es' => 'es',
        'es_es' => 'es',
        'es-ES' => 'es',
        'es_mx' => 'es',
        'es-MX' => 'es',
    ];

    private const HTML_LANG = [
        'pt_BR' => 'pt-BR',
        'en' => 'en',
        'es' => 'es',
    ];

    private const CATALOG_FILES = [
        'pt_BR' => 'pt_BR.php',
        'en' => 'en.php',
        'es' => 'es.php',
    ];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    private string $language;

    public static function fromConfig(array $config): self
    {
        return new self((string) ($config['language'] ?? self::DEFAULT_LANGUAGE));
    }

    public function __construct(string $language = self::DEFAULT_LANGUAGE)
    {
        $this->language = self::normalizeLanguage($language);
    }

    public function language(): string
    {
        return $this->language;
    }

    public function htmlLang(): string
    {
        return self::HTML_LANG[$this->language] ?? self::HTML_LANG[self::DEFAULT_LANGUAGE];
    }

    public function t(string $key, array $replace = []): string
    {
        $line = self::catalog($this->language)[$key]
            ?? self::catalog(self::DEFAULT_LANGUAGE)[$key]
            ?? $key;

        foreach ($replace as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $language): array
    {
        if (isset(self::$catalogs[$language])) {
            return self::$catalogs[$language];
        }

        $file = self::CATALOG_FILES[$language] ?? self::CATALOG_FILES[self::DEFAULT_LANGUAGE];
        $catalog = require __DIR__ . '/Languages/' . $file;

        return self::$catalogs[$language] = is_array($catalog) ? $catalog : [];
    }

    private static function normalizeLanguage(string $language): string
    {
        $language = trim($language);
        if (isset(self::LANGUAGES[$language])) {
            return self::LANGUAGES[$language];
        }

        $key = strtolower(str_replace('-', '_', $language));
        if (isset(self::LANGUAGES[$key])) {
            return self::LANGUAGES[$key];
        }

        return self::DEFAULT_LANGUAGE;
    }
}
