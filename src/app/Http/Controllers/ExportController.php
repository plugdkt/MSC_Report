<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\Export;
use App\Services\Export\ExportException;
use App\Services\Export\ExportService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function store(Request $request, Dashboard $dashboard, ExportService $exports): RedirectResponse
    {
        Gate::authorize('export', $dashboard);
        $data = $request->validate([
            'target' => ['required', Rule::in(array_keys(ExportService::COMBOS))],
            'format' => ['required', Rule::in(['pdf', 'jpeg', 'png'])],
            'scale' => ['nullable', Rule::in(config('export.scales'))],
            'orientation' => ['nullable', Rule::in(['landscape', 'portrait'])],
        ]);

        try {
            $export = $exports->request($dashboard, $request->user(), $data['target'], $data['format'], $data);
        } catch (ExportException $e) {
            return back()->with('error', $e->getMessage());
        }

        Audit::log('export.created', $export, ['target' => $data['target'], 'format' => $data['format']], summary: $dashboard->title);

        return redirect()->route('exports.show', $export);
    }

    public function show(Request $request, Export $export): View
    {
        $this->authorizeView($request, $export);

        return view('exports.show', ['export' => $export->load('dashboard')]);
    }

    public function retry(Request $request, Export $export, ExportService $exports): RedirectResponse
    {
        Gate::authorize('retry', $export);
        $new = $exports->request($export->dashboard, $request->user(), $export->target, $export->format, $export->options ?? []);

        return redirect()->route('exports.show', $new);
    }

    public function download(Request $request, Export $export): StreamedResponse
    {
        $this->authorizeView($request, $export);
        abort_unless($export->isReady() && $export->file_path && Storage::disk('local')->exists($export->file_path), 404, 'ไฟล์หมดอายุหรือไม่พบ');
        Audit::log('export.downloaded', $export, ['filename' => $export->filename]);

        return Storage::disk('local')->download($export->file_path, $export->filename, [
            'Content-Type' => match ($export->format) {
                'pdf' => 'application/pdf', 'png' => 'image/png', default => 'image/jpeg'
            },
        ]);
    }

    private function authorizeView(Request $request, Export $export): void
    {
        Gate::authorize('view', $export);
    }
}
