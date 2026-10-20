<?php

namespace App\Jobs;

use App\Models\Dataset;
use App\Services\Import\DatasetImporter;
use App\Services\Import\ImportException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportDatasetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $datasetId,
        public readonly string $sheet,
        public readonly int $headerStart,
        public readonly int $headerEnd,
    ) {}

    public function handle(DatasetImporter $importer): void
    {
        $dataset = Dataset::find($this->datasetId);
        if (! $dataset) {
            return;
        }

        try {
            $importer->import($dataset, $this->sheet, $this->headerStart, $this->headerEnd);
        } catch (ImportException $e) {
            $dataset->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Dataset import failed', ['dataset' => $dataset->id, 'error' => $e->getMessage()]);
            $dataset->update(['status' => 'failed', 'error_message' => 'นำเข้าข้อมูลไม่สำเร็จ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ']);
        }
    }
}
