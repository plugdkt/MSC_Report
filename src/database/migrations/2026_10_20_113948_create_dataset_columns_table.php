<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('slug', 16);
            $table->string('source_name');
            $table->string('display_name');
            $table->string('inferred_type', 20);
            $table->string('user_type', 20)->nullable();
            $table->boolean('is_pii')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->json('profile')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_columns');
    }
};
