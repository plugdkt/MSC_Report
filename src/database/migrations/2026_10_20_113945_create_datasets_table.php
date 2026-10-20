<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('original_filename');
            $table->string('stored_path', 500);
            $table->char('file_hash', 64)->index();
            $table->unsignedInteger('file_size');
            $table->enum('status', ['uploaded', 'inspected', 'importing', 'ready', 'failed'])->default('uploaded');
            $table->json('inspection')->nullable();
            $table->string('sheet_name', 150)->nullable();
            $table->unsignedInteger('header_row_start')->nullable();
            $table->unsignedInteger('header_row_end')->nullable();
            $table->json('title_lines')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->json('excluded_rows')->nullable();
            $table->json('quality')->nullable();
            $table->text('error_message')->nullable();
            $table->string('period_label', 50)->nullable();
            $table->unsignedBigInteger('series_id')->nullable()->index();
            $table->unsignedBigInteger('template_version_id')->nullable()->index();
            $table->json('column_mapping')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
