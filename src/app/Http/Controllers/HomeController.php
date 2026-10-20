<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * หน้าแรกสำหรับผู้ที่ยังไม่เข้าสู่ระบบ
     */
    public function landing(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('home');
        }

        return view('landing');
    }

    /**
     * หน้าหลักหลังเข้าสู่ระบบ
     */
    public function home(Request $request): View
    {
        return view('home', [
            'user' => $request->user(),
            'datasets' => $request->user()->datasets()->latest()->get(),
        ]);
    }
}
