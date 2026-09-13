<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CheckoutController;
use App\Controllers\FileLinkController;
use App\Controllers\PartnerController;
use App\Controllers\SetupController;
use App\Controllers\StudentController;
use App\Controllers\WebhookController;
use App\Http\Router;

$router = new Router();
$catalog = new CatalogController();
$auth = new AuthController();
$admin = new AdminController();
$student = new StudentController();
$partner = new PartnerController();
$setup = new SetupController();
$checkout = new CheckoutController();
$webhooks = new WebhookController();
$fileLinks = new FileLinkController();

$router->get('/archivo/{token}', fn (string $token) => $fileLinks->download($token));

$router->get('/', fn () => $catalog->home());
$router->get('/catalogo', fn () => $catalog->home());
$router->get('/producto/{slug}', fn (string $slug) => $catalog->show($slug));

$router->get('/adquirir/{slug}', fn (string $slug) => $checkout->show($slug));
$router->post('/adquirir/{slug}', fn (string $slug) => $checkout->submit($slug));
$router->get('/compra/{matricula}', fn (string $matricula) => $checkout->success($matricula));
$router->get('/api/cotizar/{slug}', fn (string $slug) => $checkout->quote($slug));
$router->get('/api/cotizar-combo/{slug}', fn (string $slug) => $checkout->quoteCombo($slug));
$router->get('/api/examen-slots/{slug}', fn (string $slug) => $checkout->examSlots($slug));

// Instalador web (funciona aunque setup.php no esté en el docroot)
$router->get('/setup', fn () => $setup->run());

// OpenPay (SPEI / cargos) — sin CSRF; auth HTTP Basic opcional vía OPENPAY_WEBHOOK_*
$router->post('/webhooks/openpay', fn () => $webhooks->openPay());

$router->get('/login', fn () => $auth->showLogin());
$router->post('/login', fn () => $auth->login());
$router->post('/logout', fn () => $auth->logout());
$router->get('/recuperar', fn () => $auth->showForgot());

$router->get('/admin', fn () => $admin->dashboard());
$router->get('/admin/productos', fn () => $admin->products());
$router->get('/admin/productos/nuevo', fn () => $admin->productCreateForm());
$router->post('/admin/productos/nuevo', fn () => $admin->productCreate());
$router->get('/admin/productos/plantilla-certificaciones.csv', fn () => $admin->productsBulkTemplate());
$router->get('/admin/productos/exportar.csv', fn () => $admin->productsBulkExport());
$router->post('/admin/productos/importar-csv', fn () => $admin->productsBulkImport());
$router->get('/admin/productos/generar-cursos-prep', fn () => $admin->prepCoursesBulkForm());
$router->post('/admin/productos/generar-cursos-prep', fn () => $admin->prepCoursesBulkGenerate());
$router->get('/admin/productos/{id}', fn (string $id) => $admin->productEdit($id));
$router->post('/admin/productos/{id}', fn (string $id) => $admin->productUpdate($id));
$router->post('/admin/productos/{id}/logo', fn (string $id) => $admin->productLogoUpload($id));
$router->post('/admin/productos/{id}/media', fn (string $id) => $admin->productMediaStore($id));
$router->post('/admin/productos/{id}/media/{mediaId}', fn (string $id, string $mediaId) => $admin->productMediaUpdate($id, $mediaId));
$router->post('/admin/productos/{id}/media/{mediaId}/eliminar', fn (string $id, string $mediaId) => $admin->productMediaDelete($id, $mediaId));
$router->post('/admin/productos/{id}/eliminar', fn (string $id) => $admin->productDelete($id));
$router->get('/admin/grupos', fn () => $admin->productGroups());
$router->get('/admin/grupos/nuevo', fn () => $admin->productGroupCreateForm());
$router->post('/admin/grupos/nuevo', fn () => $admin->productGroupCreate());
$router->post('/admin/grupos/sugeridos', fn () => $admin->productGroupsSeed());
$router->get('/admin/grupos/{id}', fn (string $id) => $admin->productGroupEdit($id));
$router->post('/admin/grupos/{id}', fn (string $id) => $admin->productGroupUpdate($id));
$router->post('/admin/grupos/{id}/eliminar', fn (string $id) => $admin->productGroupDelete($id));
$router->post('/admin/campos-checkout', fn () => $admin->createCheckoutField());
$router->post('/admin/campos-checkout/eliminar', fn () => $admin->deleteCheckoutField());
$router->post('/admin/campos-checkout/editar', fn () => $admin->updateCheckoutField());

