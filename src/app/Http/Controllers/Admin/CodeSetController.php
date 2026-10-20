<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CodeGroupRule;
use App\Models\CodeSet;
use App\Models\CodeValue;
use App\Models\DatasetColumn;
use App\Services\CodeLookup;
use App\Services\Import\ImportException;
use App\Services\Import\SheetAnalyzer;
use App\Services\Import\SpreadsheetReader;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * จัดการตารางรหัสอ้างอิง (spec FR-15)
 */
class CodeSetController extends Controller
{
    public function index(CodeLookup $lookup): View
    {
        $sets = CodeSet::withCount(['values', 'groupRules'])->orderBy('sort')->orderBy('name')->get();
        $unknown = $sets->mapWithKeys(fn (CodeSet $s) => [$s->id => count($lookup->unknownCodes($s))]);

        return view('admin.codes.index', compact('sets', 'unknown'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash:ascii', 'max:50', 'unique:code_sets,key'],
            'name' => ['required', 'string', 'max:150'],
            'column_pattern' => ['nullable', 'string', 'max:255', $this->regexRule()],
        ]);
        $set = CodeSet::create($data + ['sort' => (int) CodeSet::max('sort') + 1]);

        Audit::log('admin.codes', summary: 'สร้างชุดรหัส');

        return redirect()->route('admin.codes.show', $set)->with('status', 'สร้างชุดรหัสแล้ว');
    }

    public function show(CodeSet $codeSet, CodeLookup $lookup): View
    {
        return view('admin.codes.show', [
            'set' => $codeSet,
            'values' => $codeSet->values()->get(),
            'rules' => $codeSet->groupRules()->get(),
            'unknown' => $lookup->unknownCodes($codeSet),
            'linkedColumns' => DatasetColumn::where('code_set_id', $codeSet->id)->count(),
            'ruleTypes' => CodeGroupRule::TYPES,
        ]);
    }

    public function update(Request $request, CodeSet $codeSet): RedirectResponse
    {
        $codeSet->update($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'column_pattern' => ['nullable', 'string', 'max:255', $this->regexRule()],
        ]));

        Audit::log('admin.codes', summary: 'แก้ตั้งค่าชุดรหัส');

        return back()->with('status', 'บันทึกการตั้งค่าแล้ว (มีผลกับไฟล์ที่นำเข้าครั้งถัดไป)');
    }

    public function storeValue(Request $request, CodeSet $codeSet): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('code_values')->where('code_set_id', $codeSet->id)],
            'label' => ['required', 'string', 'max:255'],
        ]);
        $codeSet->values()->create(['code' => trim($data['code']), 'label' => trim($data['label'])]);

        Audit::log('admin.codes', summary: 'เพิ่มรหัส');

        return back()->with('status', 'เพิ่มรหัส '.$data['code'].' แล้ว');
    }

    public function updateValue(Request $request, CodeSet $codeSet, CodeValue $value): RedirectResponse
    {
        abort_unless($value->code_set_id === $codeSet->id, 404);
        $value->update($request->validate(['label' => ['required', 'string', 'max:255']]));

        Audit::log('admin.codes', summary: 'แก้ชื่อรหัส');

        return back()->with('status', 'บันทึกชื่อของรหัส '.$value->code.' แล้ว');
    }

    public function destroyValue(CodeSet $codeSet, CodeValue $value): RedirectResponse
    {
        abort_unless($value->code_set_id === $codeSet->id, 404);
        $value->delete();

        Audit::log('admin.codes', summary: 'ลบรหัส');

        return back()->with('status', 'ลบรหัส '.$value->code.' แล้ว');
    }

    /**
     * นำเข้ารหัสจาก Excel/CSV: คอลัมน์แรก = รหัส, คอลัมน์ที่สอง = ชื่อ (FR-15.2)
     */
    public function import(Request $request, CodeSet $codeSet, SpreadsheetReader $reader, SheetAnalyzer $analyzer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:5120']], [
            'file.extensions' => 'รองรับเฉพาะไฟล์ .xlsx, .xls และ .csv',
        ]);

        try {
            $grid = $reader->readAll($request->file('file')->getRealPath())[0] ?? throw new ImportException('ไม่พบข้อมูลในไฟล์');
        } catch (ImportException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $analysis = $analyzer->analyze($grid);
        [$codeCol, $labelCol] = array_slice(array_keys($analysis->columns), 0, 2) + [null, null];
        if ($codeCol === null || $labelCol === null) {
            return back()->withErrors(['file' => 'ไฟล์ต้องมีอย่างน้อย 2 คอลัมน์: รหัส และ ชื่อ']);
        }

        $count = 0;
        foreach ($analysis->dataRows() as $r) {
            $code = $grid->cell($r, $codeCol);
            $codeText = is_float($code->value) && floor($code->value) == $code->value ? (string) (int) $code->value : $code->text();
            $label = $grid->cell($r, $labelCol)->text();
            if ($codeText === '' || $label === '') {
                continue;
            }
            $codeSet->values()->updateOrCreate(['code' => mb_substr($codeText, 0, 50)], ['label' => mb_substr($label, 0, 255)]);
            $count++;
        }

        Audit::log('admin.codes', summary: 'นำเข้ารหัส');

        return back()->with('status', "นำเข้า/อัปเดตรหัส {$count} รายการ");
    }

    public function storeRule(Request $request, CodeSet $codeSet): RedirectResponse
    {
        $data = $request->validate([
            'match_type' => ['required', Rule::in(array_keys(CodeGroupRule::TYPES))],
            'pattern' => ['required', 'string', 'max:100', $request->input('match_type') === 'regex' ? $this->regexRule() : 'string'],
            'group_label' => ['required', 'string', 'max:150'],
        ]);
        $codeSet->groupRules()->create($data + ['sort' => (int) $codeSet->groupRules()->max('sort') + 1]);

        Audit::log('admin.codes', summary: 'เพิ่มกฎจัดกลุ่ม');

        return back()->with('status', 'เพิ่มกฎจัดกลุ่มแล้ว');
    }

    public function destroyRule(CodeSet $codeSet, CodeGroupRule $rule): RedirectResponse
    {
        abort_unless($rule->code_set_id === $codeSet->id, 404);
        $rule->delete();

        Audit::log('admin.codes', summary: 'ลบกฎจัดกลุ่ม');

        return back()->with('status', 'ลบกฎแล้ว');
    }

    private function regexRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (filled($value) && @preg_match('/'.str_replace('/', '\/', $value).'/iu', '') === false) {
                $fail('รูปแบบไม่ถูกต้อง (regex)');
            }
        };
    }
}
