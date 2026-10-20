<?php

namespace App\Services;

use App\Models\CodeSet;
use App\Models\DatasetColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * แปลงรหัสเป็นชื่อ และจัดกลุ่มตามโครงสร้างงบประมาณ (spec FR-15)
 */
class CodeLookup
{
    /** @var Collection<int, CodeSet>|null */
    private ?Collection $sets = null;

    /** @var array<int, array<string, string>> */
    private array $labels = [];

    /**
     * @return Collection<int, CodeSet>
     */
    public function sets(): Collection
    {
        return $this->sets ??= CodeSet::with('groupRules')->orderBy('sort')->get();
    }

    public function setForColumn(string $header): ?CodeSet
    {
        return $this->sets()->first(fn (CodeSet $s) => $s->matchesColumn($header));
    }

    public function label(int $setId, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $this->labels[$setId] ??= CodeSet::find($setId)?->values()->pluck('label', 'code')->mapWithKeys(fn ($l, $c) => [(string) $c => $l])->all() ?? [];

        return $this->labels[$setId][(string) $code] ?? null;
    }

    /**
     * ชื่อกลุ่มตามกฎของชุดรหัส เช่น "2.1.2 ค่าใช้สอย" → "งบดำเนินงาน"
     */
    public function group(int $setId, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $set = $this->sets()->firstWhere('id', $setId);

        return $set?->groupRules->first(fn ($rule) => $rule->matches($value))?->group_label;
    }

    /**
     * รหัสที่พบในข้อมูลที่นำเข้า แต่ยังไม่มีชื่อในชุดรหัส (FR-15.2)
     *
     * @return array<string, int> รหัส => จำนวนแถวที่พบ
     */
    public function unknownCodes(CodeSet $set): array
    {
        $known = $set->values()->pluck('code')->map(fn ($c) => (string) $c)->flip();
        $found = [];

        DatasetColumn::where('code_set_id', $set->id)->get(['dataset_id', 'slug'])
            ->each(function (DatasetColumn $col) use (&$found) {
                DB::table('dataset_rows')->where('dataset_id', $col->dataset_id)
                    ->selectRaw('json_extract(data, ?) as v, count(*) as n', ['$.'.$col->slug])
                    ->groupBy('v')->get()
                    ->each(function ($r) use (&$found) {
                        $code = trim((string) $r->v, '"');
                        if ($code !== '' && $code !== 'null') {
                            $found[$code] = ($found[$code] ?? 0) + $r->n;
                        }
                    });
            });

        $unknown = array_filter(
            array_diff_key($found, $known->all()),
            fn ($n, $code) => $this->group($set->id, (string) $code) === null,
            ARRAY_FILTER_USE_BOTH,
        );
        ksort($unknown, SORT_NATURAL);

        return $unknown;
    }

    public function flush(): void
    {
        $this->sets = null;
        $this->labels = [];
    }
}
