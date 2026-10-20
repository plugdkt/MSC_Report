<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\User;

/**
 * ดู: เจ้าของ / Admin / ผู้ที่ได้รับแชร์ · แก้ไข-แชร์-ลบ: เจ้าของเท่านั้น (spec FR-8, FR-9.3)
 */
class DashboardPolicy
{
    public function view(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->user_id === $user->id
            || $user->isAdmin()
            || $dashboard->shares()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->user_id === $user->id;
    }

    public function share(User $user, Dashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }

    /** สั่งส่งออกได้ทุกคนที่ดูได้ (ผู้บริหารที่ได้รับแชร์ต้องการไฟล์ไปใช้ต่อ) */
    public function export(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard);
    }
}
