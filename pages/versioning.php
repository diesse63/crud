<?php
/**
 * versioning.php
 *
 * Gestione centralizzata delle versioni del creator e dei file generati.
 * Major condiviso e modificato manualmente.
 * Minor incrementato automaticamente a ogni aggiornamento.
 *
 * Versione: 1.0
 */

declare(strict_types=1);

if (!function_exists('crudVersionNormalize')) {
    function crudVersionNormalize(string $version, string $defaultVersion = '1.0'): string
    {
        $defaultVersion = preg_match('/^\d+\.\d+$/', $defaultVersion) ? $defaultVersion : '1.0';
        $version = trim($version);

        if (!preg_match('/^(\d+)\.(\d+)/', $version, $matches)) {
            return $defaultVersion;
        }

        return (int) $matches[1] . '.' . (int) $matches[2];
    }
}

if (!function_exists('crudVersionIncrement')) {
    function crudVersionIncrement(string $version, string $defaultVersion = '1.0'): string
    {
        $normalizedVersion = crudVersionNormalize($version, $defaultVersion);
        [$major, $minor] = array_map('intval', explode('.', $normalizedVersion, 2));

        return $major . '.' . ($minor + 1);
    }
}

if (!function_exists('resolveNextGeneratedPageVersion')) {
    function resolveNextGeneratedPageVersion(string $targetPath, string $defaultVersion = '1.0'): string
    {
        $defaultVersion = crudVersionNormalize($defaultVersion, '1.0');

        if (!is_file($targetPath) || !is_readable($targetPath)) {
            return $defaultVersion;
        }

        $contents = file_get_contents($targetPath);
        if (!is_string($contents) || $contents === '') {
            return $defaultVersion;
        }

        $patterns = [
            '/\$generatedPageVersion\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/',
            '/\'generated_page_version\'\s*=>\s*[\'"]([^\'"]+)[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents, $matches)) {
                return crudVersionIncrement((string) $matches[1], $defaultVersion);
            }
        }

        return $defaultVersion;
    }
}
