<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_response_cache', function (Blueprint $table) {
            $table->char('cache_key', 64)->primary();
            $table->string('stage', 20);
            $table->json('response');
            $table->unsignedInteger('tokens_saved')->default(0);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_response_cache');
    }
};
