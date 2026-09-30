<?php

namespace App\Jobs;

use App\Services\PurchaseOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Number, PDF and emails for an approved award. Safe to run more than once. */
class IssuePurchaseOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300];

    public function __construct(public int $awardId)
    {
        $this->afterCommit();
    }

    public function handle(PurchaseOrderService $po): void
    {
        $po->issue($this->awardId);
    }
}
