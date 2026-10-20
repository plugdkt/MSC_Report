<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatasetRequest;
use App\Jobs\ImportDatasetJob;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DatasetColumn;
use App\Models\ReportDraft;
use App\Services\Ai\AiGateway;
use App\Services\CodeLookup;
use App\Services\Import\ImportException;
use App\Services\Import\WorkbookInspector;
use App\Services\Report\ReportPlanner;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DatasetController extends Controller
{
    public function create(): View
    {
        return view('datasets.create', ['maxMb' => config('import.max_kb') / 1024]);
    }

    /**
     * อัปโหลดไฟล์ แล้วสำรวจทุกชีตทันที (ไฟล์ขนาดไม่เกิน 20 MB ใช้เวลาไม่กี่วินาที)
     */
    public function store(StoreDatasetRequest $request, WorkbookInspector $inspector): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->storeAs('uploads', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            $inspection = $inspector->inspect($fullPath);
        } catch (ImportException $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $templateKey = $inspection['template']['key'] ?? '';
        if (str_starts_with($templateKey, 'dataset:') && Dataset::find((int) substr($templateKey, 8))?->user_id !== $request->user()->id) {
            unset($inspection['template']);
        }

        $dataset = $request->user()->datasets()->create([
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'file_hash' => hash_file('sha256', $fullPath),
            'file_size' => $file->getSize(),
            'status' => 'inspected',
            'inspection' => $inspection,
            'expires_at' => now()->addDays(config('import.retention_days')),
        ]);

        Audit::log('dataset.upload', $dataset, ['size' => $dataset->file_size], summary: $dataset->original_filename);

        return redirect()->route('datasets.sheets', $dataset);
    }

    /**
     * เลือกชีตและแถวหัวตาราง พร้อมตัวอย่างข้อมูล
     */
    public function sheets(Request $request, Dataset $dataset, WorkbookInspector $inspector): View
    {
        Gate::authorize('update', $dataset);

        $sheets = collect($dataset->inspection['sheets'] ?? []);
        $selectedName = $request->query('sheet', $dataset->sheet_name ?? $dataset->inspection['recommended'] ?? $sheets->first()['name'] ?? null);
        $selected = $sheets->firstWhere('name', $selectedName) ?? $sheets->first();
        $error = null;

        $start = $request->integer('header_start');
        if ($start > 0 && $selected) {
            $end = max($start, min($request->integer('header_end', $start), $start + 3));
            try {
                $selected = [...$inspector->inspectSheet(Storage::disk('local')->path($dataset->stored_path), $selected['name'], $start, $end), 'recommended' => $selected['recommended'] ?? false];
            } catch (ImportException $e) {
                $error = $e->getMessage();
            }
        }

        return view('datasets.sheets', compact('dataset', 'sheets', 'selected', 'error'));
    }

    public function import(Request $request, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);

        $names = collect($dataset->inspection['sheets'] ?? [])->pluck('name')->all();
        $data = $request->validate([
            'sheet' => ['required', Rule::in($names)],
            'header_start' => ['required', 'integer', 'min:1', 'max:50'],
            'header_end' => ['required', 'integer', 'gte:header_start', 'lte:'.($request->integer('header_start') + 3)],
        ]);

        Audit::log('dataset.import', $dataset, ['sheet' => $data['sheet']], summary: $dataset->name);
        $dataset->update(['status' => 'importing', 'sheet_name' => $data['sheet']]);
        ImportDatasetJob::dispatch($dataset->id, $data['sheet'], (int) $data['header_start'], (int) $data['header_end']);

        return redirect()->route('datasets.show', $dataset);
    }

    public function show(Dataset $dataset): View|RedirectResponse
    {
        Gate::authorize('view', $dataset);

        if ($dataset->status === 'inspected') {
            return redirect()->route('datasets.sheets', $dataset);
        }

        return view('datasets.show', [
            'dataset' => $dataset,
            'columns' => $dataset->columns()->with('codeSet')->get(),
            'codes' => app(CodeLookup::class),
            'ai' => $ai = app(AiGateway::class)->availability(request()->user()),
            'estimate' => $dataset->status === 'ready' && $ai['available'] ? app(ReportPlanner::class)->estimatePlanTokens($dataset) : 0,
            'types' => DatasetColumn::TYPES,
            'canEdit' => Gate::allows('update', $dataset),
        ]);
    }

    /**
     * แก้ชื่อแสดงผล/ชนิด/ข้อมูลส่วนบุคคล ของคอลัมน์ — ถ้าเปลี่ยนชนิด จะนำเข้าใหม่เพื่อแปลงค่าตามชนิดใหม่
     */
    public function updateColumns(Request $request, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);

        $data = $request->validate([
            'columns' => ['required', 'array'],
            'columns.*.display_name' => ['required', 'string', 'max:255'],
            'columns.*.type' => ['required', Rule::in(array_keys(DatasetColumn::TYPES))],
            'columns.*.is_pii' => ['nullable', 'boolean'],
            'columns.*.is_hidden' => ['nullable', 'boolean'],
        ]);

        $typeChanged = false;
        foreach ($dataset->columns as $column) {
            $input = $data['columns'][$column->slug] ?? null;
            if (! $input) {
                continue;
            }
            $newType = $input['type'] === $column->inferred_type ? null : $input['type'];
            $typeChanged = $typeChanged || $newType !== $column->user_type;
            $column->update([
                'display_name' => trim($input['display_name']),
                'user_type' => $newType,
                'is_pii' => (bool) ($input['is_pii'] ?? false),
                'is_hidden' => (bool) ($input['is_hidden'] ?? false),
            ]);
        }

        if ($typeChanged) {
            $dataset->update(['status' => 'importing']);
            ImportDatasetJob::dispatch($dataset->id, $dataset->sheet_name, $dataset->header_row_start, $dataset->header_row_end);

            return redirect()->route('datasets.show', $dataset)->with('status', 'บันทึกแล้ว กำลังแปลงข้อมูลตามชนิดใหม่');
        }

        return redirect()->route('datasets.show', $dataset)->with('status', 'บันทึกการตั้งค่าคอลัมน์แล้ว');
    }

    public function destroy(Dataset $dataset): RedirectResponse
    {
        Gate::authorize('delete', $dataset);

        // ลบต่อเนื่อง (spec FR-7.5): รายงาน เวอร์ชัน ไฟล์ส่งออก รูป และไฟล์ต้นฉบับ
        $disk = Storage::disk('local');
        foreach (Dashboard::where('dataset_id', $dataset->id)->with('exports')->get() as $dashboard) {
            foreach ($dashboard->exports as $export) {
                if ($export->file_path) {
                    $disk->delete($export->file_path);
                }
            }
            $disk->deleteDirectory('dashboards/'.$dashboard->id);
            $dashboard->exports()->delete();
            $dashboard->delete();
        }
        $disk->delete($dataset->stored_path);
        ReportDraft::where('dataset_id', $dataset->id)->delete();
        Audit::log('dataset.deleted', $dataset, summary: $dataset->name);
        $dataset->delete();

        return redirect()->route('home')->with('status', 'ลบข้อมูล "'.$dataset->name.'" แล้ว');
    }
}
