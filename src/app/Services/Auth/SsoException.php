<?php

namespace App\Services\Auth;

use RuntimeException;

/** ข้อความใน exception นี้แสดงให้ผู้ใช้เห็นได้ (ภาษาไทย ไม่มีข้อมูลลับ) */
class SsoException extends RuntimeException {}
