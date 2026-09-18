<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class ModuleScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        // // Do not filter in unauthenticated contexts (seeders/console)
        if (!Auth::check()) {
            return;
        }

        $user = Auth::user();
        if (!$user) {
            return;
        }

        // Restaurant/branch users should only see restaurant modules.
        // Superadmin should be able to see all modules and filter explicitly where needed.
        if (!is_null($user->restaurant_id)) {
            $builder->where('is_superadmin', 0);
        }
    }
}

