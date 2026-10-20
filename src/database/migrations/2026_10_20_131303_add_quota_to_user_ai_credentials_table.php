<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_ai_credentials', function (Blueprint $table) {
            // โควต้ารายวันต่อโมเดลที่ gateway แจ้งกลับมาล่าสุด {model: {quota, used, remaining, at}}
            $table->json('quota')->nullable()->after('monthly_token_budget');
        });
    }

    public function down(): void
    {
        Schema::table('user_ai_credentials', function (Blueprint $table) {
            $table->dropColumn('quota');
        });
    }
};
