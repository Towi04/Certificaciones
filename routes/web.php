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
$router->get('/admin/productos/{id}', fn (string $id) => $admin->productEdit($id));
$router->post('/admin/productos/{id}', fn (string $id) => $admin->productUpdate($id));
$router->get('/admin/maestra', fn () => $admin->master());
$router->get('/admin/pagos', fn () => $admin->payments());
$router->get('/admin/compras/{id}', fn (string $id) => $admin->purchaseShow($id));
$router->post('/admin/compras/{id}/confirmar-pago', fn (string $id) => $admin->confirmPayment($id));
$router->get('/admin/compras/{id}/comprobante', fn (string $id) => $admin->paymentProof($id));
$router->get('/admin/seguimientos/{id}', fn (string $id) => $admin->trackingShow($id));
$router->post('/admin/seguimientos/{id}/avanzar', fn (string $id) => $admin->trackingAdvance($id));
$router->post('/admin/seguimientos/{id}/moodle', fn (string $id) => $admin->trackingSyncMoodle($id));
$router->post('/admin/seguimientos/{id}/examen', fn (string $id) => $admin->trackingUpdateExam($id));
$router->post('/admin/documentos/{id}/aprobar', fn (string $id) => $admin->documentApprove($id));
$router->post('/admin/documentos/{id}/rechazar', fn (string $id) => $admin->documentReject($id));
$router->get('/admin/documentos/{id}/ver', fn (string $id) => $admin->documentDownload($id));
$router->get('/admin/partners', fn () => $admin->partners());
$router->get('/admin/partners/nuevo', fn () => $admin->partnerCreateForm());
$router->post('/admin/partners/nuevo', fn () => $admin->partnerCreate());
$router->get('/admin/partners/{id}', fn (string $id) => $admin->partnerEdit($id));
$router->post('/admin/partners/{id}', fn (string $id) => $admin->partnerUpdate($id));
$router->post('/admin/partners/{id}/reenviar-acceso', fn (string $id) => $admin->partnerResendAccess($id));
$router->get('/admin/proveedores', fn () => $admin->suppliers());
$router->get('/admin/salud', fn () => $admin->health());

$router->get('/alumno', fn () => $student->dashboard());
$router->get('/alumno/caso/{id}', fn (string $id) => $student->caseShow($id));
$router->post('/alumno/documentos/{id}/reenviar', fn (string $id) => $student->reuploadDocument($id));
$router->get('/alumno/documentos/{id}/ver', fn (string $id) => $student->documentDownload($id));
$router->get('/partner', fn () => $partner->dashboard());
$router->get('/partner/registrar', fn () => $partner->registerForm());
$router->post('/partner/registrar', fn () => $partner->registerSubmit());
$router->get('/partner/caso/{id}', fn (string $id) => $partner->caseShow($id));
$router->post('/partner/caso/{id}/examen', fn (string $id) => $partner->updateExam($id));

return $router;
