<?php

declare(strict_types=1);

namespace App\Setup;

use App\Database\Connection;
use App\Repositories\CertifierRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Support\Settings;

final class CatalogSeeder
{
    /** @return list<string> */
    public function run(): array
    {
        $log = [];
        $pdo = Connection::get();

        $suppliers = [
            ['name' => 'Creative Solutions', 'code' => 'creative', 'website' => null, 'notes' => 'Cambridge / Linguaskill'],
            ['name' => 'Lingua Franca', 'code' => 'linguafranca', 'website' => null, 'notes' => 'TOEFL (certifica IIE)'],
            ['name' => 'UKS', 'code' => 'uks', 'website' => 'https://uks.mx', 'notes' => 'ELeT y familia UKS'],
            ['name' => 'ETC Iberoamérica', 'code' => 'etc', 'website' => null, 'notes' => 'Microsoft, Adobe, Apple, etc.'],
            ['name' => 'iTEP / Oxford', 'code' => 'itep', 'website' => null, 'notes' => 'iTEP y OOPT'],
            ['name' => 'Instituto DOCEO', 'code' => 'doceo', 'website' => 'https://institutodoceo.com', 'notes' => 'Cursos Moodle propios y trámites'],
        ];

        $supplierIds = [];
        $sRepo = new SupplierRepository();
        foreach ($suppliers as $s) {
            $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE code = ?');
            $stmt->execute([$s['code']]);
            $id = $stmt->fetchColumn();
            if ($id) {
                $supplierIds[$s['code']] = (int) $id;
                continue;
            }
            $supplierIds[$s['code']] = $sRepo->create($s);
            $log[] = 'Proveedor creado: ' . $s['code'];
        }

        $certifiers = [
            ['name' => 'Cambridge', 'code' => 'cambridge'],
            ['name' => 'TOEFL / IIE', 'code' => 'toefl'],
            ['name' => 'UKS', 'code' => 'uks'],
            ['name' => 'Microsoft', 'code' => 'microsoft'],
            ['name' => 'iTEP', 'code' => 'itep'],
            ['name' => 'Oxford', 'code' => 'oxford'],
            ['name' => 'SEP / CENNI', 'code' => 'cenni'],
            ['name' => 'Instituto DOCEO', 'code' => 'doceo'],
        ];
        $cRepo = new CertifierRepository();
        $certifierIds = [];
        foreach ($certifiers as $c) {
            $existing = $cRepo->findByCode($c['code']);
            if ($existing) {
                $certifierIds[$c['code']] = (int) $existing['id'];
                continue;
            }
            $certifierIds[$c['code']] = $cRepo->create($c);
            $log[] = 'Certificador creado: ' . $c['code'];
        }

        $upsertProduct = static function (array $row) use ($pdo): string {
            $stmt = $pdo->prepare('SELECT id FROM products WHERE code = ?');
            $stmt->execute([$row['code']]);
            $id = $stmt->fetchColumn();
            $repo = new ProductRepository();
            $code = (string) $row['code'];
            if ($id) {
                unset($row['code']);
                $repo->update((int) $id, $row);

                return "Producto actualizado: {$code}";
            }
            $repo->create($row);

            return "Producto creado: {$code}";
        };

        $eletDescription = <<<'TXT'
El ELeT es un examen de acreditación del nivel de inglés computarizado y con resultados inmediatos. Se encuentra alineado al marco común europeo y al programa de Certificación Nacional del Nivel de Idioma (CENNI) de la SEP.

Características:
• Examen de acreditación para personas de 14 años en adelante.
• Reactivos alineados al Marco Común Europeo (CEFR), niveles A1− a C1+ (CENNI niveles 2–16).
• Inglés adaptado a necesidades de uso global.
• Resultado al instante al terminar el examen.
• Aprobado por el Colegio de Profesionales en la Enseñanza del Inglés (COPEI).
• Duración aproximada: 75 minutos.
• Cuatro secciones: Comprensión de lectura (Reading), Comprensión auditiva (Listening), Uso del idioma (Use of English), Producción escrita (Writing).
TXT;

        $eletBenefits = <<<'HTML'
<ul>
<li><strong>Resultados inmediatos</strong></li>
<li><strong>Constancia UKS</strong></li>
<li>Combo opcional: trámite CENNI sin costo adicional (constancia CENNI validez 1 año)</li>
</ul>
HTML;

        $eletUksConfig = [
            'checkout_fields' => ['email', 'first_name', 'last_name_p', 'last_name_m', 'phone'],
            'required_docs' => [],
            'registration_docs' => [
                ['code' => 'reglamento', 'label' => 'Reglamento firmado (PDF)', 'required' => true, 'accept' => '.pdf'],
                ['code' => 'signature', 'label' => 'Firma (imagen)', 'required' => true, 'accept' => '.jpg,.jpeg,.png'],
            ],
            'card_msi' => ['enabled' => true, 'months' => [1, 3, 6], 'min_amount' => 500],
            'schedule' => [
                'weekdays' => ['start' => '10:00', 'end' => '17:30'],
                'saturday' => ['start' => '08:00', 'end' => '12:00'],
                'min_advance_days' => 2,
                'same_day_exception' => ['before' => '16:00', 'requires_admin' => true],
            ],
            'optional_addons' => [
                [
                    'product_code' => 'ELET-CENNI',
                    'label' => 'Incluir trámite CENNI (sin costo adicional)',
                    'description' => 'Constancia CENNI con validez 1 año. Trámite gestionado por DOCEO.',
                    'default' => false,
                    'price' => 0,
                ],
            ],
        ];

        $eletCenniConfig = [
            'checkout_fields' => [],
            'required_docs' => [],
            'registration_docs' => [
                ['code' => 'reglamento', 'label' => 'Reglamento firmado (PDF)', 'required' => true, 'accept' => '.pdf'],
                ['code' => 'signature', 'label' => 'Firma (imagen)', 'required' => true, 'accept' => '.jpg,.jpeg,.png'],
            ],
            'card_msi' => ['enabled' => false, 'months' => [], 'min_amount' => 0],
            'bundled_with' => 'ELET-UKS',
        ];

        $products = [
            [
                'code' => 'ELET-UKS',
                'name' => 'ELET',
                'slug' => 'elet',
                'type' => 'certification',
                'category' => 'english_adult',
                'supplier_id' => $supplierIds['uks'],
                'certifier_id' => $certifierIds['uks'],
                'short_description' => 'Examen ELeT computarizado con resultados inmediatos. Alineado CEFR y CENNI.',
                'description' => $eletDescription,
                'benefits_html' => $eletBenefits,
                'catalog_price' => 1500,
                'public_price' => 1350,
                'cost_price' => 846,
                'price_partner_a' => 1300,
                'price_partner_b' => 1250,
                'price_partner_c' => 1200,
                'price_cncm' => 846,
                'is_star' => 1,
                'audience' => 'adult',
                'platform_type' => 'none',
                'sort_order' => 1,
                'config_json' => json_encode($eletUksConfig, JSON_UNESCAPED_UNICODE),
            ],
            [
                'code' => 'ELET-CENNI',
                'name' => 'Trámite CENNI (ELeT)',
                'slug' => 'elet-cenni-tramite',
                'type' => 'procedure',
                'category' => 'english_adult',
                'supplier_id' => $supplierIds['doceo'],
                'certifier_id' => $certifierIds['cenni'],
                'short_description' => 'Trámite CENNI incluido sin costo al adquirir ELET (opcional).',
                'public_price' => 0,
                'cost_price' => 0,
                'is_star' => 0,
                'is_public' => 0,
                'audience' => 'adult',
                'platform_type' => 'none',
                'sort_order' => 99,
                'config_json' => json_encode($eletCenniConfig, JSON_UNESCAPED_UNICODE),
            ],
            [
                'code' => 'ITEP-CENNI',
                'name' => 'iTEP + CENNI',
                'slug' => 'itep-cenni',
                'type' => 'certification',
                'category' => 'english_adult',
                'supplier_id' => $supplierIds['itep'],
                'certifier_id' => $certifierIds['itep'],
                'short_description' => 'Examen iTEP con opción de trámite CENNI.',
                'public_price' => 2490,
                'is_star' => 1,
                'audience' => 'adult',
                'platform_type' => 'none',
                'sort_order' => 2,
            ],
            [
                'code' => 'TOEFL-ITP',
                'name' => 'TOEFL ITP',
                'slug' => 'toefl-itp',
                'type' => 'certification',
                'category' => 'english_adult',
                'supplier_id' => $supplierIds['linguafranca'],
                'certifier_id' => $certifierIds['toefl'],
                'short_description' => 'Aplicación sábados 11:00 (u otro horario con costo extra).',
                'public_price' => 1950,
                'is_star' => 1,
                'audience' => 'adult',
                'platform_type' => 'none',
                'sort_order' => 3,
            ],
            [
                'code' => 'OOPT',
                'name' => 'Oxford Online Placement Test (OOPT)',
                'slug' => 'oopt',
                'type' => 'certification',
                'category' => 'english_adult',
                'supplier_id' => $supplierIds['itep'],
                'certifier_id' => $certifierIds['oxford'],
                'short_description' => 'Placement online 24/7.',
                'public_price' => 500,
                'price_partner_a' => 400,
                'price_partner_b' => 400,
                'price_partner_c' => 350,
                'price_cncm' => 350,
                'cost_price' => 199,
                'is_star' => 0,
                'audience' => 'adult',
                'platform_type' => 'none',
                'sort_order' => 10,
            ],
            [
                'code' => 'MOS-EXCEL-2016',
                'name' => 'Microsoft Office Specialist Excel 2016',
                'slug' => 'mos-excel-2016',
                'type' => 'certification',
                'category' => 'it',
                'supplier_id' => $supplierIds['etc'],
                'certifier_id' => $certifierIds['microsoft'],
                'short_description' => 'Certificación Microsoft vía ETC Iberoamérica.',
                'public_price' => 1500,
                'cost_price' => 750,
                'price_cncm' => 1050,
                'price_partner_a' => 1250,
                'price_partner_b' => 1400,
                'price_partner_c' => 1350,
                'is_star' => 1,
                'audience' => 'any',
                'platform_type' => 'provider',
                'sort_order' => 4,
            ],
            [
                'code' => 'CENNI-TRAMITE',
                'name' => 'Trámite CENNI ante SEP',
                'slug' => 'tramite-cenni',
                'type' => 'procedure',
                'category' => 'other',
                'supplier_id' => $supplierIds['doceo'],
                'certifier_id' => $certifierIds['cenni'],
                'short_description' => 'Gestión DOCEO del trámite CENNI (docs + seguimiento).',
                'public_price' => 300,
                'is_star' => 0,
                'audience' => 'adult',
                'platform_type' => 'none',
                'sort_order' => 20,
            ],
            [
                'code' => 'PREP-TOEFL-MOODLE',
                'name' => 'Curso de preparación TOEFL (Moodle DOCEO)',
                'slug' => 'curso-prep-toefl',
                'type' => 'course',
                'category' => 'english_adult',
                'supplier_id' => $supplierIds['doceo'],
                'certifier_id' => $certifierIds['doceo'],
                'short_description' => 'Acceso 6 meses en campus DOCEO. Prórroga al 50%.',
                'public_price' => 1000,
                'is_star' => 1,
                'audience' => 'adult',
                'platform_type' => 'moodle',
                'access_months' => 6,
                'extension_percent' => 50,
                'sort_order' => 5,
            ],
            [
                'code' => 'TOEFL-JUNIOR',
                'name' => 'TOEFL Junior',
                'slug' => 'toefl-junior',
                'type' => 'certification',
                'category' => 'english_kids',
                'supplier_id' => $supplierIds['linguafranca'],
                'certifier_id' => $certifierIds['toefl'],
                'short_description' => 'Certificación TOEFL para menores.',
                'public_price' => 2100,
                'is_star' => 0,
                'audience' => 'kids',
                'platform_type' => 'none',
                'sort_order' => 15,
            ],
        ];

        foreach ($products as $p) {
            if (!isset($p['catalog_price'])) {
                $public = (float) $p['public_price'];
                $p['catalog_price'] = Settings::catalogPriceFromPublic($public);
            }
            $p['is_active'] = $p['is_active'] ?? 1;
            $p['is_public'] = $p['is_public'] ?? 1;
            $p['cost_price'] = $p['cost_price'] ?? 0;
            // Checkout mínimo por defecto: contacto + pago.
            // Reglamento/firma se piden después en el caso del alumno (registration_docs).
            if (!isset($p['config_json'])) {
                $type = (string) ($p['type'] ?? '');
                $registrationDocs = [];
                if (in_array($type, ['certification', 'procedure'], true)) {
                    $registrationDocs = [
                        [
                            'code' => 'reglamento',
                            'label' => 'Reglamento firmado (PDF)',
                            'required' => true,
                            'accept' => '.pdf',
                        ],
                        [
                            'code' => 'signature',
                            'label' => 'Firma (imagen)',
                            'required' => true,
                            'accept' => '.jpg,.jpeg,.png',
                        ],
                    ];
                }
                $deferred = [
                    'enabled' => in_array($type, ['certification', 'procedure'], true),
                    'months' => [1, 3, 6],
                    'min_amount' => 500,
                ];
                $p['config_json'] = json_encode([
                    'checkout_fields' => ['email', 'first_name', 'last_name_p', 'last_name_m', 'phone'],
                    'required_docs' => [],
                    'registration_docs' => $registrationDocs,
                    'card_msi' => $deferred,
                ], JSON_UNESCAPED_UNICODE);
            }
            $log[] = $upsertProduct($p);
        }

        $stmt = $pdo->prepare('SELECT id FROM combos WHERE code = ?');
        $stmt->execute(['TOEFL-FULL']);
        $comboId = $stmt->fetchColumn();
        if (!$comboId) {
            $pdo->prepare(
                'INSERT INTO combos (code, name, slug, description, is_active, is_star, public_price, catalog_price)
                 VALUES (?, ?, ?, ?, 1, 1, ?, ?)'
            )->execute([
                'TOEFL-FULL',
                'Combo TOEFL + CENNI + Preparación',
                'combo-toefl-cenni-prep',
                'Pago en una sola exhibición con precio preferencial.',
                2500,
                Settings::catalogPriceFromPublic(2500),
            ]);
            $comboId = (int) $pdo->lastInsertId();
            $log[] = 'Combo creado: TOEFL-FULL';
        } else {
            $comboId = (int) $comboId;
            $log[] = 'Combo existente: TOEFL-FULL';
        }

        $map = [];
        foreach (['TOEFL-ITP', 'CENNI-TRAMITE', 'PREP-TOEFL-MOODLE'] as $code) {
            $st = $pdo->prepare('SELECT id FROM products WHERE code = ?');
            $st->execute([$code]);
            $pid = $st->fetchColumn();
            if ($pid) {
                $map[] = (int) $pid;
            }
        }
        $pdo->prepare('DELETE FROM combo_items WHERE combo_id = ?')->execute([$comboId]);
        $ins = $pdo->prepare('INSERT INTO combo_items (combo_id, product_id, sort_order) VALUES (?, ?, ?)');
        foreach ($map as $i => $pid) {
            $ins->execute([$comboId, $pid, $i]);
        }

        $pipelines = [
            ['cert_basic', 'Certificación básica', 'certification', [
                ['registro', 'Registro completado', 'system'],
                ['docs', 'Documentos del alumno', 'student'],
                ['pago', 'Esperando pago', 'student'],
                ['confirm_pago', 'Confirmación de pago', 'admin'],
                ['asignacion', 'Asignación de códigos / solicitud proveedor', 'admin'],
                ['examen', 'Examen programado', 'student'],
                ['resultados', 'Resultados', 'admin'],
                ['fin', 'Completado', 'system'],
            ]],
            ['course_moodle', 'Curso Moodle', 'course', [
                ['pago', 'Pago confirmado', 'system'],
                ['alta_moodle', 'Alta Moodle', 'system'],
                ['activo', 'Acceso activo', 'student'],
                ['fin', 'Completado / vencido', 'system'],
            ]],
            ['cenni_doceo', 'Trámite CENNI DOCEO', 'procedure', [
                ['docs', 'Subir documentación', 'student'],
                ['revision', 'Revisión admin', 'admin'],
                ['envio_sep', 'Trámite ante SEP', 'admin'],
                ['folio', 'Folio CENNI', 'admin'],
                ['fin', 'Descarga / entrega', 'student'],
            ]],
        ];

        foreach ($pipelines as [$code, $name, $type, $steps]) {
            $st = $pdo->prepare('SELECT id FROM pipeline_templates WHERE code = ?');
            $st->execute([$code]);
            $tid = $st->fetchColumn();
            if (!$tid) {
                $pdo->prepare('INSERT INTO pipeline_templates (code, name, product_type) VALUES (?, ?, ?)')
                    ->execute([$code, $name, $type]);
                $tid = (int) $pdo->lastInsertId();
            } else {
                $tid = (int) $tid;
            }
            $pdo->prepare('DELETE FROM pipeline_steps WHERE pipeline_template_id = ?')->execute([$tid]);
            $insStep = $pdo->prepare(
                'INSERT INTO pipeline_steps (pipeline_template_id, code, label, actor, sort_order, is_terminal)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($steps as $i => [$scode, $label, $actor]) {
                $insStep->execute([$tid, $scode, $label, $actor, $i, $scode === 'fin' ? 1 : 0]);
            }
            $log[] = "Pipeline: {$code}";
        }

        $log[] = 'Seed de catálogo completado.';

        return $log;
    }
}
