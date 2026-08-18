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

if (!defined('CRUD_FIXED_VERSION_MAJOR')) {
    define('CRUD_FIXED_VERSION_MAJOR', 1);
}

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

if (!function_exists('crudVersionMajor')) {
    function crudVersionMajor(string $version, string $defaultVersion = '1.0'): int
    {
        $normalizedVersion = crudVersionNormalize($version, $defaultVersion);
        [$major] = array_map('intval', explode('.', $normalizedVersion, 2));

        return $major;
    }
}

if (!function_exists('crudVersionMajorBaseline')) {
    function crudVersionMajorBaseline(string $version, string $defaultVersion = '1.0'): string
    {
        $major = crudVersionMajor($version, $defaultVersion);

        return $major . '.0';
    }
}

if (!function_exists('crudVersionForceMajor')) {
    function crudVersionForceMajor(string $version, int $major = CRUD_FIXED_VERSION_MAJOR, int $defaultMinor = 0): string
    {
        $normalizedVersion = crudVersionNormalize($version, $major . '.' . $defaultMinor);
        [, $minor] = array_map('intval', explode('.', $normalizedVersion, 2));

        return max(0, $major) . '.' . max(0, $minor);
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

if (!function_exists('crudExtractGeneratedFileMetadataFromContent')) {
    function crudExtractGeneratedFileMetadataFromContent(string $contents): array
    {
        $metadata = [
            'creator_version' => '',
            'page_version' => '',
            'file_version' => '',
        ];

        if ($contents === '') {
            return $metadata;
        }

        $creatorPatterns = [
            '/^\s*\*\s*Versione creatore\s*:\s*([0-9]+\.[0-9]+)/mi',
            '/\$generatedVersion\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"]\s*;/i',
        ];
        foreach ($creatorPatterns as $pattern) {
            if (preg_match($pattern, $contents, $matches)) {
                $metadata['creator_version'] = crudVersionNormalize((string) $matches[1]);
                break;
            }
        }

        $pagePatterns = [
            '/^\s*\*\s*Versione pagina\s*:\s*([0-9]+\.[0-9]+)/mi',
            '/\$generatedPageVersion\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"]\s*;/i',
            '/[\'"]generated_page_version[\'"]\s*=>\s*[\'"]([0-9]+\.[0-9]+)[\'"]/i',
        ];
        foreach ($pagePatterns as $pattern) {
            if (preg_match($pattern, $contents, $matches)) {
                $metadata['page_version'] = crudVersionNormalize((string) $matches[1]);
                break;
            }
        }

        $filePatterns = [
            '/^\s*\*?\s*Versione(?:\s+pagina)?\s*:\s*([0-9]+\.[0-9]+)/mi',
        ];
        foreach ($filePatterns as $pattern) {
            if (preg_match($pattern, $contents, $matches)) {
                $metadata['file_version'] = crudVersionNormalize((string) $matches[1]);
                break;
            }
        }

        if ($metadata['file_version'] === '' && $metadata['page_version'] !== '') {
            $metadata['file_version'] = $metadata['page_version'];
        }

        return $metadata;
    }
}

if (!function_exists('crudExtractGeneratedFileMetadataFromFile')) {
    function crudExtractGeneratedFileMetadataFromFile(string $filePath): array
    {
        $metadata = [
            'creator_version' => '',
            'page_version' => '',
            'file_version' => '',
        ];

        if (!is_file($filePath) || !is_readable($filePath)) {
            return $metadata;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return $metadata;
        }

        $contents = (string) fread($handle, 8192);
        fclose($handle);

        return crudExtractGeneratedFileMetadataFromContent($contents);
    }
}

if (!function_exists('resolveNextGeneratedPageVersion')) {
    function resolveNextGeneratedPageVersion(string $targetPath, string $defaultVersion = '1.0'): string
    {
        $defaultVersion = crudVersionForceMajor($defaultVersion);

        if (!is_file($targetPath) || !is_readable($targetPath)) {
            return $defaultVersion;
        }

        $metadata = crudExtractGeneratedFileMetadataFromFile($targetPath);
        $currentVersion = $metadata['page_version'] !== ''
            ? $metadata['page_version']
            : $metadata['file_version'];

        if ($currentVersion === '') {
            return $defaultVersion;
        }

        $normalizedCurrentVersion = crudVersionNormalize($currentVersion, $defaultVersion);
        $normalizedCurrentVersion = crudVersionForceMajor($normalizedCurrentVersion);

        if (crudVersionMajor($normalizedCurrentVersion, $defaultVersion) !== CRUD_FIXED_VERSION_MAJOR) {
            return $defaultVersion;
        }

        return crudVersionIncrement($normalizedCurrentVersion, $defaultVersion);
    }
}

if (!function_exists('crudExpectedGeneratorVersionForType')) {
    function crudExpectedGeneratorVersionForType(string $viewType, string $creatorFile): string
    {
        static $cache = [];

        $viewType = strtoupper(trim($viewType));
        $cacheKey = $creatorFile;

        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = [
                'SCHEDA_SINGOLA' => '1.0',
                'TABELLA_MODALE' => '1.0',
                'MASTER_DETAIL' => '1.0',
            ];

            if (is_file($creatorFile) && is_readable($creatorFile)) {
                $contents = (string) file_get_contents($creatorFile);
                $patterns = [
                    'SCHEDA_SINGOLA' => '/const\s+SCHEDA_SINGOLA_GENERATOR_VERSION\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"]\s*;/i',
                    'TABELLA_MODALE' => '/const\s+SCHEDA_TABELLARE_GENERATOR_VERSION\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"]\s*;/i',
                    'MASTER_DETAIL' => '/const\s+MASTER_DETAIL_GENERATOR_VERSION\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"]\s*;/i',
                ];

                foreach ($patterns as $typeCode => $pattern) {
                    if (preg_match($pattern, $contents, $matches)) {
                        $cache[$cacheKey][$typeCode] = crudVersionNormalize((string) $matches[1]);
                    }
                }
            }
        }

        return $cache[$cacheKey][$viewType] ?? $cache[$cacheKey]['SCHEDA_SINGOLA'];
    }
}
