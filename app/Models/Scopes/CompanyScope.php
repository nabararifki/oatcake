<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->check()) {
            $user = auth()->user();

            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                if (session()->has('active_company_id') && session('active_company_id') !== null) {
                    $builder->where('company_id', session('active_company_id'));
                }

                return;
            }

            if ($user->company_id !== null) {
                $builder->where('company_id', $user->company_id);
            } else {
                $builder->whereRaw('1 = 0');
            }

            return;
        }

        if (app()->runningInConsole()) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
