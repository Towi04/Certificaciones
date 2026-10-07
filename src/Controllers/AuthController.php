<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Services\LoginThrottle;
use App\Services\PasswordResetService;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            if (Auth::mustChangePassword()) {
                redirect('/cuenta/cambiar-contrasena');
            }
            redirect(self::homeForRole(Auth::role()));
        }
        view('auth/login', ['title' => 'Iniciar sesión', 'layout' => 'auth']);
    }

    public function login(): void
    {
        csrf_verify();
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $throttle = new LoginThrottle();
        $key = LoginThrottle::clientKey('login', $email);
        if ($throttle->tooManyAttempts($key)) {
            flash('error', 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.');
            redirect('/login');
        }
        if (!Auth::attempt($email, $password)) {
            $throttle->hit($key);
            flash('error', 'Correo o contraseña incorrectos.');
            redirect('/login');
        }
        $throttle->clear($key);
        if (Auth::mustChangePassword()) {
            flash('success', 'Inicia cambiando tu contraseña temporal.');
            redirect('/cuenta/cambiar-contrasena');
        }
        redirect(self::homeForRole(Auth::role()));
    }

    public function logout(): void
    {
        Auth::logout();
        flash('success', 'Sesión cerrada.');
        redirect('/');
    }

    public function showForgot(): void
    {
        if (Auth::check()) {
            redirect(self::homeForRole(Auth::role()));
        }
        view('auth/forgot', ['title' => 'Restablecer contraseña', 'layout' => 'auth']);
    }

    public function forgotSubmit(): void
    {
        csrf_verify();
        $email = (string) ($_POST['email'] ?? '');
        $throttle = new LoginThrottle(5, 900);
        $key = LoginThrottle::clientKey('forgot', $email);
        if ($throttle->tooManyAttempts($key)) {
            flash('error', 'Demasiadas solicitudes. Espera unos minutos e inténtalo de nuevo.');
            redirect('/recuperar');
        }
        $throttle->hit($key);
        (new PasswordResetService())->requestReset($email);
        // Respuesta uniforme (no revelar si el correo existe).
        flash(
            'success',
            'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña. Revisa tu bandeja y spam.'
        );
        redirect('/login');
    }

    public function showReset(string $token): void
    {
        if (Auth::check()) {
            redirect(self::homeForRole(Auth::role()));
        }
        $peek = (new PasswordResetService())->peekValidToken($token);
        if ($peek === null) {
            flash('error', 'El enlace no es válido o ya expiró. Solicita uno nuevo.');
            redirect('/recuperar');
        }
        view('auth/reset', [
            'title' => 'Nueva contraseña',
            'layout' => 'auth',
            'token' => $token,
            'email' => $peek['email'],
        ]);
    }

    public function resetSubmit(string $token): void
    {
        csrf_verify();
        $throttle = new LoginThrottle(8, 900);
        $key = LoginThrottle::clientKey('reset', $token);
        if ($throttle->tooManyAttempts($key)) {
            flash('error', 'Demasiados intentos. Solicita un enlace nuevo más tarde.');
            redirect('/recuperar');
        }
        try {
            (new PasswordResetService())->resetPassword(
                $token,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            $throttle->clear($key);
            flash('success', 'Contraseña actualizada. Ya puedes iniciar sesión.');
            redirect('/login');
        } catch (\InvalidArgumentException $e) {
            $throttle->hit($key);
            flash('error', $e->getMessage());
            redirect('/recuperar/' . rawurlencode($token));
        } catch (\Throwable $e) {
            error_log('[Doceo] resetSubmit: ' . $e->getMessage());
            flash('error', 'No se pudo actualizar la contraseña. Inténtalo de nuevo.');
            redirect('/recuperar');
        }
    }

    public function showChangePassword(): void
    {
        Auth::requireLogin();
        // requireLogin ya redirige si must_change, pero esta ruta está permitida.
        view('auth/change_password', [
            'title' => 'Cambiar contraseña',
            'layout' => 'auth',
            'forced' => Auth::mustChangePassword(),
        ]);
    }

    public function changePasswordSubmit(): void
    {
        // No usar requireLogin() completo: enforcePasswordChange permitiría esta ruta,
        // pero necesitamos sesión activa.
        if (!Auth::check()) {
            flash('error', 'Inicia sesión para continuar.');
            redirect('/login');
        }
        csrf_verify();
        try {
            (new PasswordResetService())->changePasswordForUser(
                (int) Auth::id(),
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            Auth::clearMustChangeFlag();
            flash('success', 'Contraseña actualizada.');
            redirect(self::homeForRole(Auth::role()));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/cuenta/cambiar-contrasena');
        } catch (\Throwable $e) {
            error_log('[Doceo] changePassword: ' . $e->getMessage());
            flash('error', 'No se pudo cambiar la contraseña.');
            redirect('/cuenta/cambiar-contrasena');
        }
    }

    private static function homeForRole(?string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'partner' => '/partner',
            'student' => '/alumno',
            default => '/',
        };
    }
}
