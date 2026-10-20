<?php

namespace App\Support;

use App\Models\Setting;

/**
 * ค่าตั้งขององค์กรที่ Admin แก้ได้ (ชื่อหน่วยงานในท้ายหน้า/ไฟล์ส่งออก, ธีมเริ่มต้น)
 */
class Org
{
    public const DEFAULT_NAME = 'คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา';

    public static function name(): string
    {
        return (string) (Setting::get('org.name') ?: self::DEFAULT_NAME);
    }

    public static function defaultTheme(): string
    {
        return (string) (Setting::get('org.default_theme') ?: 'org');
    }
}
