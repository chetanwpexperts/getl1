<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Jobs\ParseRfqWithAi;
use App\Models\AiJob;
use App\Services\Ai\Claude;
use App\Services\AuditLogger;
use App\Services\Billing\PlanService;
use App\Services\FileGuard;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Create with AI": the buyer pastes a requirement or uploads a file; AI fills the normal RFQ
 * form, which the buyer reviews and saves. Nothing reaches suppliers without that review.
 */
class AiRfqController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function store(Request $request, PlanService $plans, Claude $claude, FileGuard $guard, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'ai_text' => ['nullable', 'string', 'max:30000'],
            'ai_file' => ['nullable', 'file'],
        ]);
        if (blank($data['ai_text'] ?? null) && ! $request->hasFile('ai_file')) {
            throw ValidationException::withMessages(['ai_text' => 'Paste your requirement or upload a file.']);
        }

        $org = $this->current->get();
        $allowance = $plans->aiAllowance($org);
        if (! $claude->isConfigured()) {
            throw ValidationException::withMessages(['ai_text' => 'AI reading is being set up. Please fill the form below for now.']);
        }
        if (! $allowance['enabled']) {
            throw ValidationException::withMessages(['ai_text' => 'Creating RFQs with AI is part of the Growth plan and above.']);
        }
        if ($allowance['left'] === 0) {
            throw ValidationException::withMessages(['ai_text' => "You've used all {$allowance['limit']} AI reads this month. Fill the form below, or upgrade for more."]);
        }

        $path = null;
        if ($request->hasFile('ai_file')) {
            $ext = $guard->check($request->file('ai_file'), [FileGuard::PDF, FileGuard::JPG, FileGuard::PNG, FileGuard::XLSX], 10 * 1024, 'ai_rfq_input', 'ai_file');
            $path = $request->file('ai_file')->storeAs("ai-inputs/{$org->id}", Str::random(40).'.'.$ext, 'local');
        }

        $job = AiJob::create([
            'organization_id' => $org->id,
            'user_id' => $request->user()->id,
            'type' => 'rfq_parse',
            'input_text' => filled($data['ai_text'] ?? null) ? trim($data['ai_text']) : null,
            'input_file_path' => $path,
            'status' => 'queued',
        ]);
        $audit->log('ai_rfq_requested', $job, after: ['file' => (bool) $path, 'chars' => mb_strlen((string) $job->input_text)]);
        ParseRfqWithAi::dispatch($job->id);

        return redirect()->route('buyer.rfqs.ai.show', $job->id);
    }

    public function show(int $job): View|RedirectResponse
    {
        $job = $this->find($job);
        if ($job->status === 'done') {
            return redirect()->route('buyer.rfqs.ai.use', $job->id);
        }

        return view('buyer.rfqs.ai-wait', ['job' => $job]);
    }

    public function status(int $job): JsonResponse
    {
        $job = $this->find($job);

        return response()->json([
            'status' => $job->status,
            'error' => $job->status === 'failed' ? $job->error : null,
            'next' => $job->status === 'done' ? route('buyer.rfqs.ai.use', $job->id) : null,
        ])->header('Cache-Control', 'no-store');
    }

    /** Open the normal RFQ form, filled with what the AI read, for the buyer to check and save. */
    public function use(Request $request, int $job): RedirectResponse
    {
        $job = $this->find($job);
        abort_unless($job->status === 'done' && is_array($job->output['fields'] ?? null), 404);
        $job->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        $fields = $job->output['fields'];
        $request->session()->flashInput($fields + ['quote_deadline' => '']);

        return redirect()->route('buyer.rfqs.create')
            ->with('ai_warnings', $job->output['warnings'] ?? [])
            ->with('status', 'AI filled the form from your requirement. Please check every item, set the quote deadline and save.');
    }

    private function find(int $id): AiJob
    {
        // Scoped to the current company by the model's tenancy scope.
        return AiJob::where('type', 'rfq_parse')->findOrFail($id);
    }
}
