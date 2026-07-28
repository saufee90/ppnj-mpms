<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSystemMaintenance
{
    private const TIMEZONE = 'Asia/Kuala_Lumpur';

    public function handle(Request $request, Closure $next): Response
    {
        if (! SystemSetting::maintenanceEnabled()) {
            return $next($request);
        }

        if ($request->is('up') || $request->routeIs('maintenance.show')) {
            return $next($request);
        }

        if ($request->routeIs('logout')) {
            return $next($request);
        }

        if ($request->routeIs('login') && $request->isMethod('get')) {
            return $next($request);
        }

        $user = $request->user();
        if ($user && $user->isAdmin()) {
            return $next($request);
        }

        $payload = [
            'title' => SystemSetting::getValue('maintenance_title', SystemSetting::DEFAULTS['maintenance_title']),
            'message' => SystemSetting::getValue('maintenance_message', SystemSetting::DEFAULTS['maintenance_message']),
            'notes' => $this->splitNotes(SystemSetting::getValue('maintenance_notes')),
            'maintenance_type' => SystemSetting::getValue('maintenance_type', SystemSetting::DEFAULTS['maintenance_type']),
            'maintenance_label' => SystemSetting::maintenanceLabel((string) SystemSetting::getValue('maintenance_type', SystemSetting::DEFAULTS['maintenance_type'])),
            'maintenance_end_at' => SystemSetting::getValue('maintenance_end_at'),
            'system_version' => SystemSetting::systemVersion(),
            'preview' => false,
        ];

        return $this->renderMaintenanceResponse($request, $payload);
    }

    private function splitNotes(mixed $notes): array
    {
        if (! is_string($notes) || trim($notes) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $notes) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function renderMaintenanceResponse(Request $request, array $payload): Response
    {
        $status = 503;
        $response = $request->expectsJson() || $request->ajax()
            ? response()->json([
                'message' => 'Sistem sedang diselenggara. Sila cuba sebentar lagi.',
                'maintenance' => true,
                'maintenance_type' => $payload['maintenance_type'],
                'maintenance_label' => $payload['maintenance_label'],
            ], $status)
            : response()->view('maintenance', $payload, $status);

        if (! empty($payload['maintenance_end_at'])) {
            try {
                $retryAfter = now(self::TIMEZONE)->diffInSeconds(\Carbon\Carbon::parse($payload['maintenance_end_at'], self::TIMEZONE), false);
                if ($retryAfter > 0) {
                    $response->headers->set('Retry-After', (string) $retryAfter);
                }
            } catch (\Throwable) {
                // Ignore invalid dates silently.
            }
        }

        return $response;
    }
}
