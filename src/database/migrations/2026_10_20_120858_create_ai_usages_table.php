<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->enum('key_source', ['personal', 'shared']);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period', 'key_source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
