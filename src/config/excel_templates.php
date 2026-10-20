<?php

/*
 * ไฟล์ Excel แนะนำ (spec FR-14) — field type ใช้ชนิดเดียวกับ DatasetColumn::TYPES
 * options: รายการ dropdown · options_from: 'code_set:{key}' ดึงรหัสจากตารางรหัส (FR-15)
 */
return [
    'budget_monthly' => [
        'name' => 'รายงานผลการใช้จ่ายงบประมาณประจำเดือน',
        'description' => 'งบตั้งต้น โอนย้าย ใบสั่งซื้อ ใช้จริง และคงเหลือ ตามรายการงบประมาณ — โครงสร้างเดียวกับรายงานเงินคงเหลือของคณะ',
        'icon' => 'money',
        'fields' => [
            ['key' => 'fiscal_year', 'label' => 'ปีงบประมาณ', 'type' => 'code', 'required' => true, 'width' => 12, 'help' => 'ปี พ.ศ. 4 หลัก', 'example' => '2569'],
            ['key' => 'budget_code', 'label' => 'รหัสงบประมาณ', 'type' => 'code', 'required' => true, 'width' => 15, 'options_from' => 'code_set:budget_code', 'help' => 'รหัส 6 หลัก', 'example' => '210200'],
            ['key' => 'category', 'label' => 'หมวดรายจ่าย', 'type' => 'category', 'required' => true, 'width' => 26, 'help' => 'ขึ้นต้นด้วยเลขหมวด เช่น 2.1.2 เพื่อจัดกลุ่มเป็นงบรายจ่ายได้', 'example' => '2.1.2 ค่าใช้สอย'],
            ['key' => 'fund', 'label' => 'กองทุน', 'type' => 'code', 'required' => true, 'width' => 11, 'options_from' => 'code_set:fund', 'help' => 'รหัสกองทุน', 'example' => '20200'],
            ['key' => 'item', 'label' => 'รายการ', 'type' => 'text', 'required' => true, 'width' => 40, 'help' => 'ชื่อรายการ/โครงการ', 'example' => 'ค่าซ่อมแซมครุภัณฑ์'],
            ['key' => 'as_of', 'label' => 'ณ วันที่', 'type' => 'date', 'required' => true, 'width' => 13, 'help' => 'วันที่ของข้อมูล', 'example' => '30/09/2569'],
            ['key' => 'base', 'label' => 'งบประมาณตั้งต้น', 'type' => 'currency', 'required' => true, 'width' => 16, 'help' => 'บาท', 'example' => '100,000.00'],
            ['key' => 'adjust', 'label' => 'ปรับปรุงงบประมาณ', 'type' => 'currency', 'required' => false, 'width' => 16, 'help' => 'บาท (ติดลบได้)', 'example' => '0.00'],
            ['key' => 'transfer', 'label' => 'โอนย้ายงบประมาณ', 'type' => 'currency', 'required' => false, 'width' => 16, 'help' => 'บาท (ติดลบได้)', 'example' => '10,000.00'],
            ['key' => 'po', 'label' => 'มูลค่าใบสั่งซื้อ', 'type' => 'currency', 'required' => false, 'width' => 16, 'help' => 'บาท', 'example' => '5,000.00'],
            ['key' => 'used', 'label' => 'ยอดเงินจริง', 'type' => 'currency', 'required' => true, 'width' => 16, 'help' => 'บาท', 'example' => '80,000.00'],
            ['key' => 'reserve', 'label' => 'กันเงิน', 'type' => 'currency', 'required' => false, 'width' => 14, 'help' => 'บาท', 'example' => '0.00'],
            ['key' => 'remaining', 'label' => 'ยอดเงินที่เหลือ', 'type' => 'currency', 'required' => true, 'width' => 16, 'help' => 'งบตั้งต้น + ปรับปรุง + โอนย้าย − ใบสั่งซื้อ − ใช้จริง − กันเงิน', 'example' => '25,000.00'],
            ['key' => 'money_type', 'label' => 'ประเภทเงิน', 'type' => 'code', 'required' => false, 'width' => 12, 'options_from' => 'code_set:money_type', 'help' => 'รหัสประเภทเงิน', 'example' => '12'],
        ],
    ],

    'daily_work' => [
        'name' => 'บันทึกงานประจำวัน',
        'description' => 'บันทึกงานที่รับ/ดำเนินการแต่ละวัน เพื่อสรุปปริมาณงาน สถานะ และระยะเวลาดำเนินการ',
        'icon' => 'list',
        'fields' => [
            ['key' => 'date', 'label' => 'วันที่รับงาน', 'type' => 'date', 'required' => true, 'width' => 14, 'help' => 'วว/ดด/ปปปป (พ.ศ. ได้)', 'example' => '15/03/2569'],
            ['key' => 'work_type', 'label' => 'ประเภทงาน', 'type' => 'category', 'required' => true, 'width' => 22, 'options' => ['งานเอกสาร/หนังสือ', 'งานประชุม', 'งานการเงิน', 'งานพัสดุ', 'งานบริการ', 'อื่น ๆ'], 'help' => 'เลือกจากรายการ', 'example' => 'งานการเงิน'],
            ['key' => 'detail', 'label' => 'รายละเอียดงาน', 'type' => 'text', 'required' => true, 'width' => 42, 'help' => 'สั้น ๆ', 'example' => 'ตรวจเอกสารเบิกจ่ายโครงการ'],
            ['key' => 'owner', 'label' => 'ผู้รับผิดชอบ', 'type' => 'category', 'required' => false, 'width' => 20, 'help' => 'ชื่อเล่นหรือตำแหน่ง (ระบบปิดบังชื่อก่อนส่งให้ AI)', 'example' => 'งานการเงิน'],
            ['key' => 'status', 'label' => 'สถานะ', 'type' => 'category', 'required' => true, 'width' => 16, 'options' => ['รับเรื่อง', 'กำลังดำเนินการ', 'เสร็จสิ้น', 'ยกเลิก'], 'help' => 'เลือกจากรายการ', 'example' => 'เสร็จสิ้น'],
            ['key' => 'quantity', 'label' => 'จำนวน (ชิ้นงาน)', 'type' => 'integer', 'required' => false, 'width' => 14, 'help' => 'ตัวเลข', 'example' => '3'],
            ['key' => 'days', 'label' => 'ระยะเวลาดำเนินการ (วัน)', 'type' => 'number', 'required' => false, 'width' => 18, 'help' => 'ตัวเลข', 'example' => '2'],
            ['key' => 'note', 'label' => 'หมายเหตุ', 'type' => 'text', 'required' => false, 'width' => 30, 'help' => '', 'example' => ''],
        ],
    ],

    'project_tracking' => [
        'name' => 'ติดตามโครงการ/กิจกรรม',
        'description' => 'งบประมาณ การเบิกจ่าย และความก้าวหน้าของแต่ละโครงการ',
        'icon' => 'flag',
        'fields' => [
            ['key' => 'project_code', 'label' => 'รหัสโครงการ', 'type' => 'code', 'required' => false, 'width' => 14, 'help' => '', 'example' => 'P69-001'],
            ['key' => 'project', 'label' => 'ชื่อโครงการ', 'type' => 'text', 'required' => true, 'width' => 42, 'help' => '', 'example' => 'โครงการพัฒนาหลักสูตร'],
            ['key' => 'unit', 'label' => 'หน่วยงานที่รับผิดชอบ', 'type' => 'category', 'required' => true, 'width' => 24, 'help' => '', 'example' => 'สาขาวิชาสรีรวิทยา'],
            ['key' => 'budget', 'label' => 'งบประมาณ', 'type' => 'currency', 'required' => true, 'width' => 16, 'help' => 'บาท', 'example' => '50,000.00'],
            ['key' => 'spent', 'label' => 'เบิกจ่ายแล้ว', 'type' => 'currency', 'required' => true, 'width' => 16, 'help' => 'บาท', 'example' => '32,500.00'],
            ['key' => 'progress', 'label' => 'ความก้าวหน้า (ร้อยละ)', 'type' => 'percent', 'required' => false, 'width' => 16, 'help' => '0–100', 'example' => '65'],
            ['key' => 'start', 'label' => 'วันเริ่มโครงการ', 'type' => 'date', 'required' => false, 'width' => 14, 'help' => '', 'example' => '01/11/2568'],
            ['key' => 'end', 'label' => 'วันสิ้นสุดโครงการ', 'type' => 'date', 'required' => false, 'width' => 14, 'help' => '', 'example' => '31/08/2569'],
            ['key' => 'status', 'label' => 'สถานะ', 'type' => 'category', 'required' => true, 'width' => 16, 'options' => ['ยังไม่เริ่ม', 'กำลังดำเนินการ', 'เสร็จสิ้น', 'ล่าช้า', 'ยกเลิก'], 'help' => 'เลือกจากรายการ', 'example' => 'กำลังดำเนินการ'],
        ],
    ],
];
