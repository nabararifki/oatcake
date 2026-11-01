<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('locales.supported', ['id' => [], 'en' => []]));
        $default = config('locales.default', 'id');

        // Hirarki: Akun User -> Session -> Cookie -> Header Browser -> Default
        $locale = collect([
            $request->user()?->locale,
            $request->session()->get('locale'),
            $request->cookie('locale'),
            $request->getPreferredLanguage($supported),
        ])->first(fn ($val) => is_string($val) && in_array($val, $supported, true)) ?? $default;

        App::setLocale($locale);
        Carbon::setLocale($locale);
        Number::useLocale($locale);

        return $next($request);
    }
}
