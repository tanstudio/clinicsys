<?php

namespace App\Http\Middleware;

use App\Support\StaffAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RequireStaff
{
    public function handle(Request $request, Closure $next)
    {
        if (!StaffAccess::account()) {
            return $request->expectsJson() ? response()->json(['error' => 'unauthenticated'], 401) : redirect('/staff/login');
        }
        $staff = StaffAccess::id();
        $branch = StaffAccess::branch();
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Referrer-Policy', 'same-origin');
            return $response;
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            throw $exception;
        } finally {
            DB::table('staff_access_log')->insert([
                'staff_id' => $staff, 'branch_id' => $branch, 'method' => $request->method(),
                'route' => $request->route()->uri(), 'status' => $status, 'created_at' => now(),
            ]);
        }
    }
}
