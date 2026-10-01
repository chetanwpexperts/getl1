<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Organization;
use App\Services\Ai\RfqExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Reads one requirement with AI. Runs once (no automatic retry, so no double charge); failures refund a prepaid read. */
class ParseRfqWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $aiJobId) {}

    public function handle(RfqExtractor $extractor): void
    {
        $job = AiJob::withoutGlobalScopes()->find($this->aiJobId);
        if (! $job || $job->status !== 'queued') {
            return;
        }
        $job->update(['status' => 'running']);

        try {
            $result = $extractor->extract([
                'text' => $job->input_text,
                'file_path' => $job->input_file_path ? Storage::disk('local')->path($job->input_file_path) : null,
                'file_ext' => $job->input_file_path ? pathinfo($job->input_file_path, PATHINFO_EXTENSION) : null,
            ]);
            $usage = [
                'model' => $result['meta']['model'],
                'tokens_in' => $result['meta']['tokens_in'],
                'tokens_out' => $result['meta']['tokens_out'],
                'cost_inr' => $result['meta']['cost_inr'],
            ];
            if (! $result['readable']) {
                $job->update($usage);
                self::fail($job->id, RfqExtractor::UNREADABLE_MESSAGE);

                return;
            }
            $job->update($usage + [
                'status' => 'done',
                'output' => ['fields' => $result['fields'], 'warnings' => $result['warnings'], 'uncertain' => $result['uncertain']],
            ]);
        } catch (Throwable $e) {
            Log::warning('ai_rfq_failed', ['ai_job_id' => $job->id, 'error' => $e->getMessage()]);
            self::fail($job->id, $e instanceof RuntimeException
                ? mb_substr($e->getMessage(), 0, 250) : 'Something went wrong reading this. Please try again or fill the form yourself.');
        }
    }

    public function failed(?Throwable $e): void
    {
        self::fail($this->aiJobId, 'Reading took too long. Please try again with a shorter text or smaller file.');
    }

    /**
     * Marks the read failed and gives back a prepaid AI read if one was used. The status change is
     * conditional, so the refund happens exactly once even if this runs twice.
     */
    public static function fail(int $aiJobId, string $error): void
    {
        DB::transaction(function () use ($aiJobId, $error) {
            $changed = AiJob::withoutGlobalScopes()->whereKey($aiJobId)->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
            $job = $changed ? AiJob::withoutGlobalScopes()->find($aiJobId) : null;
            if ($job?->paid_with_credit) {
                Organization::whereKey($job->organization_id)->increment('ai_credits');
                Log::info('ai_credit_refunded', ['ai_job_id' => $aiJobId, 'organization_id' => $job->organization_id]);
            }
        });
    }
}
