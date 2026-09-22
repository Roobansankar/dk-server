<?php

namespace App\Policies;

use App\Models\GalleryImage;
use App\Models\User;

class GalleryImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gallery.view');
    }

    public function view(User $user, GalleryImage $image): bool
    {
        return $user->can('gallery.view');
    }

    public function create(User $user): bool
    {
        return $user->can('gallery.manage');
    }

    public function update(User $user, GalleryImage $image): bool
    {
        return $user->can('gallery.manage');
    }

    public function delete(User $user, GalleryImage $image): bool
    {
        return $user->can('gallery.manage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('gallery.manage');
    }
}
