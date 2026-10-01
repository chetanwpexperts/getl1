<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is GetL1 healthy right now? Each check returns ok / warn / fail with a plain-English detail.
 * Used by the admin Health page and by getl1:health, which emails an alert when something fails.
 */
class SystemHealth
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /** @return list<array{key: string, label: string, status: string, detail: string}> */
    public function checks(): array
    {
        return array_map(fn ($c) => $this->safe(...$c), [
            ['database', 'Database', fn () => $this->database()],
            ['queue', 'Background jobs (emails, POs, AI)', fn () => $this->queue()],
            ['failed_jobs', 'Failed jobs (24 h)', fn () => $this->failedJobs()],
            ['scheduler', 'Scheduler (auction clock, reminders)', fn () => $this->scheduler()],
            ['realtime', 'Live auction connection server', fn () => $this->realtime()],
            ['disk', 'Disk space', fn () => $this->disk()],
            ['ai', 'AI reading (24 h)', fn () => $this->ai()],
            ['logins', 'Failed logins (last hour)', fn () => $this->logins()],
        ]);
    }

    /** 'ok' | 'warn' | 'fail' for the whole system. */
    public static function overall(array $checks): string
    {
        $s = array_column($checks, 'status');

        return in_array('fail', $s, true) ? 'fail' : (in_array('warn', $s, true) ? 'warn' : 'ok');
    }

    private function safe(string $key, string $label, \Closure $check): array
    {
        try {
            [$status, $detail] = $check();
        } catch (Throwable $e) {
            [$status, $detail] = ['fail', 'Check failed: '.mb_substr($e->getMessage(), 0, 160)];
        }

        return compact('key', 'label', 'status', 'detail');
    }

    private function database(): array
    {
        $t = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $t) * 1000);

        return [$ms > 500 ? 'warn' : 'ok', "Responding in {$ms} ms"];
    }

    private function queue(): array
    {
        if (config('queue.default') === 'sync') {
            return ['ok', 'Runs inline (no worker needed)'];
        }
        $q = DB::table(config('queue.connections.database.table', 'jobs'));
        $waiting = (clone $q)->where('available_at', '<=', now()->getTimestamp())->whereNull('reserved_at')->count();
        $oldest = (clone $q)->where('available_at', '<=', now()->getTimestamp())->whereNull('reserved_at')->min('available_at');
        $age = $oldest ? now()->getTimestamp() - (int) $oldest : 0;
        $status = $age > 300 || $waiting > 500 ? 'fail' : ($age > 60 || $waiting > 100 ? 'warn' : 'ok');

        return [$status, $waiting === 0 ? 'Nothing waiting' : "{$waiting} waiting, oldest ".self::ago($age).($status === 'fail' ? '. Is the queue worker running?' : '')];
    }

    private function failedJobs(): array
    {
        $n = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return [$n > 10 ? 'fail' : ($n > 0 ? 'warn' : 'ok'), $n === 0 ? 'None' : "{$n} failed. Check with: php artisan queue:failed"];
    }

    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        if (! $last) {
            return ['fail', 'No heartbeat yet. Is the cron entry for schedule:run in place?'];
        }
        $age = now()->getTimestamp() - (int) $last;

        return [$age > 180 ? 'fail' : 'ok', 'Last ran '.self::ago($age).' ago'];
    }

    private function realtime(): array
    {
        if (! in_array(config('broadcasting.default'), ['reverb', 'pusher'], true)) {
            return ['ok', 'Off: auction pages update every 2 seconds instead'];
        }
        $host = config('reverb.servers.reverb.host', '127.0.0.1');
        $host = in_array($host, ['0.0.0.0', '::'], true) ? '127.0.0.1' : $host;
        $port = (int) config('reverb.servers.reverb.port');
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if (! $fp) {
            return ['fail', "Not reachable on {$host}:{$port}. Auction pages fall back to updating every 2 seconds."];
        }
        fclose($fp);

        return ['ok', "Listening on {$host}:{$port}"];
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return ['warn', 'Could not read disk usage'];
        }
        $pct = $free / $total * 100;

        return [$pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'), sprintf('%.1f GB free (%d%%)', $free / 1073741824, round($pct))];
    }

    private function ai(): array
    {
        $rows = DB::table('ai_jobs')->where('created_at', '>=', now()->subDay())->selectRaw("status, count(*) as n")->groupBy('status')->pluck('n', 'status');
        $total = (int) $rows->sum();
        $failed = (int) ($rows['failed'] ?? 0);
        if ($total === 0) {
            return ['ok', 'No reads in the last day'];
        }
        $rate = round($failed / $total * 100);

        return [$total >= 5 && $rate > 30 ? 'warn' : 'ok', "{$total} reads, {$failed} failed or unclear ({$rate}%)"];
    }

    private function logins(): array
    {
        $since = now()->subHour();
        $n = count(app(SecurityLogReader::class)->entries(null, fn ($e) => $e['event'] === 'login_failed'
            && strtotime($e['time']) >= $since->getTimestamp(), 1000));

        return [$n > 50 ? 'fail' : ($n > 20 ? 'warn' : 'ok'), $n === 0 ? 'None' : "{$n} failed logins".($n > 20 ? '. Possible password guessing; see the Security log.' : '')];
    }

    private static function ago(int $s): string
    {
        return $s < 60 ? "{$s} s" : ($s < 3600 ? intdiv($s, 60).' min' : intdiv($s, 3600).' h');
    }
}
