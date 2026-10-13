<?php

namespace App\Support\Integrations;

class PluginPackage
{
    /**
     * @return array{path: string, file: string, version: string, size_bytes: int, sha256: string}|null
     */
    public function latest(): ?array
    {
        $directory = rtrim((string) config('watchdog.plugin_dir'), '/');
        $best = null;

        foreach (glob($directory.'/business-watchdog-*.zip') ?: [] as $path) {
            if (preg_match('/business-watchdog-(\d+\.\d+\.\d+)\.zip$/', $path, $match) !== 1 || ! is_file($path)) {
                continue;
            }

            if ($best === null || version_compare($match[1], $best['version'], '>')) {
                $best = ['path' => $path, 'version' => $match[1]];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'path' => $best['path'],
            'file' => basename($best['path']),
            'version' => $best['version'],
            'size_bytes' => (int) filesize($best['path']),
            'sha256' => (string) hash_file('sha256', $best['path']),
        ];
    }
}
