<?php

namespace Database\Seeders;

use App\Models\CodeSet;
use Illuminate\Database\Seeder;

/**
 * ชุดรหัสเริ่มต้นตามโครงสร้างไฟล์งบประมาณของคณะ (ชื่อของแต่ละรหัสให้ Admin กรอก/นำเข้าเอง — spec §13 ข้อ 13)
 */
class CodeSetSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            ['key' => 'fund', 'name' => 'กองทุน', 'column_pattern' => 'กองทุน', 'description' => 'เช่น 20200, 50500, 60600', 'sort' => 1],
            ['key' => 'money_type', 'name' => 'ประเภทเงิน', 'column_pattern' => '^(ประเภทเงิน|เงิน)$', 'description' => 'เช่น 11, 12, 14', 'sort' => 2],
            ['key' => 'budget_code', 'name' => 'รหัสงบประมาณ', 'column_pattern' => '^รหัสงบประมาณ$', 'description' => 'เช่น 110000, 210100', 'sort' => 3],
            ['key' => 'plan', 'name' => 'แผนงาน', 'column_pattern' => '^แผนงาน$', 'description' => 'รหัสแผนงาน 9 หลัก', 'sort' => 4],
            ['key' => 'expense_category', 'name' => 'หมวดรายจ่าย', 'column_pattern' => '^(คำอธิบาย|หมวดรายจ่าย|ประเภทรายจ่าย)$', 'description' => 'ใช้จัดกลุ่มเป็นงบรายจ่ายตามเลขนำหน้า', 'sort' => 5],
        ];

        foreach ($sets as $data) {
            CodeSet::updateOrCreate(['key' => $data['key']], $data);
        }

        $category = CodeSet::where('key', 'expense_category')->first();
        if ($category->groupRules()->doesntExist()) {
            foreach ([['1.', 'งบบุคลากร'], ['2.', 'งบดำเนินงาน'], ['3.', 'งบลงทุน'], ['4.', 'งบเงินอุดหนุน'], ['5.', 'งบรายจ่ายอื่น']] as $i => [$prefix, $label]) {
                $category->groupRules()->create(['match_type' => 'prefix', 'pattern' => $prefix, 'group_label' => $label, 'sort' => $i]);
            }
        }
    }
}
