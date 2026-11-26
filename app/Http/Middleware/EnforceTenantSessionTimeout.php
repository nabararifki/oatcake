<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantSessionTimeout
{
    /**
     * Enforce tenant-configured idle session timeout for authenticated web requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        if ($request->routeIs('logout')) {
            return $next($request);
        }

        $user = Auth::user();
        $company = ($user && $user->isSuperAdmin() && $request->session()->get('active_company_id'))
            ? Company::find($request->session()->get('active_company_id'))
            : $user?->company;

        $timeoutMinutes = $company ? $company->getEffectiveAutoLogoutMinutes() : 30;
        $now = time();
        $lastActivity = $request->session()->get('last_activity');

        if ($lastActivity !== null && ($now - (int) $lastActivity) > ($timeoutMinutes * 60)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => __('auth.session_idle_timeout'),
                    'redirect' => route('login', ['timeout' => 1]),
                ], 419);
            }

            return redirect()
                ->route('login', ['timeout' => 1])
                ->with('error', __('auth.session_idle_timeout'));
        }

        $request->session()->put('last_activity', $now);

        return $next($request);
    }
}
