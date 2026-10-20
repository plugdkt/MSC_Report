<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users', [
            'users' => User::orderByDesc('last_login_at')->orderBy('name')->paginate(50),
            'roles' => User::ROLES,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->is($request->user()) && ($data['role'] !== 'admin' || ! $data['is_active'])) {
            return back()->with('error', 'ไม่สามารถลดสิทธิ์หรือระงับบัญชีของตัวเองได้');
        }

        $before = $user->only('role', 'is_active');
        $user->update($data);
        Audit::log('admin.user_updated', $user, ['role' => $before['role'].' → '.$data['role'], 'active' => (int) $before['is_active'].' → '.(int) $data['is_active']], summary: $user->username);

        return back()->with('status', 'บันทึกสิทธิ์ของ '.$user->name.' แล้ว');
    }
}
