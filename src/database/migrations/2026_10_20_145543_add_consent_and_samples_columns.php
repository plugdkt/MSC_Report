<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ผู้ใช้ยอมรับการส่งข้อมูลสรุปไปยัง AI ภายในองค์กรแล้วเมื่อใด (spec FR-7.3)
            $table->timestamp('ai_consent_at')->nullable()->after('is_active');
        });
        Schema::table('report_drafts', function (Blueprint $table) {
            // false = ไม่ส่งแถวตัวอย่างให้ AI เลย ส่งเฉพาะชื่อคอลัมน์และสถิติ (spec FR-7.2)
            $table->boolean('send_samples')->default(true)->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('report_drafts', fn (Blueprint $t) => $t->dropColumn('send_samples'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ai_consent_at'));
    }
};
