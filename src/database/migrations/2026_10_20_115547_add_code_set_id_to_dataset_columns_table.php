<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dataset_columns', function (Blueprint $table) {
            $table->foreignId('code_set_id')->nullable()->after('is_hidden')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dataset_columns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('code_set_id');
        });
    }
};
