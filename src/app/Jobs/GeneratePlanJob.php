<?php

namespace App\Jobs;

use App\Models\ReportDraft;
use App\Services\Report\ReportPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $answers
     */
    public function __construct(public readonly int $draftId, public readonly array $answers) {}

    public function handle(ReportPlanner $planner): void
    {
        $draft = ReportDraft::find($this->draftId);
        if (! $draft || $draft->status !== 'planning') {
            return;
        }

        try {
            match (true) {
                $draft->kind === 'refine' => $planner->refine($draft),
                $draft->planned_spec !== null && $draft->instruction !== null => $planner->revisePlan($draft),
                default => $planner->plan($draft, $this->answers),
            };
        } catch (Throwable $e) {
            Log::error('Generate plan failed', ['draft' => $draft->id, 'error' => $e->getMessage()]);
            $draft->update(['status' => 'failed', 'error_message' => 'สร้างรายงานไม่สำเร็จ: '.mb_strimwidth($e->getMessage(), 0, 200, '…')]);
        }
    }
}
