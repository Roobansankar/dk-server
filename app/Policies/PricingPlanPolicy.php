<?php

namespace App\Policies;

use App\Models\PricingPlan;
use App\Models\User;

class PricingPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pricing.view');
    }

    public function view(User $user, PricingPlan $plan): bool
    {
        return $user->can('pricing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pricing.manage');
    }

    public function update(User $user, PricingPlan $plan): bool
    {
        return $user->can('pricing.manage');
    }

    public function delete(User $user, PricingPlan $plan): bool
    {
        return $user->can('pricing.manage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('pricing.manage');
    }
}
