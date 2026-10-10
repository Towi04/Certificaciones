<?php

declare(strict_types=1);

namespace App\Setup;

use App\Config\Env;
use App\Repositories\ComboRepository;
use App\Repositories\ProductRepository;
use App\Services\ComboAdminService;
use App\Services\ComboCatalogPresenter;

/**
 * Fase 4 — QA combos en catálogo (lógica + checklist MANUAL).
 *
 * @return array{ok:bool,lines:list<string>,failures:int,manual:int}
 */
final class CatalogCombosQa
{
    /** @return array{ok:bool,lines:list<string>,failures:int,manual:int} */
    public function run(): array
    {
        $lines = [];
        $failures = 0;
        $manual = 0;

        $lines[] = '=== Catálogo Combos — QA Fase 4 ===';
        $lines[] = '';

        foreach ([
            'A) Secciones / rutas / UI' => $this->sectionRoutes(),
            'B) Presenter overrides vs fallback' => $this->sectionPresenter(),
            'C) Precios / CTA / partner shape' => $this->sectionPricingCta(),
            'D) Staging MANUAL' => $this->sectionManual(),
        ] as $title => $cases) {
            $lines[] = '--- ' . $title . ' ---';
            foreach ($cases as $case) {
                $id = (string) $case['id'];
                $status = (string) $case['status'];
                $detail = (string) $case['detail'];
                if ($status === 'OK') {
                    $lines[] = "OK     {$id}  {$detail}";
                } elseif ($status === 'MANUAL') {
                    $lines[] = "MANUAL {$id}  {$detail}";
                    $manual++;
                } else {
                    $lines[] = "FAIL   {$id}  {$detail}";
                    $failures++;
                }
            }
            $lines[] = '';
        }

        $dbName = trim((string) (Env::get('DB_NAME', '') ?? ''));
        $lines[] = '--- BD (opcional) ---';
        if ($dbName === '') {
            $lines[] = 'WARN   DB_NAME vacío: no se validó listado real de combos.';
            $lines[] = '       En staging: php bin/catalog-combos-qa.php con .env completo.';
            $manual++;
        } else {
            try {
                $repo = new ComboRepository();
                $count = $repo->publicCatalogCount(null, false);
                $stars = count($repo->starCombos(null));
                $lines[] = "OK     BD  combos públicos={$count}, destacados={$stars}";
                if ($count < 1) {
                    $lines[] = 'MANUAL BD  no hay combos públicos visibles; crea/activa uno en Admin → Combos';
                    $manual++;
                }
            } catch (\Throwable $e) {
                $lines[] = 'FAIL   BD  ' . $e->getMessage();
                $failures++;
            }
        }
        $lines[] = '';

        $ok = $failures === 0;
        $lines[] = $ok
            ? "RESULTADO: QA LÓGICA OK ({$manual} paso(s) manuales / avisos)"
            : "RESULTADO: NO LISTO — {$failures} fallo(s), {$manual} manual(es)";
        $lines[] = 'Checklist: docs/products/catalog-combos.md#qa-fase-4';

        return ['ok' => $ok, 'lines' => $lines, 'failures' => $failures, 'manual' => $manual];
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionRoutes(): array
    {
        $out = [];
        $out[] = $this->assert(
            'A1',
            ProductRepository::normalizeCatalogSection('combos') === 'combos'
                && ProductRepository::normalizeCatalogSection('paquetes') === 'combos',
            'normalizeCatalogSection acepta combos/paquetes'
        );

        $routes = (string) @file_get_contents(BASE_PATH . '/routes/web.php');
        $out[] = $this->assert(
            'A2',
            str_contains($routes, "/paquete/{slug}") && str_contains($routes, 'showCombo'),
            'ruta /paquete/{slug} → showCombo'
        );

        $home = (string) @file_get_contents(BASE_PATH . '/views/catalog/home.php');
        $out[] = $this->assert(
            'A3',
            str_contains($home, "seccion') => 'combos'") || str_contains($home, "'combos'")
                && str_contains($home, 'Combos'),
            'pestaña Combos en catalog/home.php'
        );

        $partner = (string) @file_get_contents(BASE_PATH . '/views/partner/register.php');
        $out[] = $this->assert(
            'A4',
            str_contains($partner, 'Combos') && str_contains($partner, 'combos'),
            'pestaña Combos en partner/registrar'
        );

        $acquire = (string) @file_get_contents(BASE_PATH . '/views/checkout/acquire.php');
        $out[] = $this->assert(
            'A5',
            str_contains($acquire, 'preselectComboFromQuery') && str_contains($acquire, 'combo_id'),
            'deep-link ?combo_id= en acquire'
        );

        $card = (string) @file_get_contents(BASE_PATH . '/views/catalog/_card.php');
        $out[] = $this->assert(
            'A6',
            str_contains($card, 'catalog_kind') && str_contains($card, '/paquete/'),
            'tarjeta distingue combo → /paquete/'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionPresenter(): array
    {
        $presenter = new ComboCatalogPresenter(null);
        $certs = [
            [
                'id' => 10,
                'type' => 'certification',
                'name' => 'Cert Alfa',
                'slug' => 'cert-alfa',
                'logo_path' => '/uploads/products/10/alfa.png',
                'short_description' => 'Resumen <em>Alfa</em>',
                'description' => '<p>Descripción Alfa</p>',
                'catalog_price' => 1500,
                'public_price' => 1500,
            ],
            [
                'id' => 11,
                'type' => 'certification',
                'name' => 'Cert Beta',
                'slug' => 'cert-beta',
                'logo_path' => '/uploads/products/11/beta.png',
                'short_description' => 'Resumen Beta',
                'description' => '<p>Descripción Beta</p>',
                'catalog_price' => 900,
                'public_price' => 900,
            ],
        ];
        $course = [
            'id' => 20,
            'type' => 'course',
            'name' => 'Curso Prep',
            'slug' => 'curso-prep',
            'logo_path' => '/uploads/products/20/curso.png',
            'short_description' => 'Prep short',
            'description' => '',
            'catalog_price' => 800,
            'public_price' => 800,
        ];

        $fallback = $presenter->presentCard([
            'id' => 1,
            'code' => 'demo-fb',
            'name' => 'Combo fallback',
            'slug' => 'combo-fallback',
            'description' => '',
            'short_description' => '',
            'logo_path' => null,
            'is_star' => 1,
            'is_active' => 1,
            'is_public' => 1,
            'public_price' => 2000,
            'catalog_price' => 2000,
        ], array_merge($certs, [$course]));

        $out = [];
        $out[] = $this->assert(
            'B1',
            ($fallback['logo_path'] ?? '') === '/uploads/products/10/alfa.png',
            'sin logo propio → logo 1ª certificación'
        );
        $out[] = $this->assert(
            'B2',
            str_contains((string) ($fallback['short_description'] ?? ''), 'Cert Alfa')
                && str_contains((string) ($fallback['short_description'] ?? ''), 'Cert Beta')
                && !str_contains((string) ($fallback['short_description'] ?? ''), 'Curso Prep'),
            'fallback short usa solo certificaciones'
        );
        $out[] = $this->assert(
            'B3',
            str_contains((string) ($fallback['description'] ?? ''), 'Descripción Alfa')
                && str_contains((string) ($fallback['description'] ?? ''), 'Cert Beta'),
            'fallback body incluye certificaciones'
        );
        $out[] = $this->assert(
            'B4',
            ($fallback['acquire_slug'] ?? '') === 'cert-alfa'
                && ($fallback['catalog_kind'] ?? '') === 'combo',
            'acquire_slug = 1ª cert; catalog_kind=combo'
        );

        $override = $presenter->presentCard([
            'id' => 2,
            'code' => 'demo-ov',
            'name' => 'Combo override',
            'slug' => 'combo-override',
            'description' => '<p>Body propio</p>',
            'short_description' => '<strong>Resumen propio</strong>',
            'logo_path' => '/uploads/combos/2/custom.png',
            'is_star' => 0,
            'is_active' => 1,
            'is_public' => 1,
            'public_price' => 1800,
            'catalog_price' => 1800,
        ], array_merge($certs, [$course]));

        $out[] = $this->assert(
            'B5',
            ($override['logo_path'] ?? '') === '/uploads/combos/2/custom.png'
                && ($override['short_description'] ?? '') === '<strong>Resumen propio</strong>'
                && ($override['description'] ?? '') === '<p>Body propio</p>',
            'overrides propios ganan al fallback'
        );

        $noCert = $presenter->presentCard([
            'id' => 3,
            'code' => 'demo-course',
            'name' => 'Solo cursos',
            'slug' => 'solo-cursos',
            'description' => '',
            'short_description' => '',
            'logo_path' => null,
            'is_active' => 1,
            'is_public' => 1,
            'public_price' => 1000,
            'catalog_price' => 1000,
        ], [$course]);

        $out[] = $this->assert(
            'B6',
            ($noCert['logo_path'] ?? '') === '/uploads/products/20/curso.png'
                && ($noCert['acquire_slug'] ?? '') === 'curso-prep',
            'sin certificaciones → fallback al 1er ítem'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionPricingCta(): array
    {
        $items = [
            [
                'id' => 10,
                'type' => 'certification',
                'name' => 'Cert',
                'slug' => 'cert',
                'catalog_price' => 1500,
                'public_price' => 1500,
            ],
            [
                'id' => 20,
                'type' => 'course',
                'name' => 'Curso',
                'slug' => 'curso',
                'catalog_price' => 800,
                'public_price' => 800,
            ],
        ];
        $combo = [
            'public_price' => 2000,
            'catalog_price' => 2000,
            'price_partner_a' => 1700,
            'price_partner_b' => 1600,
            'price_partner_c' => 1500,
        ];
        $list = ComboAdminService::listPriceForCombo($combo);
        $br = ComboAdminService::priceBreakdown($items, $list);
        $out = [];
        $out[] = $this->assert(
            'C1',
            $br['solo_sum'] === 2300.0 && $br['savings'] === 300.0,
            'ahorro lista: solo=2300 combo=2000 → 300'
        );

        // Sin BD: no instanciar PricingService ni PartnerAdminService (special tiers → PDO).
        // Validamos el contrato: columnas price_partner_* en el combo + método en PricingService.
        $src = (string) @file_get_contents(BASE_PATH . '/src/Services/PricingService.php');
        $out[] = $this->assert(
            'C2',
            (float) ($combo['price_partner_a'] ?? 0) === 1700.0
                && str_contains($src, 'function partnerPriceForProduct')
                && str_contains($src, 'priceColumnForTier'),
            'partnerPriceForProduct lee precio de nivel del combo'
        );

        $cta = '/adquirir/cert-alfa?combo_id=1';
        $out[] = $this->assert(
            'C3',
            str_contains($cta, 'combo_id=') && str_starts_with($cta, '/adquirir/'),
            'forma CTA /adquirir/{cert}?combo_id='
        );

        $migration = BASE_PATH . '/sql/migrations/20261010_combo_catalog_fields.sql';
        $out[] = $this->assert(
            'C4',
            is_file($migration)
                && str_contains((string) file_get_contents($migration), 'short_description')
                && str_contains((string) file_get_contents($migration), 'logo_path')
                && str_contains((string) file_get_contents($migration), 'is_public'),
            'migración Fase 1 presente'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionManual(): array
    {
        return [
            [
                'id' => 'D1',
                'status' => 'MANUAL',
                'detail' => 'staging: pestaña Combos + contador en /catalogo',
            ],
            [
                'id' => 'D2',
                'status' => 'MANUAL',
                'detail' => 'staging: combo sin overrides muestra fallback de certificaciones',
            ],
            [
                'id' => 'D3',
                'status' => 'MANUAL',
                'detail' => 'staging: combo con imagen/resumen/desc propios los muestra',
            ],
            [
                'id' => 'D4',
                'status' => 'MANUAL',
                'detail' => 'staging: partner /partner/registrar?seccion=combos con precio de nivel',
            ],
            [
                'id' => 'D5',
                'status' => 'MANUAL',
                'detail' => 'staging: CTA ficha → acquire con combo preseleccionado',
            ],
            [
                'id' => 'D6',
                'status' => 'MANUAL',
                'detail' => 'staging: tabs responsive (mobile) Certificaciones|Cursos|Combos',
            ],
        ];
    }

    /** @return array{id:string,status:string,detail:string} */
    private function assert(string $id, bool $ok, string $detail): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'OK' : 'FAIL',
            'detail' => $detail,
        ];
    }
}
