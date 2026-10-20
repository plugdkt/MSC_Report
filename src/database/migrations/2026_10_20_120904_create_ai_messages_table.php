<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->char('payload_hash', 64);
            // เก็บเนื้อหาเต็มเฉพาะเมื่อเปิด AI_LOG_PAYLOAD (debug) — spec FR-7.4
            $table->longText('content')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->enum('key_source', ['personal', 'shared', 'none'])->default('none');
            $table->boolean('from_cache')->default(false);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
