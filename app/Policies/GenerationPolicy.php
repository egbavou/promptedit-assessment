<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

final class GenerationPolicy
{
    public function view(User $user, Generation $generation): bool
    {
        return $user->id === $generation->user_id;
    }
}
