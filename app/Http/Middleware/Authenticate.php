<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate extends BaseAuthenticate
{
    private const TIMEZONE = 'Asia/Kuala_Lumpur';

    public function handle($request, Closure $next, ...$guards): Response
    {
        if (SystemSetting::maintenanceEnabled()) {
            $user = $request->user();

            if (! $user || ! $user->isAdmin()) {
                return $this->maintenanceResponse($request);
            }
        }

        return parent::handle($request, $next, ...$guards);
    }

    private function maintenanceResponse(Request $request): Response
    {
        $settings = SystemSetting::mergedValues();
        $payload = [
            'title' => $settings['maintenance_title'],
            'message' => $settings['maintenance_message'],
            'notes' => array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $settings['maintenance_notes']) ?: [])),
            'maintenance_type' => $settings['maintenance_type'],
            'maintenance_label' => SystemSetting::maintenanceLabel((string) $settings['maintenance_type']),
            'maintenance_end_at' => $settings['maintenance_end_at'],
            'system_version' => $settings['system_version'],
            'preview' => false,
        ];

        $status = 503;
        $response = $request->expectsJson() || $request->ajax()
            ? response()->json([
                'message' => 'Sistem sedang diselenggara. Sila cuba sebentar lagi.',
                'maintenance' => true,
                'maintenance_type' => $payload['maintenance_type'],
                'maintenance_label' => $payload['maintenance_label'],
            ], $status)
            : response()->view('maintenance', $payload, $status);

        if ($retryAfter = SystemSetting::retryAfterSeconds($settings['maintenance_end_at'])) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
