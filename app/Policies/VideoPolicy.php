<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('videos.view');
    }

    public function view(User $user, Video $video): bool
    {
        return $user->can('videos.view');
    }

    public function create(User $user): bool
    {
        return $user->can('videos.manage');
    }

    public function update(User $user, Video $video): bool
    {
        return $user->can('videos.manage');
    }

    public function delete(User $user, Video $video): bool
    {
        return $user->can('videos.manage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('videos.manage');
    }
}
