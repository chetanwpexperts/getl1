<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\RfqExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Reads one requirement with AI. Runs once (no automatic retry, so no double charge). */
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
            $job->update([
                'status' => 'done',
                'output' => ['fields' => $result['fields'], 'warnings' => $result['warnings']],
                'model' => $result['meta']['model'],
                'tokens_in' => $result['meta']['tokens_in'],
                'tokens_out' => $result['meta']['tokens_out'],
                'cost_inr' => $result['meta']['cost_inr'],
            ]);
        } catch (Throwable $e) {
            Log::warning('ai_rfq_failed', ['ai_job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => $e instanceof RuntimeException
                ? mb_substr($e->getMessage(), 0, 250) : 'Something went wrong reading this. Please try again or fill the form yourself.']);
        }
    }

    public function failed(?Throwable $e): void
    {
        AiJob::withoutGlobalScopes()->whereKey($this->aiJobId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => 'Reading took too long. Please try again with a shorter text or smaller file.']);
    }
}
