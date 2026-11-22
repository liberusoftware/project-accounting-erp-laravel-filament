<?php

namespace Liberu\Foundation\ApplicationCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Attach baseline security response headers.
     *
     * Note: a strict Content-Security-Policy is intentionally NOT enforced here —
     * Filament/Livewire/Vite rely on inline scripts and styles, so a real CSP needs
     * per-app tuning (nonces). Add one deliberately when the surfaces are locked down.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (in_array(app()->environment(), ['production', 'staging'], true) && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self' ws: wss:",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'",
                'upgrade-insecure-requests',
            ]));
        }

        $response->headers->remove('Server');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
