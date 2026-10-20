<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * สร้าง/อัปเดตบัญชีผู้ใช้ในระบบจากโปรไฟล์ที่ได้จาก MEDSCI ACC
 */
class UserProvisioner
{
    /**
     * @param  array{user_id:int|string|null, username:string, name:string, pos_name:?string, div_name:?string, email:?string}  $profile
     */
    public function provision(array $profile): User
    {
        $user = User::firstOrNew(['username' => $profile['username']]);

        $user->fill([
            'sso_user_id' => is_numeric($profile['user_id']) ? (int) $profile['user_id'] : null,
            'name' => $profile['name'],
            'email' => $profile['email'],
            'pos_name' => $profile['pos_name'],
            'div_name' => $profile['div_name'],
            'last_login_at' => now(),
        ]);

        if (! $user->exists) {
            $user->role = 'staff';
            $user->is_active = true;
        }

        if ($this->isConfiguredAdmin($user->username)) {
            $user->role = 'admin';
        }

        $user->save();

        return $user;
    }

    public function isConfiguredAdmin(string $username): bool
    {
        return in_array(strtolower($username), array_map('strtolower', config('app.admin_usernames', [])), true);
    }
}
