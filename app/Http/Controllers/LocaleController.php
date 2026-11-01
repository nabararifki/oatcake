<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $supported = array_keys(config('locales.supported', []));
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in($supported)],
        ]);

        $locale = $data['locale'];
        $request->session()->put('locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return back()->withCookie(cookie('locale', $locale, 60 * 24 * 365, '/', null, false, false, false, 'Lax'));
    }
}
