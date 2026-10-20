<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->enum('mode', ['ai', 'rule'])->default('ai');
            $table->enum('status', ['asking', 'questions', 'planning', 'done', 'failed'])->default('asking');
            $table->json('questions')->nullable();
            $table->json('answers')->nullable();
            $table->text('understanding')->nullable();
            $table->json('notices')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('dashboard_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_drafts');
    }
};
