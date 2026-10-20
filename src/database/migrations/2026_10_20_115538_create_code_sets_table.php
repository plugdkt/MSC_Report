<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_sets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 150);
            $table->string('description')->nullable();
            // คำในหัวคอลัมน์ที่ใช้จับคู่คอลัมน์กับชุดรหัสนี้อัตโนมัติ (regex แบบไม่สนตัวพิมพ์)
            $table->string('column_pattern')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_sets');
    }
};
