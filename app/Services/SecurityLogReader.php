<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/** Reads the daily security log files (storage/logs/security-YYYY-MM-DD.log). */
class SecurityLogReader
{
    /** @return list<string> file names, newest first */
    public function files(): array
    {
        return collect(File::glob(storage_path('logs/security-*.log')))->map(fn ($f) => basename($f))->sort()->reverse()->values()->all();
    }

    /**
     * Parsed entries from one file (newest first), optionally filtered.
     *
     * @return list<array{time: string, level: string, event: string, context: array}>
     */
    public function entries(?string $file = null, ?callable $filter = null, int $limit = 400): array
    {
        $files = $this->files();
        $file = in_array($file, $files, true) ? $file : ($files[0] ?? null);
        if (! $file) {
            return [];
        }
        $lines = preg_split('/\R/', trim((string) File::get(storage_path('logs/'.$file))));
        $out = [];
        foreach (array_reverse($lines) as $line) {
            if (! preg_match('/^\[(.+?)\] \w+\.(\w+): (\S+) (\{.*\})/', $line, $m)) {
                continue;
            }
            $entry = ['time' => $m[1], 'level' => strtolower($m[2]), 'event' => $m[3], 'context' => json_decode($m[4], true) ?: []];
            if ($filter && ! $filter($entry)) {
                continue;
            }
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Entries across the last $days files matching a filter (newest first). */
    public function search(callable $filter, int $days = 7, int $limit = 50): array
    {
        $out = [];
        foreach (array_slice($this->files(), 0, $days) as $f) {
            $out = array_merge($out, $this->entries($f, $filter, $limit - count($out)));
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
