<?php

namespace App\Jobs;

use App\Models\Export;
use App\Services\Export\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $exportId) {}

    public function handle(ExportService $exports): void
    {
        $export = Export::find($this->exportId);
        if ($export && $export->status === 'queued') {
            $exports->run($export);
        }
    }
}
