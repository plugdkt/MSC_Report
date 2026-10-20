<?php

namespace App\Jobs;

use App\Models\ReportDraft;
use App\Services\Report\ReportPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateQuestionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $draftId) {}

    public function handle(ReportPlanner $planner): void
    {
        $draft = ReportDraft::find($this->draftId);
        if (! $draft || $draft->status !== 'asking') {
            return;
        }

        try {
            $planner->ask($draft);
        } catch (Throwable $e) {
            Log::error('Generate questions failed', ['draft' => $draft->id, 'error' => $e->getMessage()]);
            $draft->update(['status' => 'failed', 'error_message' => 'สร้างคำถามไม่สำเร็จ กรุณาลองใหม่']);
        }
    }
}
