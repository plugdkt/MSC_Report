<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_group_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('code_set_id')->constrained()->cascadeOnDelete();
            $table->enum('match_type', ['prefix', 'exact', 'regex'])->default('prefix');
            $table->string('pattern', 100);
            $table->string('group_label', 150);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_group_rules');
    }
};
