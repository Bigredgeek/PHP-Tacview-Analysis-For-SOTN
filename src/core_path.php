<?php

declare(strict_types=1);

if (!function_exists('tacview_resolve_core_path')) {
    /**
     * Locate the shared Tacview core directory by probing common install paths.
     */
    function tacview_resolve_core_path(?string $configuredPath, string $contextDir): string
    {
        $contextDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $contextDir), DIRECTORY_SEPARATOR);
        if ($contextDir === '') {
            $contextDir = DIRECTORY_SEPARATOR;
        }

        $candidates = [];
        $seen = [];

        $addCandidate = static function (?string $path) use (&$candidates, &$seen): void {
            if ($path === null || $path === '') {
                return;
            }

            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if ($normalized === '') {
                return;
            }

            $normalized = rtrim($normalized, DIRECTORY_SEPARATOR);
            if ($normalized === '') {
                return;
            }

            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $candidates[] = $normalized;
            }
        };

        $envPath = getenv('TACVIEW_CORE_PATH');
        if ($envPath !== false) {
            if (!preg_match('/^(?:[A-Za-z]:)?[\\\/]/', (string) $envPath)) {
                $envPath = $contextDir . DIRECTORY_SEPARATOR . $envPath;
            }
            $addCandidate((string) $envPath);
        }

        $configuredPath = trim((string) ($configuredPath ?? ''));
        if ($configuredPath !== '') {
            $addCandidate($contextDir . DIRECTORY_SEPARATOR . $configuredPath);
            $addCandidate(dirname($contextDir) . DIRECTORY_SEPARATOR . $configuredPath);
        }

        $addCandidate($contextDir . DIRECTORY_SEPARATOR . 'core');
        $addCandidate(dirname($contextDir) . DIRECTORY_SEPARATOR . 'core');
        $addCandidate($contextDir . DIRECTORY_SEPARATOR . 'php-tacview-core');
        $addCandidate(dirname($contextDir) . DIRECTORY_SEPARATOR . 'php-tacview-core');

        foreach ($candidates as $candidate) {
            if (is_file($candidate . DIRECTORY_SEPARATOR . 'tacview.php')) {
                return $candidate;
            }
        }

        $searched = $candidates === [] ? '[none]' : implode(', ', $candidates);
        throw new RuntimeException('Unable to locate Tacview core assets. Checked: ' . $searched);
    }
}
