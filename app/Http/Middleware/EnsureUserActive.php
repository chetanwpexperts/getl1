<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A user locked by GetL1 staff is signed out on their very next request, on every device. */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->locked_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->route('login')->withErrors(['email' => 'This account has been locked. Please contact '.config('site.email').'.']);
        }

        return $next($request);
    }
}
