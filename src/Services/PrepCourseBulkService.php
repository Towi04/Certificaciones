<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ComboRepository;
use App\Repositories\ProductGroupRepository;
use App\Repositories\ProductRepository;
use App\Support\Settings;

/**
 * Genera en lote cursos de preparación a partir de certificaciones existentes
 * y opcionalmente crea el combo (paquete) certificación + curso.
 */
final class PrepCourseBulkService
{
    private ProductRepository $products;
    private ComboRepository $combos;
    private ProductAdminService $productAdmin;
    private ComboAdminService $comboAdmin;
    private ProductMediaService $media;
    private ProductGroupRepository $groups;

    public function __construct()
    {
        $this->products = new ProductRepository();
        $this->combos = new ComboRepository();
        $this->productAdmin = new ProductAdminService();
        $this->comboAdmin = new ComboAdminService();
        $this->media = new ProductMediaService();
        $this->groups = new ProductGroupRepository();
    }

    public static function prepCourseCodeFor(string $certCode): string
    {
        $code = ProductAdminService::normalizeProductCode($certCode);
        if ($code === '') {
            return 'PREP';
        }
        if (str_starts_with($code, 'PREP-') || str_starts_with($code, 'PREP_')) {
            return $code;
        }

        return ProductAdminService::normalizeProductCode('PREP-' . $code);
    }

    /**
     * @return list<array{
     *   product: array<string,mixed>,
     *   status: 'pending'|'course_only'|'has_package',
     *   prep_code: string,
     *   existing_course: ?array<string,mixed>,
     *   existing_combo: ?array<string,mixed>
     * }>
     */
    public function listCertificationCandidates(?string $q = null, int $supplierId = 0): array
    {
        $filters = [
            'supplier_id' => $supplierId,
            'product_group_id' => 0,
            'is_public' => '',
            'is_star' => '',
            'type' => 'certification',
        ];
        $certs = $this->products->adminList($q !== null && $q !== '' ? $q : null, null, null, $filters);
        $out = [];
        foreach ($certs as $cert) {
            $prepCode = self::prepCourseCodeFor((string) ($cert['code'] ?? ''));
            $existingCourse = $this->products->findByCode($prepCode);
            if ($existingCourse !== null && (string) ($existingCourse['type'] ?? '') !== 'course') {
                $existingCourse = null;
            }
            $existingCombo = $this->combos->activeCourseComboForProduct((int) $cert['id']);
            if ($existingCombo !== null) {
                $status = 'has_package';
            } elseif ($existingCourse !== null) {
                $status = 'course_only';
            } else {
                $status = 'pending';
            }
            $out[] = [
                'product' => $cert,
                'status' => $status,
                'prep_code' => $prepCode,
                'existing_course' => $existingCourse,
                'existing_combo' => $existingCombo,
            ];
        }

        return $out;
    }

