<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google OAuth authentication page.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google and evaluate whitelist & active status.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            $message = 'Gagal melakukan autentikasi dengan Google. Silakan coba kembali.';

            return redirect()
                ->route('login')
                ->with('error', $message)
                ->withErrors(['login' => $message]);
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        // Kondisi 1: Email Tidak Terdaftar (Whitelist Reject)
        if (! $user) {
            $message = "Alamat email Google ({$email}) belum terdaftar di sistem OATCAKE. Hubungi HRD atau Manajer Anda.";

            return redirect()
                ->route('login')
                ->with('error', $message)
                ->withErrors(['login' => $message]);
        }

        // Kondisi 2: Akun Dinonaktifkan
        if (! $user->is_active) {
            $message = 'Akun Anda sedang dinonaktifkan. Silakan hubungi administrator.';

            return redirect()
                ->route('login')
                ->with('error', $message)
                ->withErrors(['login' => $message]);
        }

        // Kondisi 3: Valid & Aktif
        $user->update([
            'google_id' => $googleUser->getId(),
            'avatar_url' => $googleUser->getAvatar(),
        ]);

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
