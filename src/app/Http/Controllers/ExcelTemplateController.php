<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Services\ExcelTemplate\TemplateRegistry;
use App\Services\ExcelTemplate\TemplateWorkbookBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ดาวน์โหลดไฟล์ Excel แนะนำ (spec FR-14)
 */
class ExcelTemplateController extends Controller
{
    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly TemplateWorkbookBuilder $builder,
    ) {}

    public function index(): View
    {
        return view('excel-templates.index', ['templates' => $this->registry->standard()]);
    }

    public function download(string $key): BinaryFileResponse
    {
        $template = $this->registry->standard()[$key] ?? abort(404);

        return $this->send($key, $template, $template['name']);
    }

    public function fromDataset(Dataset $dataset): BinaryFileResponse
    {
        Gate::authorize('view', $dataset);
        abort_unless($dataset->status === 'ready', 404);

        return $this->send($this->registry->keyForDataset($dataset), $this->registry->fromDataset($dataset), 'แบบฟอร์ม '.$dataset->name);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function send(string $key, array $template, string $name): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->builder->save($this->builder->build($key, $template), $path);
        $filename = preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $name).'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
