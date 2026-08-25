<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiDocsBasicAuth
{
    /**
     * Protect the interactive API documentation without requiring a web session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) config('scramble.auth.username');
        $password = (string) config('scramble.auth.password');

        if (app()->environment('local') && ($username === '' || $password === '')) {
            return $next($request);
        }

        if ($username === '' || $password === '') {
            abort(404);
        }

        $validUsername = hash_equals($username, (string) $request->getUser());
        $validPassword = hash_equals($password, (string) $request->getPassword());

        if (! $validUsername || ! $validPassword) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Kijumbe API Documentation", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }
}
