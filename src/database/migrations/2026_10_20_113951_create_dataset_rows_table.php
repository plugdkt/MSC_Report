<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('source_row');
            $table->json('data');

            $table->index(['dataset_id', 'row_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_rows');
    }
};
