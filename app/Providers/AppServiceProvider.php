<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Policies\AuditLogPolicy;
use App\Policies\CompanyBackupPolicy;
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
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);

        Gate::define('manage-company-backups', [CompanyBackupPolicy::class, 'manage']);
        Gate::define('restore-company-backups', [CompanyBackupPolicy::class, 'restore']);

        View::composer(['layouts.app', 'layouts.guest'], function ($view) {
            $user = auth()->user();
            if ($user !== null) {
                $user->loadMissing([
                    'company',
                    'employee' => fn ($q) => $q->withoutGlobalScopes()->with([
                        'department' => fn ($dq) => $dq->withoutGlobalScopes(),
                    ]),
                ]);
            }
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
