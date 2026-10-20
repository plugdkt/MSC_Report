<?php

namespace App\Policies;

use App\Models\Dataset;
use App\Models\User;

class DatasetPolicy
{
    public function view(User $user, Dataset $dataset): bool
    {
        return $dataset->user_id === $user->id || $user->isAdmin();
    }

    public function update(User $user, Dataset $dataset): bool
    {
        return $dataset->user_id === $user->id;
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $dataset->user_id === $user->id;
    }
}
