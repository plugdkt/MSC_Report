<?php

namespace App\Services\ExcelTemplate;

use App\Models\Dataset;
use App\Models\DatasetColumn;
use Illuminate\Support\Facades\DB;

/**
 * รวมแบบฟอร์ม Excel: แบบมาตรฐานใน config/excel_templates.php และแบบที่สร้างจากข้อมูลที่นำเข้าแล้ว (FR-14.5)
 */
class TemplateRegistry
{
    private const DATASET_PREFIX = 'dataset:';

    private const MAX_OPTIONS = 100;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function standard(): array
    {
        return config('excel_templates');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        if (str_starts_with($key, self::DATASET_PREFIX)) {
            $dataset = Dataset::find((int) substr($key, strlen(self::DATASET_PREFIX)));

            return $dataset ? $this->fromDataset($dataset) : null;
        }

        return $this->standard()[$key] ?? null;
    }

    public function keyForDataset(Dataset $dataset): string
    {
        return self::DATASET_PREFIX.$dataset->id;
    }

    /**
     * แบบฟอร์มสำหรับกรอกข้อมูลงวดถัดไป: หัวตารางตามชื่อคอลัมน์ที่ตั้งไว้ และ dropdown จากค่าที่เคยมี
     *
     * @return array<string, mixed>
     */
    public function fromDataset(Dataset $dataset): array
    {
        $fields = $dataset->columns()->where('is_hidden', false)->get()->map(function (DatasetColumn $c) use ($dataset) {
            $type = $c->type();
            $options = [];
            if (in_array($type, ['category', 'code'], true) && ($c->profile['unique'] ?? 0) <= self::MAX_OPTIONS) {
                $options = DB::table('dataset_rows')->where('dataset_id', $dataset->id)
                    ->selectRaw('json_extract(data, ?) as v', ['$.'.$c->slug])->distinct()->pluck('v')
                    ->map(fn ($v) => trim((string) $v, '"'))->filter(fn ($v) => $v !== '' && $v !== 'null')
                    ->sort(SORT_NATURAL)->values()->all();
            }

            return [
                'key' => $c->slug,
                'label' => $c->display_name,
                'type' => $type,
                'required' => false,
                'width' => max(12, min(42, mb_strlen($c->display_name) + 6)),
                'options' => $options,
                'help' => '',
                'example' => (string) ($c->profile['samples'][0] ?? ''),
                'code_set_id' => $c->code_set_id,
            ];
        })->all();

        return [
            'name' => 'แบบฟอร์มกรอกข้อมูล: '.$dataset->name,
            'description' => 'สร้างจากข้อมูลที่นำเข้า '.($dataset->period_label ? 'งวด '.$dataset->period_label : '').' — ใช้กรอกข้อมูลงวดถัดไปให้โครงสร้างเหมือนเดิม',
            'fields' => $fields,
        ];
    }
}
