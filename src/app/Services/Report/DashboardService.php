<?php

namespace App\Services\Report;

use App\Models\Dashboard;
use App\Models\DashboardVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * บันทึก Dashboard เป็นเวอร์ชันใหม่ทุกครั้งที่แก้ (spec FR-5.4) และคำนวณตัวเลขใหม่จากข้อมูลจริง
 */
class DashboardService
{
    public function __construct(
        private readonly SpecValidator $validator,
        private readonly AggregationEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: ?DashboardVersion, 1: list<string>}
     */
    public function saveVersion(Dashboard $dashboard, array $spec, User $user, string $source = 'user', ?string $note = null, ?array $summary = null): array
    {
        $dataset = $dashboard->dataset;
        [$clean, $errors] = $this->validator->validate($spec, $dataset->columns()->get(), $dashboard->title);
        if ($errors !== [] || $clean['widgets'] === []) {
            return [null, $errors ?: ['ต้องมีอย่างน้อย 1 ส่วน']];
        }

        $computed = $this->engine->compute($dataset, $clean);
        $current = $dashboard->currentVersion;
        // บทสรุปยังใช้ได้ถ้าตัวเลขไม่เปลี่ยน (แก้แค่หัวข้อ/ตำแหน่ง/ธีม)
        $numbers = fn (?array $c) => array_map(fn ($w) => is_array($w) ? array_diff_key($w, ['title' => 1, 'label' => 1]) : $w, $c ?? []);
        $keepSummary = $summary ?? ($current && $numbers($current->computed_data) == $numbers($computed) ? $current->executive_summary : null);

        $version = DB::transaction(function () use ($dashboard, $clean, $computed, $user, $source, $note, $keepSummary) {
            $next = (int) $dashboard->versions()->max('version') + 1;
            $version = $dashboard->versions()->create([
                'version' => $next,
                'spec' => $clean,
                'computed_data' => $computed,
                'executive_summary' => $keepSummary,
                'source' => $source,
                'created_by' => $user->id,
                'note' => $note,
            ]);
            $dashboard->update(['current_version_id' => $version->id, 'title' => $clean['title']]);

            return $version;
        });

        return [$version, []];
    }

    /**
     * คำนวณตัวอย่างจาก spec ที่กำลังแก้ โดยยังไม่บันทึก
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: list<string>}
     */
    public function preview(Dashboard $dashboard, array $spec): array
    {
        [$clean, $errors] = $this->validator->validate($spec, $dashboard->dataset->columns()->get(), $dashboard->title);
        if ($errors !== [] || ($clean['widgets'] ?? []) === []) {
            return [null, null, $errors ?: ['ต้องมีอย่างน้อย 1 ส่วน']];
        }

        return [$clean, $this->engine->compute($dashboard->dataset, $clean), []];
    }

    public function revert(Dashboard $dashboard, DashboardVersion $from, User $user): DashboardVersion
    {
        [$version] = $this->saveVersion($dashboard, $from->spec, $user, 'user', 'ย้อนกลับไปเวอร์ชัน '.$from->version, $from->executive_summary);

        return $version;
    }
}
