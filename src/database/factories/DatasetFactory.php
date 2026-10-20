<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'รายงานทดสอบ',
            'original_filename' => 'test.xlsx',
            'stored_path' => 'uploads/'.Str::uuid().'.xlsx',
            'file_hash' => hash('sha256', Str::random()),
            'file_size' => 1024,
            'status' => 'inspected',
            'inspection' => ['sheets' => [], 'recommended' => null],
        ];
    }
}
