<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Support\Settings;

final class PartnerController
{
    public function dashboard(): void
    {
        Auth::requireRole(['partner']);
        $pdo = \App\Database\Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM partners WHERE user_id = ? LIMIT 1');
        $stmt->execute([Auth::id()]);
        $partner = $stmt->fetch() ?: null;
        $trackings = [];
        if ($partner) {
            $trackings = (new TrackingRepository())->forPartner((int) $partner['id']);
        }
        view('partner/dashboard', [
            'title' => 'Partner',
            'partner' => $partner,
            'trackings' => $trackings,
            'layout' => 'partner',
        ]);
    }
}
