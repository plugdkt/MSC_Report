<?php

namespace Tests\Feature;

use App\Models\CodeSet;
use App\Models\Dataset;
use App\Models\User;
use App\Services\CodeLookup;
use Database\Seeders\CodeSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BudgetWorkbook;
use Tests\TestCase;

class CodeLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CodeSetSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function importFlatSheet(): Dataset
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $this->actingAs($this->admin)->post(route('datasets.store'), ['file' => new UploadedFile($path, 'budget.xlsx', null, null, true)]);
        $dataset = Dataset::firstOrFail();
        $this->actingAs($this->admin)->post(route('datasets.import', $dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);

        return $dataset->fresh();
    }

    private function fund(): CodeSet
    {
        return CodeSet::where('key', 'fund')->firstOrFail();
    }

    public function test_group_rules_map_expense_categories_to_budget_groups(): void
    {
        $lookup = app(CodeLookup::class);
        $category = CodeSet::where('key', 'expense_category')->firstOrFail();

        $this->assertSame('งบดำเนินงาน', $lookup->group($category->id, '2.1.2 ค่าใช้สอย'));
        $this->assertSame('งบบุคลากร', $lookup->group($category->id, '1.1 ค่าจ้างชั่วคราว'));
        $this->assertNull($lookup->group($category->id, 'อื่น ๆ'));
    }

    public function test_import_links_code_columns_and_lists_codes_without_names(): void
    {
        $dataset = $this->importFlatSheet();
        $cols = $dataset->columns->keyBy('source_name');

        $this->assertSame($this->fund()->id, $cols['กองทุน']->code_set_id);
        $this->assertSame(CodeSet::where('key', 'expense_category')->value('id'), $cols['คำอธิบาย']->code_set_id);
        $this->assertNull($cols['งบประมาณ']->code_set_id);

        $this->assertSame(['20200' => 3, '50500' => 1], app(CodeLookup::class)->unknownCodes($this->fund()));

        $this->actingAs($this->admin)->post(route('admin.codes.values.store', $this->fund()), ['code' => '20200', 'label' => 'เงินอุดหนุนทั่วไป'])
            ->assertSessionHas('status');
        $this->assertSame(['50500' => 1], app(CodeLookup::class)->unknownCodes($this->fund()));

        $this->actingAs($this->admin)->get(route('datasets.show', $dataset))->assertOk()->assertSee('→ เงินอุดหนุนทั่วไป')->assertSee('→ งบบุคลากร');
        $this->actingAs($this->admin)->get(route('admin.codes.show', $this->fund()))->assertOk()->assertSee('รหัสที่พบในข้อมูลแต่ยังไม่มีชื่อ (1)');
    }

    public function test_admin_can_import_codes_from_csv(): void
    {
        $csv = UploadedFile::fake()->createWithContent('funds.csv', "รหัส,ชื่อกองทุน\n20200,เงินอุดหนุนทั่วไป\n50500,เงินรายได้\n");

        $this->actingAs($this->admin)->post(route('admin.codes.import', $this->fund()), ['file' => $csv])
            ->assertSessionHas('status', 'นำเข้า/อัปเดตรหัส 2 รายการ');

        $this->assertSame(['20200' => 'เงินอุดหนุนทั่วไป', '50500' => 'เงินรายได้'], $this->fund()->values()->pluck('label', 'code')->all());
    }

    public function test_admin_manages_values_rules_and_settings(): void
    {
        $fund = $this->fund();
        $this->actingAs($this->admin);

        $this->post(route('admin.codes.values.store', $fund), ['code' => '60600', 'label' => 'ก']);
        $value = $fund->values()->firstOrFail();
        $this->put(route('admin.codes.values.update', [$fund, $value]), ['label' => 'เงินลงทุน'])->assertSessionHas('status');
        $this->assertSame('เงินลงทุน', $value->fresh()->label);
        $this->post(route('admin.codes.values.store', $fund), ['code' => '60600', 'label' => 'ซ้ำ'])->assertSessionHasErrors('code');

        $this->post(route('admin.codes.rules.store', $fund), ['match_type' => 'prefix', 'pattern' => '6', 'group_label' => 'กองทุนลงทุน'])->assertSessionHas('status');
        $this->assertSame('กองทุนลงทุน', app(CodeLookup::class)->group($fund->id, '60600'));

        $this->put(route('admin.codes.update', $fund), ['name' => 'กองทุน', 'column_pattern' => '(กองทุน'])->assertSessionHasErrors('column_pattern');

        $this->delete(route('admin.codes.values.destroy', [$fund, $value]))->assertSessionHas('status');
        $this->assertModelMissing($value);
    }

    public function test_staff_cannot_manage_codes(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.codes.index'))->assertForbidden();
    }

    public function test_index_page_shows_sets(): void
    {
        $this->actingAs($this->admin)->get(route('admin.codes.index'))->assertOk()->assertSee('กองทุน')->assertSee('หมวดรายจ่าย');
    }
}
