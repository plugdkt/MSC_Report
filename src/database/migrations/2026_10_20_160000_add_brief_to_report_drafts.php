<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_drafts', function (Blueprint $table) {
            $table->text('brief')->nullable()->after('mode');          // คำอธิบายอิสระว่าอยากให้สรุปอะไร / คำสั่งปรับรายงาน
            $table->string('kind', 10)->default('create')->after('brief'); // create | refine
        });
    }

    public function down(): void
    {
        Schema::table('report_drafts', function (Blueprint $table) {
            $table->dropColumn(['brief', 'kind']);
        });
    }
};
