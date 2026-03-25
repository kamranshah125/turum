<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LogViewerAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Simple session-based check
        if (!$request->session()->get('log_viewer_authenticated')) {
            // If it's the login POST request, let it through
            if ($request->is('logs/login') && $request->isMethod('POST')) {
                return $next($request);
            }
            
            // Otherwise redirect to login view
            return redirect()->route('logs.index')->with('error', 'Authentication required.');
        }

        return $next($request);
    }
}
