<?php

namespace App\Services\Report;

use App\Models\DashboardVersion;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Services\Ai\JsonExtractor;

/**
 * บทสรุปสำหรับผู้บริหาร (spec FR-4.4): AI เขียนจากตัวเลขที่ระบบคำนวณแล้วเท่านั้น
 * แล้วตรวจว่าทุกตัวเลขในข้อความตรงกับผลคำนวณ — ข้อที่ตรวจไม่ผ่านจะถูกตัดทิ้ง ถ้าเหลือไม่พอใช้แบบมาตรฐาน
 */
class NarrativeService
{
    private const MIN_BULLETS = 2;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly Prompts $prompts,
        private readonly NarrativeVerifier $verifier,
    ) {}

    /**
     * @return string|null ข้อความแจ้งผู้ใช้ (กรณีถอยกลับไปแบบมาตรฐาน)
     */
    public function generate(DashboardVersion $version, User $user, bool $useAi): ?string
    {
        $facts = $this->verifier->facts($version->spec, $version->computed_data);
        $notice = null;

        if ($useAi && $facts !== []) {
            try {
                $bullets = $this->fromAi($version, $user, $facts);
                if (count($bullets) >= self::MIN_BULLETS) {
                    $version->update(['executive_summary' => ['source' => 'ai', 'bullets' => $bullets, 'generated_at' => now()->toIso8601String()]]);

                    return null;
                }
                $notice = 'บทสรุปจาก AI มีตัวเลขที่ตรวจสอบไม่ผ่าน จึงใช้บทสรุปแบบมาตรฐานแทน';
            } catch (AiException $e) {
                $notice = 'ใช้บทสรุปแบบมาตรฐาน เพราะ: '.$e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $notice = 'ใช้บทสรุปแบบมาตรฐาน เพราะเรียก AI ไม่สำเร็จ';
            }
        }

        $version->update(['executive_summary' => ['source' => 'rule', 'bullets' => $this->fromRules($version), 'generated_at' => now()->toIso8601String()]]);

        return $notice;
    }

    /**
     * @param  list<array{id:string, label:string, value:float, unit:?string}>  $facts
     * @return list<array{text:string, verified:bool}>
     */
    private function fromAi(DashboardVersion $version, User $user, array $facts): array
    {
        $request = $this->prompts->narrative($version->spec['title'], $facts);
        $response = $this->ai->complete($user, $request, $version->dashboard->dataset);
        $data = JsonExtractor::decode($response->text);
        $bullets = $this->verifier->verify((array) ($data['summary'] ?? []), $facts);
        $bad = array_filter($bullets, fn ($b) => ! $b['verified']);

        if ($bad !== [] && ! $response->truncated) {
            $errors = array_map(fn ($b) => '"'.mb_strimwidth($b['text'], 0, 80, '…').'" มีตัวเลขที่ไม่อยู่ในข้อมูล: '.implode(', ', $b['unmatched']), $bad);
            $retry = $this->prompts->repair($request, $response->text, [...array_values($errors), 'ใช้เฉพาะตัวเลขจากรายการ facts ห้ามคำนวณเพิ่ม']);
            $response = $this->ai->complete($user, $retry, $version->dashboard->dataset, useCache: false);
            $bullets = $this->verifier->verify((array) (JsonExtractor::decode($response->text)['summary'] ?? []), $facts);
        }

        $ok = array_values(array_filter($bullets, fn ($b) => $b['verified']));
        if (count($ok) >= self::MIN_BULLETS) {
            $this->ai->storeCache($request, $response);
        }

        return array_map(fn ($b) => ['text' => $b['text'], 'verified' => true], $ok);
    }

    /**
     * บทสรุปแบบมาตรฐาน (ไม่ใช้ AI) จาก KPI และกราฟแรก
     *
     * @return list<array{text:string, verified:bool}>
     */
    public function fromRules(DashboardVersion $version): array
    {
        $out = [];
        $computed = $version->computed_data;

        foreach ($version->spec['widgets'] as $w) {
            $d = $computed[$w['id']] ?? null;
            if (! $d || count($out) >= 4) {
                continue;
            }
            if (in_array($w['type'], ['kpi', 'gauge'], true) && $d['value'] !== null) {
                $text = $w['title'].' '.NarrativeVerifier::format($d['value'], $d['unit']);
                if ($w['type'] === 'gauge' && ($d['target'] ?? null) !== null) {
                    $text .= $d['value'] >= $d['target'] ? ' ถึงเป้าหมาย '.NarrativeVerifier::format($d['target'], '%') : ' ยังไม่ถึงเป้าหมาย '.NarrativeVerifier::format($d['target'], '%');
                }
                $out[] = ['text' => $text, 'verified' => true];
            } elseif (in_array($w['type'], ['bar', 'pie'], true) && ! empty($d['categories'])) {
                $data = $d['series'][0]['data'];
                $top = array_keys($data, max($data))[0];
                $total = array_sum($data);
                $share = $total != 0 ? round($data[$top] / $total * 100, 1) : null;
                $out[] = ['text' => $w['title'].': มากที่สุดคือ '.$d['categories'][$top].' '.NarrativeVerifier::format($data[$top], $d['series'][0]['unit']).($share !== null && $d['series'][0]['unit'] !== '%' ? ' ('.NarrativeVerifier::format($share, '%').' ของทั้งหมด)' : ''), 'verified' => true];
            }
        }

        return $out;
    }
}
