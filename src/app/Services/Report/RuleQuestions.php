<?php

namespace App\Services\Report;

use App\Support\Thai;
use Illuminate\Support\Carbon;

/**
 * คำถามมาตรฐานที่สร้างจาก profile ได้โดยไม่ใช้ AI (spec §6.5 โหมดไม่ใช้ AI / คำถามพื้นฐาน)
 */
class RuleQuestions
{
    public const AI_DECIDE = 'ai_decide';

    /**
     * @param  array<string, mixed>  $profile
     * @return list<array<string, mixed>>
     */
    public function build(array $profile): array
    {
        $cols = collect($profile['columns']);
        $questions = [];

        $date = $cols->firstWhere('type', 'date');
        if ($date && isset($date['from']) && substr($date['from'], 0, 7) !== substr($date['to'], 0, 7)) {
            $last = Carbon::parse($date['to']);
            $questions[] = [
                'id' => 'period',
                'type' => 'single_choice',
                'text' => 'ต้องการสรุปช่วงเวลาใด?',
                'hint' => 'ข้อมูลมีตั้งแต่ '.Thai::date($date['from']).' ถึง '.Thai::date($date['to']),
                'options' => [
                    ['value' => 'all', 'label' => 'ทั้งหมดในไฟล์'],
                    ['value' => 'last_month', 'label' => 'เฉพาะเดือนล่าสุด ('.Thai::date($last->copy()->startOfMonth()).' – '.Thai::date($last).')'],
                    ['value' => 'by_quarter', 'label' => 'แยกรายไตรมาส (ปีงบประมาณ)'],
                    ['value' => self::AI_DECIDE, 'label' => 'ให้ระบบเลือกให้'],
                ],
                'related_columns' => [$date['id']],
            ];
        }

        $numeric = $cols->filter(fn ($c) => in_array($c['type'], ['currency', 'number', 'integer', 'percent'], true) && ($c['sum'] ?? 0) != 0)
            ->sortByDesc(fn ($c) => ($c['type'] === 'currency' ? 1e15 : 0) + abs($c['sum'] ?? 0))->take(8);
        if ($numeric->isNotEmpty()) {
            $questions[] = [
                'id' => 'measures',
                'type' => 'multi_choice',
                'text' => 'ต้องการเน้นตัวเลขใดในรายงาน? (เลือกได้หลายข้อ)',
                'options' => [
                    ...$numeric->map(fn ($c) => ['value' => $c['id'], 'label' => $c['name']])->values()->all(),
                    ['value' => self::AI_DECIDE, 'label' => 'ให้ระบบเลือกให้'],
                ],
                'related_columns' => $numeric->pluck('id')->values()->all(),
            ];
        }

        $breakdowns = [];
        foreach ($cols as $c) {
            if (! in_array($c['type'], ['category', 'code'], true) || ($c['unique'] ?? 0) < 2) {
                continue;
            }
            if (! empty($c['groups']) && count($c['groups']) >= 2) {
                $breakdowns[] = ['value' => $c['id'].':group', 'label' => $c['name'].' (จัดกลุ่ม: '.implode(', ', array_slice(array_keys($c['groups']), 0, 3)).'…)'];
            }
            if (($c['unique'] ?? 0) <= 40) {
                $breakdowns[] = ['value' => $c['id'], 'label' => $c['name'].' ('.$c['unique'].' ค่า)'];
            }
        }
        if ($breakdowns !== []) {
            $questions[] = [
                'id' => 'breakdown',
                'type' => 'single_choice',
                'text' => 'ต้องการแยกดูตามอะไร?',
                'options' => [...array_slice($breakdowns, 0, 8), ['value' => 'none', 'label' => 'ไม่ต้องแยก'], ['value' => self::AI_DECIDE, 'label' => 'ให้ระบบเลือกให้']],
                'related_columns' => [],
            ];
        }

        $questions[] = $this->audienceQuestion();

        return $questions;
    }

    /**
     * @return array<string, mixed>
     */
    public function audienceQuestion(): array
    {
        return [
            'id' => 'audience',
            'type' => 'single_choice',
            'text' => 'ใครเป็นผู้อ่านรายงานนี้?',
            'options' => [
                ['value' => 'executive', 'label' => 'ผู้บริหาร — สั้น เน้นภาพรวม'],
                ['value' => 'supervisor', 'label' => 'หัวหน้างาน — มีรายละเอียดเพิ่ม'],
            ],
            'related_columns' => [],
        ];
    }
}
