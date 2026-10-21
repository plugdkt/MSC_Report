<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_drafts', function (Blueprint $table) {
            $table->enum('status', ['asking', 'questions', 'planning', 'review', 'building', 'done', 'failed'])->default('asking')->change();
            $table->json('planned_spec')->nullable()->after('answers');   // แผนรายงานที่รอผู้ใช้ตรวจก่อนสร้าง
            $table->text('instruction')->nullable()->after('brief');      // คำสั่งปรับแผนครั้งล่าสุด
        });
    }

    public function down(): void
    {
        Schema::table('report_drafts', function (Blueprint $table) {
            $table->dropColumn(['planned_spec', 'instruction']);
            $table->enum('status', ['asking', 'questions', 'planning', 'done', 'failed'])->default('asking')->change();
        });
    }
};
