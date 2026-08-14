<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        $permission = $this->resolvePermission($path, $request->method());

        if ($permission === null || $user->hasFeatureAccess($permission)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki permission untuk fitur ini.',
        ], 403);
    }

    private function resolvePermission(string $path, string $method): ?string
    {
        $modules = config('admin-permissions.modules', []);
        $method = strtoupper($method);

        foreach ($modules as $module) {
            $prefix = rtrim((string) ($module['prefix'] ?? ''), '/');
            if ($prefix === '' || ! str_starts_with($path, $prefix)) {
                continue;
            }

            $action = match (true) {
                $method === 'GET' => 'view',
                in_array($method, ['POST', 'PUT', 'PATCH'], true) && str_contains($path, '/approve') => 'approve',
                in_array($method, ['POST', 'PUT', 'PATCH'], true) && str_contains($path, '/reject') => 'approve',
                in_array($method, ['POST', 'PUT', 'PATCH'], true) && str_contains($path, '/export') => 'export',
                $method === 'POST' => 'create',
                in_array($method, ['PUT', 'PATCH'], true) => 'edit',
                $method === 'DELETE' => 'delete',
                default => 'view',
            };

            return $module[$action] ?? $module['view'] ?? null;
        }

        return null;
    }
}
