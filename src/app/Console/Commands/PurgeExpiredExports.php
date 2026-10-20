<?php

namespace App\Console\Commands;

use App\Models\Export;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredExports extends Command
{
    protected $signature = 'exports:purge';

    protected $description = 'ลบไฟล์ที่ส่งออกและหมดอายุตามนโยบาย (ค่าเริ่มต้น 7 วัน)';

    public function handle(): int
    {
        $count = 0;
        Export::where('expires_at', '<', now())->chunkById(100, function ($exports) use (&$count) {
            foreach ($exports as $export) {
                if ($export->file_path) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->delete();
                $count++;
            }
        });
        $this->info("ลบไฟล์ส่งออกที่หมดอายุ {$count} รายการ");

        return self::SUCCESS;
    }
}
