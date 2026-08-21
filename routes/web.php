<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CheckoutController;
use App\Controllers\PartnerController;
use App\Controllers\SetupController;
use App\Controllers\StudentController;
use App\Http\Router;

$router = new Router();
$catalog = new CatalogController();
$auth = new AuthController();
$admin = new AdminController();
$student = new StudentController();
$partner = new PartnerController();
$setup = new SetupController();
$checkout = new CheckoutController();

$router->get('/', fn () => $catalog->home());
$router->get('/catalogo', fn () => $catalog->home());
$router->get('/producto/{slug}', fn (string $slug) => $catalog->show($slug));

$router->get('/adquirir/{slug}', fn (string $slug) => $checkout->show($slug));
$router->post('/adquirir/{slug}', fn (string $slug) => $checkout->submit($slug));
$router->get('/compra/{matricula}', fn (string $matricula) => $checkout->success($matricula));
$router->get('/api/cotizar/{slug}', fn (string $slug) => $checkout->quote($slug));

// Instalador web (funciona aunque setup.php no esté en el docroot)
$router->get('/setup', fn () => $setup->run());

$router->get('/login', fn () => $auth->showLogin());
$router->post('/login', fn () => $auth->login());
$router->post('/logout', fn () => $auth->logout());
$router->get('/recuperar', fn () => $auth->showForgot());

$router->get('/admin', fn () => $admin->dashboard());
$router->get('/admin/productos', fn () => $admin->products());
$router->get('/admin/maestra', fn () => $admin->master());
$router->get('/admin/compras/{id}', fn (string $id) => $admin->purchaseShow($id));
$router->post('/admin/compras/{id}/confirmar-pago', fn (string $id) => $admin->confirmPayment($id));
$router->get('/admin/proveedores', fn () => $admin->suppliers());
$router->get('/admin/salud', fn () => $admin->health());

$router->get('/alumno', fn () => $student->dashboard());
$router->get('/partner', fn () => $partner->dashboard());

return $router;
