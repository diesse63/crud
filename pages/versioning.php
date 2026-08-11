<?php
/**
 * versioning.php
 *
 * Gestione centralizzata delle versioni del creator e dei file generati.
 *
 * Versione: 1.0
 */

declare(strict_types=1);

if (!function_exists('crudVersionNormalize')) {
    function crudVersionNormalize(string $version, string $defaultVersion = '1.0'): string
    {
        $defaultVersion = preg_match('/^\d+\.\d+$/', $defaultVersion) ? $defaultVersion : '1.0';
        $version = trim($version);

        if (!preg_match('/^(\d+)\.(\d+)$/', $version, $matches)) {
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

