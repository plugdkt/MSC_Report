<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('spec');
            $table->json('computed_data')->nullable();
            $table->json('executive_summary')->nullable();
            $table->enum('source', ['ai', 'rule', 'user'])->default('rule');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['dashboard_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_versions');
    }
};