$router->get('/admin/combos', fn () => $admin->combos());
$router->get('/admin/combos/nuevo', fn () => $admin->comboCreateForm());
$router->post('/admin/combos/nuevo', fn () => $admin->comboCreate());
$router->post('/admin/combos/{id}/eliminar', fn (string $id) => $admin->comboDelete($id));
$router->get('/admin/combos/{id}', fn (string $id) => $admin->comboEdit($id));
$router->post('/admin/combos/{id}', fn (string $id) => $admin->comboUpdate($id));

$router->get('/admin/precios', fn () => $admin->prices());
$router->post('/admin/precios', fn () => $admin->pricesSave());
$router->get('/admin/precios/plantilla.csv', fn () => $admin->pricesTemplate());
$router->post('/admin/precios/import', fn () => $admin->pricesImport());
$router->get('/admin/operacion/exportar', fn () => $admin->opsExport());
$router->post('/admin/operacion/accesos-lote', fn () => $admin->opsBulkAccess());
$router->post('/admin/operacion/{id}/accesos', fn (string $id) => $admin->opsSaveAccess($id));
$router->get('/admin/maestra', fn () => $admin->master());
$router->get('/admin/maestra/exportar', fn () => $admin->masterExport());
$router->get('/admin/filtros-catalogo', fn () => $admin->catalogFilters());
$router->get('/admin/filtros-catalogo/nuevo', fn () => $admin->catalogFilterCreateForm());
$router->post('/admin/filtros-catalogo/nuevo', fn () => $admin->catalogFilterCreate());
$router->get('/admin/filtros-catalogo/{id}', fn (string $id) => $admin->catalogFilterEdit($id));
$router->post('/admin/filtros-catalogo/{id}', fn (string $id) => $admin->catalogFilterUpdate($id));
$router->post('/admin/filtros-catalogo/{id}/eliminar', fn (string $id) => $admin->catalogFilterDelete($id));
$router->get('/admin/pagos', fn () => $admin->payments());
$router->get('/admin/compras/{id}', fn (string $id) => $admin->purchaseShow($id));
$router->post('/admin/compras/{id}/confirmar-pago', fn (string $id) => $admin->confirmPayment($id));
$router->get('/admin/compras/{id}/comprobante', fn (string $id) => $admin->paymentProof($id));
$router->get('/admin/seguimientos/{id}', fn (string $id) => $admin->trackingShow($id));
$router->post('/admin/seguimientos/{id}/avanzar', fn (string $id) => $admin->trackingAdvance($id));
$router->post('/admin/seguimientos/{id}/examen-asistencia', fn (string $id) => $admin->trackingConfirmExam($id));
$router->post('/admin/seguimientos/{id}/enviar-correo-paso', fn (string $id) => $admin->trackingSendStepMail($id));
$router->post('/admin/seguimientos/{id}/moodle', fn (string $id) => $admin->trackingSyncMoodle($id));
$router->post('/admin/seguimientos/{id}/examen', fn (string $id) => $admin->trackingUpdateExam($id));
$router->post('/admin/seguimientos/{id}/examen/autorizar', fn (string $id) => $admin->trackingAuthorizeExam($id));
$router->post('/admin/seguimientos/{id}/alumno', fn (string $id) => $admin->trackingUpdateStudent($id));
$router->post('/admin/seguimientos/{id}/elet-accesos', fn (string $id) => $admin->trackingPublishEletAccess($id));
$router->post('/admin/seguimientos/{id}/resultados', fn (string $id) => $admin->trackingSaveResults($id));
$router->get('/admin/seguimientos/{id}/resultados-pdf', fn (string $id) => $admin->trackingResultsPdf($id));
$router->post('/admin/seguimientos/{id}/uks-solicitud', fn (string $id) => $admin->trackingResendUksRequest($id));
$router->post('/admin/seguimientos/{id}/comprobante-proveedor', fn (string $id) => $admin->trackingUploadProviderProof($id));
$router->post('/admin/seguimientos/{id}/solicitud-proveedor', fn (string $id) => $admin->trackingSendProviderRequest($id));
$router->post('/admin/documentos/{id}/aprobar', fn (string $id) => $admin->documentApprove($id));
$router->post('/admin/documentos/{id}/rechazar', fn (string $id) => $admin->documentReject($id));
$router->get('/admin/documentos/{id}/ver', fn (string $id) => $admin->documentDownload($id));
$router->get('/admin/partners', fn () => $admin->partners());
$router->get('/admin/partners/nuevo', fn () => $admin->partnerCreateForm());
$router->post('/admin/partners/nuevo', fn () => $admin->partnerCreate());
$router->get('/admin/partners/{id}', fn (string $id) => $admin->partnerEdit($id));
$router->post('/admin/partners/{id}', fn (string $id) => $admin->partnerUpdate($id));
$router->post('/admin/partners/{id}/reenviar-acceso', fn (string $id) => $admin->partnerResendAccess($id));
$router->get('/admin/usuarios', fn () => $admin->users());
$router->get('/admin/usuarios/nuevo', fn () => $admin->userCreateForm());
$router->post('/admin/usuarios/nuevo', fn () => $admin->userCreate());
$router->get('/admin/usuarios/{id}', fn (string $id) => $admin->userEdit($id));
$router->post('/admin/usuarios/{id}', fn (string $id) => $admin->userUpdate($id));
$router->post('/admin/usuarios/{id}/reset-password', fn (string $id) => $admin->userResetPassword($id));
$router->get('/admin/proveedores', fn () => $admin->suppliers());
$router->get('/admin/proveedores/nuevo', fn () => $admin->supplierCreateForm());
$router->post('/admin/proveedores/nuevo', fn () => $admin->supplierCreate());
$router->get('/admin/proveedores/{id}/plantilla-certificaciones.csv', fn (string $id) => $admin->supplierBulkTemplate($id));
$router->post('/admin/proveedores/{id}/certificaciones', fn (string $id) => $admin->supplierBulkProducts($id));
$router->post('/admin/proveedores/{id}/logo', fn (string $id) => $admin->supplierLogo($id));
$router->post('/admin/proveedores/{id}/contactos', fn (string $id) => $admin->supplierContactCreate($id));
$router->post('/admin/proveedores/{id}/contactos/{contactId}/eliminar', fn (string $id, string $contactId) => $admin->supplierContactDelete($id, $contactId));
$router->post('/admin/proveedores/{id}/accesos', fn (string $id) => $admin->supplierAccountCreate($id));
$router->post('/admin/proveedores/{id}/accesos/{accountId}', fn (string $id, string $accountId) => $admin->supplierAccountUpdate($id, $accountId));
$router->post('/admin/proveedores/{id}/accesos/{accountId}/eliminar', fn (string $id, string $accountId) => $admin->supplierAccountDelete($id, $accountId));
$router->post('/admin/proveedores/{id}/accesos/{accountId}/revelar', fn (string $id, string $accountId) => $admin->supplierAccountReveal($id, $accountId));
$router->post('/admin/proveedores/{id}/documentos', fn (string $id) => $admin->supplierDocumentUpload($id));
$router->get('/admin/proveedores/{id}/documentos/{docId}/ver', fn (string $id, string $docId) => $admin->supplierDocumentView($id, $docId));
$router->get('/admin/proveedores/{id}/documentos/{docId}/descargar', fn (string $id, string $docId) => $admin->supplierDocumentDownload($id, $docId));
$router->post('/admin/proveedores/{id}/documentos/{docId}/eliminar', fn (string $id, string $docId) => $admin->supplierDocumentDelete($id, $docId));
$router->post('/admin/proveedores/{id}/eliminar', fn (string $id) => $admin->supplierDelete($id));
$router->get('/admin/proveedores/{id}', fn (string $id) => $admin->supplierShow($id));
$router->post('/admin/proveedores/{id}', fn (string $id) => $admin->supplierUpdate($id));
$router->get('/admin/certificadoras', fn () => $admin->certifiers());
$router->get('/admin/certificadoras/nueva', fn () => $admin->certifierCreateForm());
$router->post('/admin/certificadoras/nueva', fn () => $admin->certifierCreate());
$router->post('/admin/certificadoras/{id}/logo', fn (string $id) => $admin->certifierLogo($id));
$router->post('/admin/certificadoras/{id}/eliminar', fn (string $id) => $admin->certifierDelete($id));
$router->get('/admin/certificadoras/{id}', fn (string $id) => $admin->certifierShow($id));
$router->post('/admin/certificadoras/{id}', fn (string $id) => $admin->certifierUpdate($id));
$router->get('/admin/exportaciones', fn () => $admin->exports());
$router->get('/admin/exportaciones/{code}', fn (string $code) => $admin->exportDownload($code));
$router->post('/admin/importaciones/{code}', fn (string $code) => $admin->importUpload($code));
$router->get('/admin/vacaciones', fn () => $admin->vacations());
$router->post('/admin/vacaciones', fn () => $admin->vacationsSave());
$router->get('/admin/promo', fn () => $admin->promoCode());
$router->post('/admin/promo', fn () => $admin->promoCodeUpdate());
$router->get('/admin/inventario', fn () => $admin->inventoryIndex());
$router->get('/admin/inventario/{id}', fn (string $id) => $admin->inventoryProduct($id));
$router->post('/admin/inventario/{id}/lote', fn (string $id) => $admin->inventoryImportLot($id));

