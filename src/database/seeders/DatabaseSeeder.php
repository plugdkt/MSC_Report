<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * ผู้ใช้ถูกสร้างจากการเข้าสู่ระบบ SSO — seeder มีเฉพาะข้อมูลอ้างอิง
     */
    public function run(): void
    {
        $this->call(CodeSetSeeder::class);
    }
}
