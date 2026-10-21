<?php

namespace App\Jobs;

use App\Models\ReportDraft;
use App\Services\Report\ReportPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * สร้างรายงานจากแผนที่ผู้ใช้ตรวจแล้ว (คำนวณตัวเลข + บทสรุปผู้บริหาร)
 */
class BuildReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<string>  $keep
     * @param  list<string>  $add
     */
    public function __construct(public readonly int $draftId, public readonly array $keep, public readonly array $add = []) {}

    public function handle(ReportPlanner $planner): void
    {
        $draft = ReportDraft::find($this->draftId);
        if (! $draft || $draft->status !== 'building') {
            return;
        }

        try {
            $planner->build($draft, $this->keep, $this->add);
        } catch (Throwable $e) {
            Log::error('Build report failed', ['draft' => $draft->id, 'error' => $e->getMessage()]);
            $draft->update(['status' => 'review', 'error_message' => 'สร้างรายงานไม่สำเร็จ: '.mb_strimwidth($e->getMessage(), 0, 200, '…')]);
        }
    }
}
