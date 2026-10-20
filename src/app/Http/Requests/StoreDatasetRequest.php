<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'mimes:xlsx,xls,csv,txt', 'max:'.config('import.max_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.extensions' => 'รองรับเฉพาะไฟล์ .xlsx, .xls และ .csv (ไม่รองรับไฟล์ที่มี macro)',
            'file.mimes' => 'ไฟล์ไม่ใช่ Excel หรือ CSV ที่ถูกต้อง',
            'file.max' => 'ไฟล์ใหญ่เกิน '.(config('import.max_kb') / 1024).' MB',
            'file.uploaded' => 'อัปโหลดไฟล์ไม่สำเร็จ (ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้)',
        ];
    }
}
