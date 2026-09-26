<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        $activeCompany = $user->companies()
            ->wherePivot('status', 'active')
            ->first();

        if (! $activeCompany) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene una empresa activa asignada.',
            ], 403);
        }

        $role = $activeCompany->pivot->role;

        if ($role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado: Los cobradores no tienen permiso de modificar nada, solo tienen permiso de cobrar.',
            ], 403);
        }

        return $next($request);
    }
}
