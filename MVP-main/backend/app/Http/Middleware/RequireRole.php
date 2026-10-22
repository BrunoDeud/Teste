<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'ATIVO' && in_array($user->papel, $roles, true), 403, 'Você não tem permissão para acessar esta área.');
        return $next($request);
    }
}