$router->get('/admin/plantillas-csv', fn () => $admin->csvTemplates());
$router->get('/admin/plantillas-csv/nueva', fn () => $admin->csvTemplateCreate());
$router->post('/admin/plantillas-csv/nueva', fn () => $admin->csvTemplateStore());
$router->get('/admin/plantillas-csv/{code}/descargar', fn (string $code) => $admin->csvTemplateDownload($code));
$router->post('/admin/plantillas-csv/{code}/eliminar', fn (string $code) => $admin->csvTemplateDelete($code));
$router->get('/admin/plantillas-csv/{code}', fn (string $code) => $admin->csvTemplateEdit($code));
$router->post('/admin/plantillas-csv/{code}', fn (string $code) => $admin->csvTemplateUpdate($code));

$router->get('/admin/correos', fn () => $admin->mailTemplates());
$router->post('/admin/correos/marca', fn () => $admin->mailBrandingUpdate());
$router->get('/admin/correos/nueva', fn () => $admin->mailTemplateCreate());
$router->post('/admin/correos/nueva', fn () => $admin->mailTemplateStore());
$router->post('/admin/correos/{code}/eliminar', fn (string $code) => $admin->mailTemplateDelete($code));
$router->get('/admin/correos/{code}', fn (string $code) => $admin->mailTemplateEdit($code));
$router->post('/admin/correos/{code}', fn (string $code) => $admin->mailTemplateUpdate($code));
$router->get('/admin/salud', fn () => $admin->health());

$router->get('/alumno', fn () => $student->dashboard());
$router->get('/alumno/caso/{id}', fn (string $id) => $student->caseShow($id));
$router->post('/alumno/caso/{id}/reagenda', fn (string $id) => $student->requestReschedule($id));
$router->post('/alumno/caso/{id}/documentos', fn (string $id) => $student->uploadRegistrationDocument($id));
$router->post('/alumno/documentos/{id}/reenviar', fn (string $id) => $student->reuploadDocument($id));
$router->get('/alumno/documentos/{id}/ver', fn (string $id) => $student->documentDownload($id));
$router->get('/partner', fn () => $partner->dashboard());
$router->get('/partner/registrar', fn () => $partner->registerForm());
$router->post('/partner/registrar', fn () => $partner->registerSubmit());
$router->get('/partner/caso/{id}', fn (string $id) => $partner->caseShow($id));
$router->post('/partner/caso/{id}/examen', fn (string $id) => $partner->updateExam($id));

return $router;
