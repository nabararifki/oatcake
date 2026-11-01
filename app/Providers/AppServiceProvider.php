<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\EmployeePolicy;
use App\Support\Accent;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });

        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);

        View::composer(['layouts.app', 'layouts.guest'], function ($view) {
            $user = auth()->user();
            $company = $user?->isSuperAdmin() && session('active_company_id')
                ? Company::find(session('active_company_id'))
                : $user?->company;
            $accentPalette = Accent::palette($company?->accent_color);
            $theme = $user?->theme ?? request()->cookie('theme', 'system');

            $view->with([
                'currentCompany' => $company,
                'accentPalette'  => $accentPalette,
                'userTheme'      => $theme,
            ]);
        });
    }
}
