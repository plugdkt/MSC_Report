<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('code_set_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('label');
            $table->foreignId('parent_id')->nullable()->constrained('code_values')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['code_set_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_values');
    }
};
