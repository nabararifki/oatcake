<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme'  => ['nullable', 'string', Rule::in(['light', 'dark', 'system'])],
            'locale' => ['nullable', 'string', Rule::in(array_keys(config('locales.supported', [])))],
        ]);

        $user = $request->user();
        if ($user) {
            $user->fill(array_filter($data))->save();
        }

        return response()->json(['success' => true]);
    }
}
