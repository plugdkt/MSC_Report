<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Report\SpecValidator;
use App\Support\Audit;
use App\Support\Org;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', ['orgName' => Org::name(), 'theme' => Org::defaultTheme(), 'themes' => SpecValidator::THEMES]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'org_name' => ['required', 'string', 'max:150'],
            'default_theme' => ['required', Rule::in(array_keys(SpecValidator::THEMES))],
        ]);
        Setting::put('org.name', trim($data['org_name']));
        Setting::put('org.default_theme', $data['default_theme']);
        Audit::log('admin.settings', summary: 'ชื่อหน่วยงาน/ธีมเริ่มต้น');

        return back()->with('status', 'บันทึกการตั้งค่าระบบแล้ว');
    }
}
