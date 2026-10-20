<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Crypt::encryptString() — ห้ามส่งค่านี้ไปหน้าเว็บหรือ log
            $table->text('encrypted_key');
            $table->char('key_last4', 4);
            $table->char('key_fingerprint', 64);
            $table->enum('status', ['active', 'invalid', 'quota_exceeded', 'revoked'])->default('active');
            $table->unsignedInteger('monthly_token_budget')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ai_credentials');
    }
};
