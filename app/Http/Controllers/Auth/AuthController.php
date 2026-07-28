<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (SystemSetting::maintenanceEnabled()) {
            $settings = SystemSetting::mergedValues();

            $response = response()->view('maintenance', [
                'title' => $settings['maintenance_title'],
                'message' => $settings['maintenance_message'],
                'notes' => array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $settings['maintenance_notes']) ?: [])),
                'maintenance_type' => $settings['maintenance_type'],
                'maintenance_label' => SystemSetting::maintenanceLabel((string) $settings['maintenance_type']),
                'maintenance_end_at' => $settings['maintenance_end_at'],
                'system_version' => $settings['system_version'],
                'preview' => false,
            ], 503);

            if ($retryAfter = SystemSetting::retryAfterSeconds($settings['maintenance_end_at'])) {
                $response->headers->set('Retry-After', (string) $retryAfter);
            }

            return $response;
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'Sila masukkan email.',
            'email.email' => 'Format email tidak sah.',
            'password.required' => 'Sila masukkan kata laluan.',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata laluan tidak sah.',
            ]);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Akaun anda telah dinyahaktifkan. Sila hubungi Admin.',
            ]);
        }

        $request->session()->regenerate();

        AuditLog::record('login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLog::record('logout', Auth::user());

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
