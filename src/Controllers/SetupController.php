<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Setup\WebSetup;

final class SetupController
{
    public function run(): void
    {
        WebSetup::runFromRequest();
    }
}
