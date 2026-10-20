<?php

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

class ExportPolicy
{
    public function view(User $user, Export $export): bool
    {
        return $export->user_id === $user->id || $user->isAdmin();
    }

    public function retry(User $user, Export $export): bool
    {
        return $export->user_id === $user->id && $export->status === 'failed';
    }
}
