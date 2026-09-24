<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLdevToken
{
    public function handle(Request $request, Closure $next): Response
    {

        $tokenFile = config('ldev.home') . '/.config/ldev/token';
        $expected = is_file($tokenFile) ? trim(file_get_contents($tokenFile)) : null;

        if ($expected === null || $expected === '') {
            return $next($request);
        }

        $given = $request->query('token') ?? $request->cookie('ldev_token');

        if (!is_string($given) || !hash_equals($expected, $given)) {
            abort(403, 'Missing or invalid Linux Dev token. Launch the dashboard via the Linux Dev app icon.');
        }

        if ($request->query('token') && $request->isMethod('GET')) {
            $remaining = collect($request->query())->except('token')->all();
            $clean = $request->path() === '/' ? '/' : '/' . $request->path();
            if ($remaining) {
                $clean .= '?' . http_build_query($remaining);
            }

            return redirect($clean)->withCookie(
                cookie('ldev_token', $expected, 60 * 24 * 30, null, null, false, true, false, 'strict')
            );
        }

        return $next($request);
    }
}
