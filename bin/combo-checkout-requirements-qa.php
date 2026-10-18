#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * QA sin BD: unión de requisitos al convertir producto → paquete en checkout.
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use App\Services\CheckoutRequirements;

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "OK  {$msg}\n";
        $passed++;
    } else {
        echo "FAIL {$msg}\n";
        $failed++;
    }
}

$prep = [
    'id' => 1,
    'type' => 'course',
    'slug' => 'prep-toefl',
    'group_config_json' => json_encode([
        'checkout_fields' => ['email', 'first_name', 'last_name_p', 'phone'],
    ], JSON_UNESCAPED_UNICODE),
    'config_json' => '{}',
];

$cert = [
    'id' => 2,
    'type' => 'certification',
    'slug' => 'cert-toefl',
    'group_config_json' => json_encode([
        'checkout_fields' => [
            'email', 'first_name', 'last_name_p', 'phone',
            'birth_date', 'sex', 'nationality',
        ],
        'required_docs' => [
            ['code' => 'ine', 'label' => 'INE', 'required' => true, 'accept' => '.pdf'],
        ],
        'reglamento' => [
            'template_path' => 'assets/reglamento-demo.pdf',
            'doc_code' => 'reglamento_firmado',
            'signature_mode' => 'append_to_pdf',
        ],
        'exam' => ['choose_at_checkout' => true],
        'schedule' => ['mode' => 'window', 'min_advance_days' => 2],
    ], JSON_UNESCAPED_UNICODE),
    'config_json' => '{}',
];

$baseReq = CheckoutRequirements::requirementsForCheckout($prep, null);
assert_true(
    !in_array('birth_date', array_column($baseReq['fields'], 'code'), true),
    'ancla prep no pide birth_date'
);
assert_true($baseReq['reglamento'] === null, 'ancla prep sin reglamento');
assert_true($baseReq['docs'] === [], 'ancla prep sin docs');
assert_true($baseReq['needs_exam'] === false, 'ancla prep sin agenda');

$comboReq = CheckoutRequirements::requirementsForCheckout($prep, [$prep, $cert]);
$comboCodes = array_column($comboReq['fields'], 'code');
assert_true(in_array('birth_date', $comboCodes, true), 'combo une birth_date');
assert_true(in_array('sex', $comboCodes, true), 'combo une sex');
assert_true(in_array('nationality', $comboCodes, true), 'combo une nationality');
assert_true($comboReq['reglamento'] !== null, 'combo hereda reglamento de certificación');
assert_true(
    ($comboReq['reglamento']['doc_code'] ?? '') === 'reglamento_firmado',
    'reglamento doc_code del cert'
);
assert_true(
    in_array('ine', array_column($comboReq['docs'], 'code'), true),
    'combo une doc INE'
);
assert_true($comboReq['needs_exam'] === true, 'combo pide agenda');
assert_true(($comboReq['exam_product_slug'] ?? '') === 'cert-toefl', 'exam slug del cert');

$unionFields = CheckoutRequirements::fieldsForProducts([$prep, $cert]);
$birth = null;
foreach ($unionFields as $f) {
    if (($f['code'] ?? '') === 'birth_date') {
        $birth = $f;
    }
}
assert_true($birth !== null && !empty($birth['required']), 'birth_date required en unión');

$routes = file_get_contents($base . '/routes/web.php') ?: '';
assert_true(
    str_contains($routes, '/api/checkout-requisitos/{slug}'),
    'ruta API checkout-requisitos registrada'
);

$controller = file_get_contents($base . '/src/Controllers/CheckoutController.php') ?: '';
assert_true(
    str_contains($controller, 'function requirements'),
    'CheckoutController::requirements existe'
);
assert_true(
    str_contains($controller, 'fieldsForProducts'),
    'submit valida fieldsForProducts del combo'
);

$service = file_get_contents($base . '/src/Services/CheckoutService.php') ?: '';
assert_true(
    str_contains($service, 'reglamentoForProducts'),
    'CheckoutService usa reglamentoForProducts'
);

$acquire = file_get_contents($base . '/views/checkout/acquire.php') ?: '';
assert_true(
    str_contains($acquire, 'datos_extra'),
    'wizard tiene paso datos_extra'
);
assert_true(
    str_contains($acquire, 'resolveRequirementsForComboId'),
    'wizard resuelve requisitos al elegir combo'
);
assert_true(
    str_contains($acquire, 'comboRequirementsMap'),
    'wizard embebe mapa de requisitos por combo'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