    /**
     * @param list<int|string> $certificationIds
     * @param array<string, mixed> $options
     * @return array{
     *   created_courses:int,
     *   reused_courses:int,
     *   created_combos:int,
     *   skipped:int,
     *   errors:list<string>,
     *   lines:list<string>
     * }
     */
    public function generate(array $certificationIds, array $options = []): array
    {
        $ids = [];
        foreach ($certificationIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            throw new \InvalidArgumentException('Selecciona al menos una certificación.');
        }

        $namePrefix = trim((string) ($options['name_prefix'] ?? 'Curso de preparación:'));
        if ($namePrefix === '') {
            $namePrefix = 'Curso de preparación:';
        }
        $coursePrice = round(max(0, (float) ($options['course_public_price'] ?? 0)), 2);
        $courseIsPublic = !empty($options['course_is_public']);
        $copyLogo = !isset($options['copy_logo']) || !empty($options['copy_logo']);
        $createCombo = !isset($options['create_combo']) || !empty($options['create_combo']);
        $comboDiscount = max(0, min(90, (float) ($options['combo_discount_percent'] ?? 0)));
        $groupId = isset($options['product_group_id']) ? (int) $options['product_group_id'] : 0;
        if ($groupId > 0 && $this->groups->find($groupId) === null) {
            throw new \InvalidArgumentException('El grupo de producto seleccionado no existe.');
        }
        $skipHasPackage = !isset($options['skip_has_package']) || !empty($options['skip_has_package']);

        $result = [
            'created_courses' => 0,
            'reused_courses' => 0,
            'created_combos' => 0,
            'skipped' => 0,
            'errors' => [],
            'lines' => [],
        ];

        foreach ($ids as $certId) {
            try {
                $line = $this->generateOne(
                    $certId,
                    $namePrefix,
                    $coursePrice,
                    $courseIsPublic,
                    $copyLogo,
                    $createCombo,
                    $comboDiscount,
                    $groupId > 0 ? $groupId : null,
                    $skipHasPackage
                );
                $result['lines'][] = $line['message'];
                if ($line['skipped']) {
                    $result['skipped']++;
                }
                if ($line['created_course']) {
                    $result['created_courses']++;
                }
                if ($line['reused_course']) {
                    $result['reused_courses']++;
                }
                if ($line['created_combo']) {
                    $result['created_combos']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = 'Certificación #' . $certId . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   message:string,
     *   skipped:bool,
     *   created_course:bool,
     *   reused_course:bool,
     *   created_combo:bool
     * }
     */
    private function generateOne(
        int $certId,
        string $namePrefix,
        float $coursePrice,
        bool $courseIsPublic,
        bool $copyLogo,
        bool $createCombo,
        float $comboDiscount,
        ?int $groupIdOverride,
        bool $skipHasPackage
    ): array {
        $cert = $this->products->find($certId);
        if ($cert === null) {
            throw new \InvalidArgumentException('No encontrada.');
        }
        if ((string) ($cert['type'] ?? '') !== 'certification') {
            throw new \InvalidArgumentException(
                'Solo certificaciones (tipo certification). «' . ($cert['code'] ?? '') . '» es ' . ($cert['type'] ?? '')
            );
        }
        if (!(int) ($cert['is_active'] ?? 0)) {
            throw new \InvalidArgumentException('La certificación ' . $cert['code'] . ' está inactiva.');
        }

        $existingCombo = $this->combos->activeCourseComboForProduct($certId);
        if ($existingCombo !== null && $skipHasPackage) {
            return [
                'message' => $cert['code'] . ': ya tiene paquete «' . $existingCombo['code'] . '» — omitida.',
                'skipped' => true,
                'created_course' => false,
                'reused_course' => false,
                'created_combo' => false,
            ];
        }

        $prepCode = self::prepCourseCodeFor((string) $cert['code']);
        $createdCourse = false;
        $reusedCourse = false;
        $course = $this->products->findByCode($prepCode);
        if ($course !== null && (string) ($course['type'] ?? '') !== 'course') {
            throw new \InvalidArgumentException(
                'El código ' . $prepCode . ' ya existe pero no es un curso.'
            );
        }

        if ($course === null) {
            $courseName = rtrim($namePrefix) . ' ' . trim((string) $cert['name']);
            $courseName = trim(preg_replace('/\s+/u', ' ', $courseName) ?? $courseName);
            $slug = $this->allocateUniqueSlug('curso-prep-' . (string) $cert['slug']);
            $groupId = $groupIdOverride ?? $this->nullableId($cert['product_group_id'] ?? null);

            $courseId = $this->productAdmin->createProduct([
                'code' => $prepCode,
                'name' => $courseName,
                'slug' => $slug,
                'type' => 'course',
                'category' => (string) ($cert['category'] ?? 'other'),
                'audience' => (string) ($cert['audience'] ?? 'any'),
                'platform_type' => 'none',
                'product_group_id' => $groupId,
                'supplier_id' => $this->nullableId($cert['supplier_id'] ?? null),
                'certifier_id' => $this->nullableId($cert['certifier_id'] ?? null),
                'short_description' => 'Curso de preparación para ' . trim((string) $cert['name']) . '.',
                'description' => null,
                'benefits_html' => null,
                'level_label' => $cert['level_label'] ?? null,
                'public_price' => $coursePrice,
                'catalog_price' => '',
                'cost_price' => 0,
                'moodle_course_id' => null,
                'access_months' => 6,
                'is_active' => 1,
                'is_public' => $courseIsPublic ? 1 : 0,
                'is_star' => 0,
                'sort_order' => (int) ($cert['sort_order'] ?? 100),
            ]);
            $course = $this->products->find($courseId);
            if ($course === null) {
                throw new \RuntimeException('No se pudo leer el curso recién creado.');
            }
            $createdCourse = true;

            if ($copyLogo) {
                try {
                    $this->media->copyLogoFromProduct($certId, $courseId);
                } catch (\Throwable $e) {
                    // El curso ya existe; el logo se puede subir luego.
                    error_log('[Doceo] Prep logo copy: ' . $e->getMessage());
                }
            }
        } else {
            $reusedCourse = true;
        }

        $createdCombo = false;
        if ($createCombo) {
            $exact = $this->combos->findActiveByExactProductSet([
                (int) $cert['id'],
                (int) $course['id'],
            ]);
            if ($exact === null && $existingCombo === null) {
                $certPublic = (float) ($cert['public_price'] ?? 0);
                $coursePublic = (float) ($course['public_price'] ?? $coursePrice);
                $solo = $certPublic + $coursePublic;
                $comboPublic = $comboDiscount > 0
                    ? round($solo * (1 - ($comboDiscount / 100)), 2)
                    : round($solo, 2);
                $comboName = trim((string) $cert['name']) . ' + Preparación';
                $comboCode = ComboAdminService::normalizeCode('combo-prep-' . (string) $cert['code']);
                $this->comboAdmin->create([
                    'code' => $comboCode,
                    'name' => $comboName,
                    'slug' => '',
                    'description' => 'Certificación + curso de preparación.',
                    'is_active' => 1,
                    'is_star' => 0,
                    'public_price' => $comboPublic,
                    'catalog_price' => Settings::catalogPriceFromPublic($comboPublic),
                    'price_cncm' => null,
                    'price_partner_a' => null,
                    'price_partner_b' => null,
                    'price_partner_c' => null,
                ], [(int) $cert['id'], (int) $course['id']]);
                $createdCombo = true;
            }
        }

        $parts = [$cert['code']];
        if ($createdCourse) {
            $parts[] = 'curso ' . $prepCode . ' creado';
        } elseif ($reusedCourse) {
            $parts[] = 'curso ' . $prepCode . ' reutilizado';
        }
        if ($createdCombo) {
            $parts[] = 'combo creado';
        } elseif ($createCombo) {
            $parts[] = 'combo ya existía';
        }

        return [
            'message' => implode(' · ', $parts),
            'skipped' => false,
            'created_course' => $createdCourse,
            'reused_course' => $reusedCourse,
            'created_combo' => $createdCombo,
        ];
    }

    private function allocateUniqueSlug(string $base): string
    {
        $base = ProductAdminService::slugify($base);
        $candidate = $base;
        $n = 2;
        while ($this->products->findBySlugExact($candidate) !== null) {
            $candidate = $base . '-' . $n;
            $n++;
            if ($n > 500) {
                throw new \RuntimeException('No se pudo generar un slug único para el curso.');
            }
        }

        return $candidate;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
