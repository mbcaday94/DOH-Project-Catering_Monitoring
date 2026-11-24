<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    public function show(Request $request): SessionResource
    {
        return new SessionResource($request->user());
    }

    public function store(Request $request): RedirectResponse|SessionResource
    {
        $isApi = $request->routeIs('api.v1.session.store');
        abort_if($isApi && Auth::check(), 409, 'A session is already active.');

        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $login = $credentials['login'];
        $field = str_contains($login, '@') ? 'email' : 'username';

        if (! Auth::attempt([$field => $login, 'password' => $credentials['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        if ($isApi) {
            return $this->show($request);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->routeIs('api.v1.session.destroy')) {
            return response()->noContent();
        }

        return redirect()->route('login');
    }
}
