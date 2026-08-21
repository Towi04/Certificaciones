<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Support\Settings;

final class StudentController
{
    public function dashboard(): void
    {
        Auth::requireRole(['student']);
        $trackings = (new TrackingRepository())->forStudent((int) Auth::id());
        view('student/dashboard', [
            'title' => 'Mi panel',
            'trackings' => $trackings,
            'layout' => 'student',
        ]);
    }
}

