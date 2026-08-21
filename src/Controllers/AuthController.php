<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Support\Settings;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect(self::homeForRole(Auth::role()));
        }
        view('auth/login', ['title' => 'Iniciar sesión', 'layout' => 'auth']);
    }

    public function login(): void
    {
        csrf_verify();
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (!Auth::attempt($email, $password)) {
            flash('error', 'Correo o contraseña incorrectos.');
            redirect('/login');
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
        view('auth/forgot', ['title' => 'Restablecer contraseña', 'layout' => 'auth']);
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

