<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\PartnerRepository;
use App\Support\Settings;
use PDO;

/**
 * Alta y edición de partners (usuario + ficha + nivel de precio).
 */
final class PartnerAdminService
{
    /** Niveles de la escala (no especiales). */
    public const LADDER_TIERS = ['a', 'b', 'c'];

    /** @deprecated Usar allowedTierCodes() — se conserva por compat. */
    public const TIERS = ['cncm', 'a', 'b', 'c'];

    /** @var array<string, string>|null */
    private static ?array $tierLabelCache = null;

    private PDO $pdo;
    private PartnerRepository $partners;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->partners = new PartnerRepository();
        PartnerSpecialTierService::ensureSchema($this->pdo);
    }

    public static function clearTierCache(): void
    {
        self::$tierLabelCache = null;
    }

    /** @return array<string, string> */
    public static function ladderTierLabels(): array
    {
        return [
            'a' => 'Bronze',
            'b' => 'Silver',
            'c' => 'Gold',
        ];
    }

    /** @return array<string, string> */
    public static function tierLabels(): array
    {
        if (self::$tierLabelCache !== null) {
            return self::$tierLabelCache;
        }
        // Especiales primero (CNCM, etc.), luego escala.
        $labels = PartnerSpecialTierService::activeLabels() + self::ladderTierLabels();
        self::$tierLabelCache = $labels;

        return $labels;
    }

    public static function tierLabel(?string $tier): string
    {
        $tier = strtolower(trim((string) $tier));
        $labels = self::tierLabels();

        return $labels[$tier] ?? strtoupper($tier !== '' ? $tier : '—');
    }

    /** @return list<string> */
    public static function allowedTierCodes(): array
    {
        return array_keys(self::tierLabels());
    }

    public static function isLadderTier(string $tier): bool
    {
        return in_array(strtolower(trim($tier)), self::LADDER_TIERS, true);
    }

    public static function isSpecialTier(string $tier): bool
    {
        $tier = strtolower(trim($tier));
        if ($tier === '' || self::isLadderTier($tier)) {
            return false;
        }

        return isset(PartnerSpecialTierService::activeLabels()[$tier]);
    }

    public static function priceColumnForTier(string $tier): string
    {
        $tier = strtolower(trim($tier));
        $map = [
            'a' => 'price_partner_a',
            'b' => 'price_partner_b',
            'c' => 'price_partner_c',
        ] + PartnerSpecialTierService::activePriceColumnMap();

        return $map[$tier] ?? 'price_partner_c';
    }

    /**
     * Columnas de precio de convenios especiales activos (price_col => label).
     *
     * @return array<string, string>
     */
    public static function specialPriceFieldLabels(): array
    {
        return PartnerSpecialTierService::activePriceFields();
    }

    /**
     * Etiquetas de columnas de precio partner (BD: a/b/c → Bronze/Silver/Gold).
     *
     * @return array<string, string>
     */
    public static function priceFieldLabels(): array
    {
        return [
            'price_partner_a' => 'Partner Bronze',
            'price_partner_b' => 'Partner Silver',
            'price_partner_c' => 'Partner Gold',
        ];
    }

    /**
     * Todos los campos de precio partner mostrables: especiales + Bronze/Silver/Gold.
     *
     * @return array<string, string>
     */
    public static function allPartnerPriceFieldLabels(): array
    {
        return self::specialPriceFieldLabels() + self::priceFieldLabels();
    }

    /**
     * Columnas de precio monetarias conocidas (base + especiales + escala).
     *
     * @return list<string>
     */
    public static function allMoneyPriceColumns(): array
    {
        return array_values(array_unique(array_merge(
            ['cost_price', 'catalog_price', 'public_price'],
            array_keys(self::specialPriceFieldLabels()),
            ['price_partner_a', 'price_partner_b', 'price_partner_c']
        )));
    }

    /**
     * Encabezados CSV públicos → columnas de BD.
     * Especiales usan el nombre de columna; la escala usa bronze/silver/gold.
     *
     * @return array<string, string>
     */
    public static function priceCsvFieldMap(): array
    {
        $map = [];
        foreach (self::specialPriceFieldLabels() as $col => $_label) {
            $map[$col] = $col;
        }
        $map['price_partner_bronze'] = 'price_partner_a';
        $map['price_partner_silver'] = 'price_partner_b';
        $map['price_partner_gold'] = 'price_partner_c';

        return $map;
    }

    /** @return list<string> */
    public static function priceCsvHeaders(): array
    {
        return array_keys(self::priceCsvFieldMap());
    }

    /**
     * Resuelve un encabezado CSV / alias de formulario a la columna de BD.
     */
    public static function resolvePriceDbColumn(string $key): ?string
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return null;
        }
        $map = self::priceCsvFieldMap();
        if (isset($map[$key])) {
            return $map[$key];
        }
        if (in_array($key, ['price_partner_a', 'price_partner_b', 'price_partner_c'], true)) {
            return $key;
        }
        if (isset(self::specialPriceFieldLabels()[$key])) {
            return $key;
        }
        $aliases = [
            'bronze' => 'price_partner_a',
            'silver' => 'price_partner_b',
            'gold' => 'price_partner_c',
            'partner_bronze' => 'price_partner_a',
            'partner_silver' => 'price_partner_b',
            'partner_gold' => 'price_partner_c',
            'partner_a' => 'price_partner_a',
            'partner_b' => 'price_partner_b',
            'partner_c' => 'price_partner_c',
            'a' => 'price_partner_a',
            'b' => 'price_partner_b',
            'c' => 'price_partner_c',
            'cncm' => 'price_cncm',
        ];
        foreach (PartnerSpecialTierService::activePriceColumnMap() as $code => $col) {
            $aliases[$code] = $col;
            $aliases['partner_' . $code] = $col;
        }

        return $aliases[$key] ?? null;
    }

    /** Encabezado CSV público para una columna de BD. */
    public static function priceCsvHeaderForDb(string $dbColumn): string
    {
        foreach (self::priceCsvFieldMap() as $csv => $db) {
            if ($db === $dbColumn) {
                return $csv;
            }
        }

        return $dbColumn;
    }

    /**
     * Lee un precio partner desde un mapa CSV (prioriza bronze/silver/gold; acepta a/b/c).
     *
     * @param array<string, int> $map
     * @param list<mixed> $data
     */
    public static function csvPartnerPriceValue(array $map, array $data, string $dbColumn, mixed $default = ''): mixed
    {
        $csvHeader = self::priceCsvHeaderForDb($dbColumn);
        if (isset($map[$csvHeader])) {
            return $data[$map[$csvHeader]] ?? $default;
        }
        if (isset($map[$dbColumn])) {
            return $data[$map[$dbColumn]] ?? $default;
        }
        $short = match ($dbColumn) {
            'price_partner_a' => ['bronze', 'partner_bronze', 'partner_a', 'a'],
            'price_partner_b' => ['silver', 'partner_silver', 'partner_b', 'b'],
            'price_partner_c' => ['gold', 'partner_gold', 'partner_c', 'c'],
            default => [],
        };
        foreach ($short as $alias) {
            if (isset($map[$alias])) {
                return $data[$map[$alias]] ?? $default;
            }
        }

        return $default;
    }

    /**
     * @param array{
     *   email:string,password?:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string,
     *   code:string,display_name:string,tier:string,notes?:string,is_active?:bool|int|string,
     *   must_change_password?:bool|int|string
     * } $data
     * @return array{partner_id:int,user_id:int,plain_password:string,email_sent:bool,email_error:?string}
     */
    public function create(array $data): array
    {
        $email = strtolower(trim($data['email'] ?? ''));
        $first = trim($data['first_name'] ?? '');
        $lastP = trim($data['last_name_p'] ?? '');
        $lastM = trim($data['last_name_m'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $code = strtoupper(trim($data['code'] ?? ''));
        $display = trim($data['display_name'] ?? '');
        $tier = strtolower(trim($data['tier'] ?? 'c'));
        $notes = trim($data['notes'] ?? '');
        $active = !empty($data['is_active']);
        $mustChange = array_key_exists('must_change_password', $data)
            ? !empty($data['must_change_password'])
            : true;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }
        if ($first === '' || $lastP === '') {
            throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
        }
        if ($display === '') {
            throw new \InvalidArgumentException('El nombre comercial del partner es obligatorio.');
        }
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException('Código inválido (2–40 caracteres: A-Z, 0-9, _ o -).');
        }
        if (!in_array($tier, self::allowedTierCodes(), true)) {
            throw new \InvalidArgumentException('Nivel de partner no válido.');
        }
        if ($this->partners->codeExists($code)) {
            throw new \InvalidArgumentException('Ese código de partner ya existe.');
        }

        $stmt = $this->pdo->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ese correo ya está registrado. Usa otro o edita el partner existente.');
        }

        $plain = trim((string) ($data['password'] ?? ''));
        if ($plain === '') {
            $plain = Settings::defaultStudentPassword();
        }
        if (strlen($plain) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO users (role, email, password_hash, first_name, last_name_p, last_name_m, phone, must_change_password, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                'partner',
                $email,
                password_hash($plain, PASSWORD_DEFAULT),
                $first,
                $lastP,
                $lastM,
                $phone !== '' ? $phone : null,
                $mustChange ? 1 : 0,
                $active ? 1 : 0,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            PartnerTierService::ensureSchema($this->pdo);
            $isSpecial = self::isSpecialTier($tier);
            $tierProgram = array_key_exists('tier_program', $data)
                ? !empty($data['tier_program'])
                : !$isSpecial;
            if ($isSpecial) {
                $tierProgram = false;
            }
            $agrStart = self::normalizeDate($data['agreement_starts_at'] ?? null);
            $agrEnd = self::normalizeDate($data['agreement_ends_at'] ?? null);
            $legacyBonus = max(0, (int) ($data['legacy_sales_bonus'] ?? 0));
            $this->pdo->prepare(
                'INSERT INTO partners
                    (user_id, code, display_name, tier, tier_program, notes, legacy_sales_bonus,
                     agreement_starts_at, agreement_ends_at, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId,
                $code,
                $display,
                $tier,
                $tierProgram ? 1 : 0,
                $notes !== '' ? $notes : null,
                $legacyBonus,
                $agrStart,
                $agrEnd,
                $active ? 1 : 0,
            ]);
            $partnerId = (int) $this->pdo->lastInsertId();
            $this->syncPartnerPromoCode($partnerId, $code, $active);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'partner_id' => $partnerId,
            'user_id' => $userId,
            'plain_password' => $plain,
            'email_sent' => false,
            'email_error' => null,
        ];
    }

    /**
     * @param array{
     *   email:string,password?:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string,
     *   code:string,display_name:string,tier:string,notes?:string,is_active?:bool|int|string,
     *   must_change_password?:bool|int|string
     * } $data
     * @return array{plain_password:?string,email_sent:bool,email_error:?string}
     */
    public function update(int $partnerId, array $data): array
    {
        $partner = $this->partners->find($partnerId);
        if ($partner === null) {
            throw new \InvalidArgumentException('Partner no encontrado.');
        }

        $email = strtolower(trim($data['email'] ?? ''));
        $first = trim($data['first_name'] ?? '');
        $lastP = trim($data['last_name_p'] ?? '');
        $lastM = trim($data['last_name_m'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $code = strtoupper(trim($data['code'] ?? ''));
        $display = trim($data['display_name'] ?? '');
        $tier = strtolower(trim($data['tier'] ?? 'c'));
        $notes = trim($data['notes'] ?? '');
        $active = !empty($data['is_active']);
        $mustChange = !empty($data['must_change_password']);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }
        if ($first === '' || $lastP === '') {
            throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
        }
        if ($display === '') {
            throw new \InvalidArgumentException('El nombre comercial del partner es obligatorio.');
        }
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException('Código inválido (2–40 caracteres: A-Z, 0-9, _ o -).');
        }
        if (!in_array($tier, self::allowedTierCodes(), true)) {
            throw new \InvalidArgumentException('Nivel de partner no válido.');
        }
        if ($this->partners->codeExists($code, $partnerId)) {
            throw new \InvalidArgumentException('Ese código de partner ya existe.');
        }

        $stmt = $this->pdo->prepare('SELECT id, role FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $stmt->execute([$email, (int) $partner['user_id']]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ese correo ya pertenece a otra cuenta.');
        }

        $plain = trim((string) ($data['password'] ?? ''));
        if ($plain !== '' && strlen($plain) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($plain !== '') {
                $this->pdo->prepare(
                    'UPDATE users
                     SET email = ?, password_hash = ?, first_name = ?, last_name_p = ?, last_name_m = ?,
                         phone = ?, must_change_password = ?, is_active = ?
                     WHERE id = ?'
                )->execute([
                    $email,
                    password_hash($plain, PASSWORD_DEFAULT),
                    $first,
                    $lastP,
                    $lastM,
                    $phone !== '' ? $phone : null,
                    $mustChange ? 1 : 0,
                    $active ? 1 : 0,
                    (int) $partner['user_id'],
                ]);
            } else {
                $this->pdo->prepare(
                    'UPDATE users
                     SET email = ?, first_name = ?, last_name_p = ?, last_name_m = ?,
                         phone = ?, must_change_password = ?, is_active = ?
                     WHERE id = ?'
                )->execute([
                    $email,
                    $first,
                    $lastP,
                    $lastM,
                    $phone !== '' ? $phone : null,
                    $mustChange ? 1 : 0,
                    $active ? 1 : 0,
                    (int) $partner['user_id'],
                ]);
            }

            PartnerTierService::ensureSchema($this->pdo);
            $isSpecial = self::isSpecialTier($tier);
            $tierProgram = array_key_exists('tier_program', $data)
                ? !empty($data['tier_program'])
                : !$isSpecial;
            if ($isSpecial) {
                $tierProgram = false;
            }
            $agrStart = self::normalizeDate($data['agreement_starts_at'] ?? null);
            $agrEnd = self::normalizeDate($data['agreement_ends_at'] ?? null);
            $legacyBonus = max(0, (int) ($data['legacy_sales_bonus'] ?? 0));
            $this->pdo->prepare(
                'UPDATE partners
                 SET code = ?, display_name = ?, tier = ?, tier_program = ?, notes = ?,
                     legacy_sales_bonus = ?,
                     agreement_starts_at = ?, agreement_ends_at = ?, is_active = ?
                 WHERE id = ?'
            )->execute([
                $code,
                $display,
                $tier,
                $tierProgram ? 1 : 0,
                $notes !== '' ? $notes : null,
                $legacyBonus,
                $agrStart,
                $agrEnd,
                $active ? 1 : 0,
                $partnerId,
            ]);

            $this->syncPartnerPromoCode($partnerId, $code, $active);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'plain_password' => $plain !== '' ? $plain : null,
            'email_sent' => false,
            'email_error' => null,
        ];
    }

    /**
     * El código del partner funciona en checkout como un código promocional DOCEO:
     * el alumno paga precio público y la diferencia vs el precio del nivel se abona
     * como crédito al partner (PricingService + confirmPayment).
     */
    public function syncPartnerPromoCode(int $partnerId, string $code, bool $active): void
    {
        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException('Código de partner inválido para promoción.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, partner_id, type FROM discount_codes WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $byCode = $stmt->fetch() ?: null;

        if ($byCode !== null) {
            $owner = (int) ($byCode['partner_id'] ?? 0);
            $type = (string) ($byCode['type'] ?? '');
            if ($owner !== $partnerId) {
                if ($owner > 0) {
                    throw new \InvalidArgumentException('Ese código ya está asignado a otro partner.');
                }
                if ($type !== 'partner') {
                    throw new \InvalidArgumentException(
                        'Ese código ya existe como promoción DOCEO/campaña. Elige otro código de partner.'
                    );
                }
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, code FROM discount_codes WHERE partner_id = ? AND type = ? LIMIT 1'
        );
        $stmt->execute([$partnerId, 'partner']);
        $byPartner = $stmt->fetch() ?: null;

        if ($byPartner !== null) {
            $this->pdo->prepare(
                'UPDATE discount_codes
                 SET code = ?, discount_mode = ?, applies_to_combos = 1, is_active = ?, partner_id = ?
                 WHERE id = ?'
            )->execute([
                $code,
                'partner_public',
                $active ? 1 : 0,
                $partnerId,
                (int) $byPartner['id'],
            ]);

            return;
        }

        if ($byCode !== null && (int) ($byCode['partner_id'] ?? 0) === $partnerId) {
            $this->pdo->prepare(
                'UPDATE discount_codes
                 SET type = ?, discount_mode = ?, applies_to_combos = 1, is_active = ?
                 WHERE id = ?'
            )->execute([
                'partner',
                'partner_public',
                $active ? 1 : 0,
                (int) $byCode['id'],
            ]);

            return;
        }

        $this->pdo->prepare(
            'INSERT INTO discount_codes (code, type, partner_id, discount_mode, discount_value, applies_to_combos, is_active)
             VALUES (?, ?, ?, ?, NULL, 1, ?)'
        )->execute([
            $code,
            'partner',
            $partnerId,
            'partner_public',
            $active ? 1 : 0,
        ]);
    }

    /** @return int Número de partners sincronizados */
    public function syncAllPartnerPromoCodes(): int
    {
        $rows = $this->pdo->query(
            'SELECT id, code, is_active FROM partners ORDER BY id ASC'
        )->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            $this->syncPartnerPromoCode(
                (int) $row['id'],
                (string) $row['code'],
                (int) ($row['is_active'] ?? 0) === 1
            );
            $n++;
        }

        return $n;
    }

    /**
     * Genera nueva contraseña temporal (sin enviar correo; se muestra en el panel).
     *
     * @return array{plain_password:string,email_sent:bool,email_error:?string}
     */
    public function resetPasswordAndEmail(int $partnerId, ?string $password = null): array
    {
        $partner = $this->partners->find($partnerId);
        if ($partner === null) {
            throw new \InvalidArgumentException('Partner no encontrado.');
        }

        $plain = trim((string) ($password ?? ''));
        if ($plain === '') {
            $plain = Settings::defaultStudentPassword();
        }
        if (strlen($plain) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?'
        )->execute([
            password_hash($plain, PASSWORD_DEFAULT),
            (int) $partner['user_id'],
        ]);

        return [
            'plain_password' => $plain,
            'email_sent' => false,
            'email_error' => null,
        ];
    }

    private static function normalizeDate(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            throw new \InvalidArgumentException('Fecha de convenio inválida (usa YYYY-MM-DD).');
        }

        return $s;
    }
}
