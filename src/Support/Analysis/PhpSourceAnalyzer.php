<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Analysis;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PhpSourceAnalyzer
{
    public static function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public static function discoverClasses(array $files): array
    {
        $classes = [];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $tokens = token_get_all($code);
            $class = self::classFromTokens($tokens);

            if ($class === '') {
                continue;
            }

            $namespace = self::namespaceFromTokens($tokens);
            $fqcn = $namespace !== '' ? $namespace . '\\' . $class : $class;

            $classes[$fqcn] = [
                'fqcn' => $fqcn,
                'class' => $class,
                'namespace' => $namespace,
                'file' => $file,
                'path' => self::relativePath($file),
                'code' => $code,
                'imports' => self::importsFromCode($code),
                'extends' => self::extendsFromTokens($tokens),
                'methods' => self::extractMethods($code),
                'lines' => substr_count($code, "\n") + 1,
            ];
        }

        ksort($classes);

        return $classes;
    }

    public static function importsFromCode(string $code): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $code, $matches);
        return array_values(array_filter(array_map('trim', $matches[1] ?? [])));
    }

    public static function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }

    private static function extractMethods(string $code): array
    {
        $tokens = token_get_all($code);
        $methods = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                }
            }

            if (! $name) {
                continue;
            }

            $visibility = 'public';
            $static = false;
            for ($k = $i - 1; $k >= max(0, $i - 14); $k--) {
                if ($tokens[$k] === ';' || $tokens[$k] === '}') {
                    break;
                }
                if (is_array($tokens[$k])) {
                    $visibility = match ($tokens[$k][0]) {
                        T_PUBLIC => 'public',
                        T_PROTECTED => 'protected',
                        T_PRIVATE => 'private',
                        default => $visibility,
                    };
                    $static = $static || $tokens[$k][0] === T_STATIC;
                }
            }

            $brace = null;
            $signature = '';
            for ($j = $i; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $brace = $j;
                    break;
                }
                if ($tokens[$j] === ';') {
                    break;
                }
                $signature .= self::tokenText($tokens[$j]);
            }

            if ($brace === null) {
                continue;
            }

            $level = 0;
            $end = $brace;
            for ($j = $brace; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $level++;
                } elseif ($tokens[$j] === '}') {
                    $level--;
                }
                if ($level === 0) {
                    $end = $j;
                    break;
                }
            }

            $body = '';
            for ($j = $brace + 1; $j < $end; $j++) {
                $body .= self::tokenText($tokens[$j]);
            }

            $returnType = null;
            if (preg_match('/\)\s*:\s*([?\\\\A-Za-z0-9_|]+)/', $signature, $match)) {
                $returnType = ltrim($match[1], '?');
            }

            $startLine = is_array($tokens[$i]) ? $tokens[$i][2] : 0;
            $methods[] = [
                'name' => $name,
                'visibility' => $visibility,
                'static' => $static,
                'signature' => trim(preg_replace('/\s+/', ' ', $signature) ?? ''),
                'returnType' => $returnType,
                'startLine' => $startLine,
                'endLine' => $startLine + substr_count($body, "\n") + 1,
                'body' => $body,
            ];
        }

        return $methods;
    }

    private static function namespaceFromTokens(array $tokens): string
    {
        $namespace = '';
        $collect = false;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $collect = true;
                continue;
            }
            if ($collect) {
                if ($token === ';' || $token === '{') {
                    return trim($namespace);
                }
                $namespace .= self::tokenText($token);
            }
        }

        return '';
    }

    private static function classFromTokens(array $tokens): string
    {
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return $tokens[$j][1];
                    }
                }
            }
        }

        return '';
    }

    private static function extendsFromTokens(array $tokens): string
    {
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_EXTENDS) {
                continue;
            }
            $extends = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '{' || (is_array($tokens[$j]) && $tokens[$j][0] === T_IMPLEMENTS)) {
                    break;
                }
                $extends .= self::tokenText($tokens[$j]);
            }

            return trim($extends);
        }

        return '';
    }

    private static function tokenText($token): string
    {
        return is_array($token) ? $token[1] : $token;
    }
}
