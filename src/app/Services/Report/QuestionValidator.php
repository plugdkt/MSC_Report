<?php

namespace App\Services\Report;

/**
 * ตรวจคำถามจาก AI ให้อยู่ในรูปแบบที่หน้าจอแสดงได้ และอ้างคอลัมน์ที่มีจริง (spec FR-3.2–3.4)
 */
class QuestionValidator
{
    private const TYPES = ['single_choice', 'multi_choice', 'text', 'number'];

    private const MAX_QUESTIONS = 6;

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string>  $columnIds
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: ?string} [คำถาม, ข้อผิดพลาด, understanding]
     */
    public function validate(?array $data, array $columnIds, int $min = 2): array
    {
        if (! is_array($data) || ! isset($data['questions']) || ! is_array($data['questions'])) {
            return [[], ['ต้องมี key "questions" เป็น array'], null];
        }

        $errors = [];
        $questions = [];
        $ids = [];
        foreach (array_slice($data['questions'], 0, self::MAX_QUESTIONS) as $i => $q) {
            $n = $i + 1;
            if (! is_array($q) || ! is_string($q['text'] ?? null) || trim($q['text']) === '') {
                $errors[] = "คำถามข้อ {$n} ไม่มี text";

                continue;
            }
            $type = in_array($q['type'] ?? null, self::TYPES, true) ? $q['type'] : (isset($q['options']) ? 'single_choice' : 'text');
            $id = preg_replace('/[^a-z0-9_]/i', '', (string) ($q['id'] ?? '')) ?: 'q'.$n;
            if (in_array($id, $ids, true) || in_array($id, ['audience', 'period', 'measures', 'breakdown'], true)) {
                $id = 'q'.$n.'_'.$id;
            }
            $ids[] = $id;

            $options = [];
            if (in_array($type, ['single_choice', 'multi_choice'], true)) {
                foreach ((array) ($q['options'] ?? []) as $o) {
                    $value = is_array($o) ? (string) ($o['value'] ?? '') : (string) $o;
                    $label = is_array($o) ? (string) ($o['label'] ?? $value) : (string) $o;
                    if ($value !== '' && trim($label) !== '') {
                        $options[] = ['value' => mb_substr($value, 0, 120), 'label' => mb_substr(trim($label), 0, 200)];
                    }
                }
                if (count($options) < 2) {
                    $errors[] = "คำถามข้อ {$n} ต้องมีตัวเลือกอย่างน้อย 2 ข้อ";

                    continue;
                }
                if (! collect($options)->contains('value', RuleQuestions::AI_DECIDE)) {
                    $options[] = ['value' => RuleQuestions::AI_DECIDE, 'label' => 'ให้ AI เลือกให้'];
                }
            }

            $questions[] = array_filter([
                'id' => $id,
                'type' => $type,
                'text' => mb_substr(trim($q['text']), 0, 300),
                'hint' => is_string($q['hint'] ?? null) ? mb_substr($q['hint'], 0, 300) : null,
                'options' => $options ?: null,
                'related_columns' => array_values(array_intersect((array) ($q['related_columns'] ?? []), $columnIds)),
                'source' => 'ai',
            ], fn ($v) => $v !== null);
        }

        if (count($questions) < $min) {
            $errors[] = "ต้องมีคำถามที่ใช้ได้อย่างน้อย {$min} ข้อ";
        }

        $understanding = is_string($data['understanding'] ?? null) ? mb_substr(trim($data['understanding']), 0, 500) : null;

        return [$questions, $errors, $understanding];
    }
}
