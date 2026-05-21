<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCustomer
{
    private const CUSTOMER_ROLES = ['company_admin', 'ops_pic', 'finance_pic'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isCustomer() && $user->company_id && $user->hasAnyRole(self::CUSTOMER_ROLES)) {
            return $next($request);
        }

        return response()->json(['message' => 'Akses ditolak. Hanya untuk customer.'], 403);
    }
}
