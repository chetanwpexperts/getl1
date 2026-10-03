<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\RfqQuestion;
use App\Services\RfqQuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Buyer side of RFQ clarifications. The RFQ lookup is scoped to the buyer's company (404 otherwise). */
class QuestionController extends Controller
{
    public function __construct(private RfqQuestionService $questions) {}

    public function answer(Request $request, int $rfq, int $question): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $q = RfqQuestion::where('rfq_id', $rfq->id)->where('organization_id', $rfq->organization_id)->findOrFail($question);
        $data = $request->validate([
            'answer' => ['required', 'string', 'max:'.RfqQuestionService::MAX_LENGTH],
            'visibility' => ['required', 'in:all,private'],
        ], ['answer.required' => 'Type your answer.']);

        $this->questions->answer($q, $request->user(), $data['answer'], $data['visibility']);

        return redirect()->to(route('buyer.rfqs.show', $rfq->id).'#questions')->with('status', $data['visibility'] === 'all'
            ? 'Answer sent to every supplier on this RFQ (without naming who asked).'
            : 'Answer sent only to the supplier who asked.');
    }

    public function announce(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate(['text' => ['required', 'string', 'max:'.RfqQuestionService::MAX_LENGTH]],
            ['text.required' => 'Type the clarification.']);
        $this->questions->announce($rfq, $request->user(), $data['text']);

        return redirect()->to(route('buyer.rfqs.show', $rfq->id).'#questions')->with('status', 'Clarification sent to every supplier on this RFQ.');
    }
}
