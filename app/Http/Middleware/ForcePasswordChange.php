<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            // El flag se guarda junto con el id del usuario para el que se calculó:
            // si cambia el usuario logueado en la misma sesión de navegador (logout +
            // login de otra cuenta), se recalcula en vez de arrastrar el valor viejo.
            if (session('needs_password_change_user_id') !== Auth::id()) {
                $needsChange = Hash::check(Auth::user()->dni, Auth::user()->password_hash);
                session([
                    'needs_password_change' => $needsChange,
                    'needs_password_change_user_id' => Auth::id(),
                ]);
            }

            if (session('needs_password_change')) {
                $excludedRoutes = ['password.change.show', 'password.change.update', 'logout'];
                
                if (!in_array($request->route()?->getName(), $excludedRoutes)) {
                    return redirect()->route('password.change.show');
                }
            }
        }

        return $next($request);
    }
}
