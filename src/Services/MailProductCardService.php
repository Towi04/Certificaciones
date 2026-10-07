<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Repositories\ProductRepository;
use App\Support\Settings;
use PDO;

/**
 * Tarjetas de producto para plantillas de correo.
 * Placeholder {{elet}} (o el que configures) se expanden a HTML de tarjeta
 * con logo, nombre, enlace al catálogo y badge de descuento / franja.
 */
final class MailProductCardService
{
    public const SETTINGS_KEY = 'mail_product_card_defaults';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureTables();
    }

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS mail_product_cards (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                placeholder VARCHAR(60) NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                layout ENUM('square','wide','row') NOT NULL DEFAULT 'wide',
                badge_mode ENUM('discount','banner','both','none') NOT NULL DEFAULT 'discount',
                badge_text VARCHAR(80) NULL,
                custom_image_path VARCHAR(255) NULL,
                show_description TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mail_product_cards_placeholder (placeholder),
                KEY idx_mail_product_cards_product (product_id),
                KEY idx_mail_product_cards_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM mail_product_cards LIKE \'custom_image_path\'');
            if ($cols && !$cols->fetch()) {
                $this->pdo->exec(
                    'ALTER TABLE mail_product_cards ADD COLUMN custom_image_path VARCHAR(255) NULL AFTER badge_text'
                );
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    /** @return array{default_layout:string,default_badge:string,banner_text:string,accent_color:string,cta_label:string} */
    public static function defaults(): array
    {
        $raw = Settings::get(self::SETTINGS_KEY, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        return [
            'default_layout' => self::normalizeLayout((string) ($decoded['default_layout'] ?? 'wide')),
            'default_badge' => self::normalizeBadge((string) ($decoded['default_badge'] ?? 'discount')),
            'banner_text' => trim((string) ($decoded['banner_text'] ?? 'Solicita tu descuento')) ?: 'Solicita tu descuento',
            'accent_color' => self::normalizeColor((string) ($decoded['accent_color'] ?? '#315285')),
            'cta_label' => trim((string) ($decoded['cta_label'] ?? 'Ver en catálogo')) ?: 'Ver en catálogo',
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveDefaults(array $input): void
    {
        $data = [
            'default_layout' => self::normalizeLayout((string) ($input['default_layout'] ?? 'wide')),
            'default_badge' => self::normalizeBadge((string) ($input['default_badge'] ?? 'discount')),
            'banner_text' => trim((string) ($input['banner_text'] ?? 'Solicita tu descuento')) ?: 'Solicita tu descuento',
            'accent_color' => self::normalizeColor((string) ($input['accent_color'] ?? '#315285')),
            'cta_label' => trim((string) ($input['cta_label'] ?? 'Ver en catálogo')) ?: 'Ver en catálogo',
        ];
        Settings::set(self::SETTINGS_KEY, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.*, p.name AS product_name, p.code AS product_code, p.slug AS product_slug,
                    p.logo_path, p.short_description, p.catalog_price, p.public_price, p.is_public
             FROM mail_product_cards c
             INNER JOIN products p ON p.id = c.product_id
             ORDER BY c.sort_order ASC, c.placeholder ASC'
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => !empty($r['is_active'])));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.name AS product_name, p.code AS product_code, p.slug AS product_slug,
                    p.logo_path, p.short_description, p.catalog_price, p.public_price
             FROM mail_product_cards c
             INNER JOIN products p ON p.id = c.product_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByPlaceholder(string $placeholder): ?array
    {
        $key = self::normalizePlaceholder($placeholder);
        if ($key === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.name AS product_name, p.code AS product_code, p.slug AS product_slug,
                    p.logo_path, p.short_description, p.catalog_price, p.public_price
             FROM mail_product_cards c
             INNER JOIN products p ON p.id = c.product_id
             WHERE c.placeholder = ? AND c.is_active = 1 LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $imageFile $_FILES['custom_image']
     */
    public function create(array $input, ?array $imageFile = null): int
    {
        $data = $this->normalizeCardInput($input);
        $customImage = null;
        if ($imageFile !== null && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $customImage = self::storeCardImageUpload($imageFile);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_product_cards
                (placeholder, product_id, layout, badge_mode, badge_text, custom_image_path,
                 show_description, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['placeholder'],
            $data['product_id'],
            $data['layout'],
            $data['badge_mode'],
            $data['badge_text'],
            $customImage,
            $data['show_description'],
            $data['is_active'],
            $data['sort_order'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $imageFile $_FILES['custom_image']
     */
    public function update(int $id, array $input, ?array $imageFile = null): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Tarjeta no encontrada.');
        }
        $data = $this->normalizeCardInput($input, $id);
        $customImage = ($existing['custom_image_path'] ?? null) !== null && $existing['custom_image_path'] !== ''
            ? (string) $existing['custom_image_path']
            : null;

        if (!empty($input['clear_custom_image'])) {
            $this->deleteStoredImage($customImage);
            $customImage = null;
        }
        if ($imageFile !== null && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->deleteStoredImage($customImage);
            $customImage = self::storeCardImageUpload($imageFile);
        }

        $this->pdo->prepare(
            'UPDATE mail_product_cards
             SET placeholder = ?, product_id = ?, layout = ?, badge_mode = ?, badge_text = ?,
                 custom_image_path = ?, show_description = ?, is_active = ?, sort_order = ?
             WHERE id = ?'
        )->execute([
            $data['placeholder'],
            $data['product_id'],
            $data['layout'],
            $data['badge_mode'],
            $data['badge_text'],
            $customImage,
            $data['show_description'],
            $data['is_active'],
            $data['sort_order'],
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing !== null) {
            $this->deleteStoredImage(
                ($existing['custom_image_path'] ?? null) !== null ? (string) $existing['custom_image_path'] : null
            );
        }
        $this->pdo->prepare('DELETE FROM mail_product_cards WHERE id = ?')->execute([$id]);
    }

    /**
     * Vista previa sin guardar (admin).
     *
     * @param array<string, mixed> $input
     */
    public function previewFromInput(array $input): string
    {
        $productId = (int) ($input['product_id'] ?? 0);
        if ($productId < 1) {
            return '<p class="muted" style="margin:0;font-size:.85rem">Elige un producto para ver la vista previa.</p>';
        }
        $product = (new ProductRepository())->find($productId);
        if ($product === null) {
            return '<p class="muted" style="margin:0;font-size:.85rem">Producto no encontrado.</p>';
        }

        $card = [
            'product_name' => (string) ($product['name'] ?? ''),
            'product_slug' => (string) ($product['slug'] ?? ''),
            'logo_path' => (string) ($product['logo_path'] ?? ''),
            'custom_image_path' => trim((string) ($input['custom_image_path'] ?? '')),
            'short_description' => (string) ($product['short_description'] ?? ''),
            'catalog_price' => $product['catalog_price'] ?? 0,
            'public_price' => $product['public_price'] ?? 0,
            'layout' => (string) ($input['layout'] ?? 'wide'),
            'badge_mode' => (string) ($input['badge_mode'] ?? 'discount'),
            'badge_text' => (string) ($input['badge_text'] ?? ''),
            'show_description' => !empty($input['show_description']) ? 1 : 0,
        ];
        if ($card['custom_image_path'] === '') {
            $card['custom_image_path'] = null;
        }

        $previewUrl = trim((string) ($input['preview_image_url'] ?? ''));
        if ($previewUrl !== '' && self::isAllowedPreviewImageUrl($previewUrl)) {
            $card['_preview_image_url'] = $previewUrl;
        }

        // Defaults temporales desde el formulario de diseño (sin guardar).
        $defaults = self::defaults();
        if (isset($input['accent_color']) || isset($input['banner_text']) || isset($input['cta_label'])) {
            $defaults['accent_color'] = self::normalizeColor((string) ($input['accent_color'] ?? $defaults['accent_color']));
            $defaults['banner_text'] = trim((string) ($input['banner_text'] ?? $defaults['banner_text'])) ?: $defaults['banner_text'];
            $defaults['cta_label'] = trim((string) ($input['cta_label'] ?? $defaults['cta_label'])) ?: $defaults['cta_label'];
        }

        return $this->renderCard($card, $defaults);
    }

    /**
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     */
    public static function storeCardImageUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona una imagen válida para la tarjeta.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \InvalidArgumentException('Archivo de imagen inválido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('La imagen no debe superar 4 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'card.png'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            throw new \InvalidArgumentException('Usa PNG, JPG, WEBP o GIF.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: ($file['type'] ?? ''));
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            throw new \InvalidArgumentException('Tipo de imagen no permitido.');
        }

        $relativeDir = '/uploads/mail/product-cards';
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de imágenes de tarjetas.');
        }

        $filename = 'card-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
                throw new \RuntimeException('No se pudo guardar la imagen de la tarjeta.');
            }
            @unlink($tmp);
        }

        return $relativeDir . '/' . $filename;
    }

    /**
     * Expande {{placeholder}} de tarjetas y {{cards:a,b,c}} / {{cards_grid:a,b}} en el HTML.
     *
     * @param array<string, string> $vars
     */
    public function expandInHtml(string $html, array $vars = []): string
    {
        if ($html === '' || !str_contains($html, '{{')) {
            return $html;
        }

        $reserved = self::reservedPlaceholderKeys();
        foreach ($vars as $k => $_) {
            $reserved[self::normalizePlaceholder((string) $k)] = true;
        }

        // Grids: {{cards:elet,toefl}} o {{cards_grid:elet,toefl,excel}}
        $html = (string) preg_replace_callback(
            '/\{\{\s*(cards(?:_grid)?)\s*:\s*([a-zA-Z0-9_,\-\s]+?)\s*\}\}/u',
            function (array $m): string {
                $keys = preg_split('/\s*,\s*/', trim($m[2])) ?: [];
                $keys = array_values(array_filter(array_map([self::class, 'normalizePlaceholder'], $keys)));
                if ($keys === []) {
                    return $m[0];
                }

                return $this->renderGrid($keys);
            },
            $html
        );

        // Tarjetas sueltas: {{elet}}
        $html = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/u',
            function (array $m) use ($reserved): string {
                $key = self::normalizePlaceholder($m[1]);
                if ($key === '' || isset($reserved[$key])) {
                    return $m[0];
                }
                // Prefijos de docs dinámicos no son tarjetas.
                if (str_starts_with($key, 'doc_')) {
                    return $m[0];
                }
                $card = $this->findByPlaceholder($key);
                if ($card === null) {
                    return $m[0];
                }

                return $this->renderCard($card);
            },
            $html
        );

        return $html;
    }

    /** @return array<string, string> placeholder => descripción */
    public function placeholderCatalog(): array
    {
        $out = [];
        foreach ($this->active() as $card) {
            $ph = (string) $card['placeholder'];
            $out[$ph] = 'Tarjeta: ' . (string) ($card['product_name'] ?? $ph);
        }
        $out['cards:…'] = 'Grid de tarjetas, ej. {{cards:elet,toefl}}';

        return $out;
    }

    /**
     * HTML de muestra para el editor de plantillas (vista previa en el navegador).
     *
     * @return array<string, string>
     */
    public function samplePreviewVars(): array
    {
        $vars = [];
        foreach ($this->active() as $card) {
            $ph = (string) $card['placeholder'];
            $vars[$ph] = $this->renderCard($card);
        }

        return $vars;
    }

    /**
     * @param array<string, mixed> $card
     */
    public function renderCard(array $card, ?array $defaults = null): string
    {
        $defaults = $defaults ?? self::defaults();
        $name = trim((string) ($card['product_name'] ?? 'Certificación'));
        $slug = trim((string) ($card['product_slug'] ?? ''));
        $layout = self::normalizeLayout((string) ($card['layout'] ?? $defaults['default_layout']));
        $badgeMode = self::normalizeBadge((string) ($card['badge_mode'] ?? $defaults['default_badge']));
        $bannerText = trim((string) ($card['badge_text'] ?? '')) ?: $defaults['banner_text'];
        $showDesc = !empty($card['show_description']);
        $desc = trim(strip_tags((string) ($card['short_description'] ?? '')));
        if (mb_strlen($desc) > 140) {
            $desc = mb_substr($desc, 0, 137) . '…';
        }

        $list = (float) ($card['catalog_price'] ?? 0);
        $public = (float) ($card['public_price'] ?? 0);
        $discountPct = self::discountPercent($list, $public);

        $productUrl = $this->absoluteUrl($slug !== '' ? '/producto/' . $slug : '/catalogo');
        $logoUrl = $this->resolveCardImageUrl($card);

        $accent = $defaults['accent_color'];
        $cta = htmlspecialchars($defaults['cta_label'], ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8');
        // data: URLs en preview admin; el resto se escapa normal.
        $safeLogo = str_starts_with($logoUrl, 'data:image/')
            ? $logoUrl
            : htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $safeBanner = htmlspecialchars($bannerText, ENT_QUOTES, 'UTF-8');
        $safeDesc = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');

        $badgeHtml = '';
        if (($badgeMode === 'discount' || $badgeMode === 'both') && $discountPct > 0) {
            $badgeHtml .= '<span style="display:inline-block;background:#dc2626;color:#fff;font-weight:800;'
                . 'font-size:12px;line-height:1;padding:6px 8px;border-radius:999px;">-'
                . (int) $discountPct . '%</span>';
        }
        if ($badgeMode === 'banner' || $badgeMode === 'both') {
            $badgeHtml .= ($badgeHtml !== '' ? '&nbsp;' : '')
                . '<span style="display:inline-block;background:' . $accent . ';color:#fff;font-weight:700;'
                . 'font-size:11px;line-height:1.2;padding:5px 8px;border-radius:6px;">'
                . $safeBanner . '</span>';
        }

        if ($layout === 'row') {
            return $this->htmlRowCard($safeUrl, $safeLogo, $safeName, $safeDesc, $showDesc, $badgeHtml, $cta, $accent);
        }
        if ($layout === 'square') {
            return $this->htmlSquareCard($safeUrl, $safeLogo, $safeName, $badgeHtml, $cta, $accent);
        }

        return $this->htmlWideCard($safeUrl, $safeLogo, $safeName, $safeDesc, $showDesc, $badgeHtml, $cta, $accent);
    }

    /** @param list<string> $placeholders */
    public function renderGrid(array $placeholders): string
    {
        $cards = [];
        foreach ($placeholders as $ph) {
            $card = $this->findByPlaceholder($ph);
            if ($card !== null) {
                // En grid forzar square si la tarjeta era wide, salvo row.
                if (($card['layout'] ?? '') === 'wide') {
                    $card['layout'] = 'square';
                }
                $cards[] = $this->renderCard($card);
            }
        }
        if ($cards === []) {
            return '';
        }

        $n = count($cards);
        $cols = $n <= 2 ? $n : ($n <= 4 ? 2 : 3);
        $width = (int) floor(100 / $cols);
        $rows = array_chunk($cards, $cols);
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:12px 0;">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td width="' . $width . '%" valign="top" style="padding:6px;vertical-align:top;">'
                    . $cell . '</td>';
            }
            // Rellenar celdas vacías para alinear.
            $pad = $cols - count($row);
            for ($i = 0; $i < $pad; $i++) {
                $html .= '<td width="' . $width . '%" style="padding:6px;">&nbsp;</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    public static function discountPercent(float $listPrice, float $publicPrice): int
    {
        if ($listPrice <= 0 || $publicPrice <= 0 || $publicPrice >= $listPrice) {
            return 0;
        }

        return (int) max(1, min(95, (int) round((($listPrice - $publicPrice) / $listPrice) * 100)));
    }

    public static function normalizePlaceholder(string $raw): string
    {
        $key = strtolower(trim($raw));
        $key = str_replace([' ', '-'], '_', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';

        return $key;
    }

    /** @return array<string, bool> */
    public static function reservedPlaceholderKeys(): array
    {
        $keys = [];
        foreach (MailTemplateService::availablePlaceholderOptions() as $group) {
            foreach (array_keys($group) as $k) {
                $keys[] = (string) $k;
            }
        }
        $extra = [
            'name', 'full_name', 'first_name', 'promo_code', 'catalog_url', 'login_url',
            'product_name', 'certificacion', 'student_email', 'student_phone',
            'partner_name', 'partner_code', 'partner_email', 'matricula',
            'cards', 'cards_grid',
        ];
        $out = [];
        foreach (array_merge($keys, $extra) as $k) {
            $n = self::normalizePlaceholder((string) $k);
            if ($n !== '' && !str_contains($n, '*')) {
                $out[$n] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   placeholder:string,product_id:int,layout:string,badge_mode:string,
     *   badge_text:?string,show_description:int,is_active:int,sort_order:int
     * }
     */
    private function normalizeCardInput(array $input, ?int $ignoreId = null): array
    {
        $placeholder = self::normalizePlaceholder((string) ($input['placeholder'] ?? ''));
        if ($placeholder === '' || strlen($placeholder) < 2) {
            throw new \InvalidArgumentException('Indica un placeholder válido (ej. elet, toefl_itp).');
        }
        if (isset(self::reservedPlaceholderKeys()[$placeholder])) {
            throw new \InvalidArgumentException(
                'El placeholder {{' . $placeholder . '}} está reservado por el sistema. Elige otro nombre.'
            );
        }

        $productId = (int) ($input['product_id'] ?? 0);
        if ($productId < 1 || (new ProductRepository())->find($productId) === null) {
            throw new \InvalidArgumentException('Elige un producto del catálogo.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM mail_product_cards WHERE placeholder = ?'
            . ($ignoreId !== null ? ' AND id != ' . (int) $ignoreId : '')
            . ' LIMIT 1'
        );
        $stmt->execute([$placeholder]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ya existe una tarjeta con {{' . $placeholder . '}}.');
        }

        $badgeText = trim((string) ($input['badge_text'] ?? ''));

        return [
            'placeholder' => $placeholder,
            'product_id' => $productId,
            'layout' => self::normalizeLayout((string) ($input['layout'] ?? 'wide')),
            'badge_mode' => self::normalizeBadge((string) ($input['badge_mode'] ?? 'discount')),
            'badge_text' => $badgeText !== '' ? mb_substr($badgeText, 0, 80) : null,
            'show_description' => !empty($input['show_description']) ? 1 : 0,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'sort_order' => max(0, min(9999, (int) ($input['sort_order'] ?? 0))),
        ];
    }

    private static function normalizeLayout(string $layout): string
    {
        return in_array($layout, ['square', 'wide', 'row'], true) ? $layout : 'wide';
    }

    private static function normalizeBadge(string $badge): string
    {
        return in_array($badge, ['discount', 'banner', 'both', 'none'], true) ? $badge : 'discount';
    }

    private static function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1) {
            return strtoupper($color);
        }

        return '#315285';
    }

    /** @param array<string, mixed> $card */
    private function resolveCardImageUrl(array $card): string
    {
        $preview = trim((string) ($card['_preview_image_url'] ?? ''));
        if ($preview !== '' && self::isAllowedPreviewImageUrl($preview)) {
            return $preview;
        }
        $custom = trim((string) ($card['custom_image_path'] ?? ''));
        if ($custom !== '') {
            return $this->absoluteUrl(asset($custom));
        }
        $logoPath = trim((string) ($card['logo_path'] ?? ''));
        if ($logoPath !== '') {
            return $this->absoluteUrl(asset($logoPath));
        }

        return $this->absoluteUrl('/assets/brand/logo.png');
    }

    private static function isAllowedPreviewImageUrl(string $url): bool
    {
        if (str_starts_with($url, 'data:image/png;base64,')
            || str_starts_with($url, 'data:image/jpeg;base64,')
            || str_starts_with($url, 'data:image/jpg;base64,')
            || str_starts_with($url, 'data:image/webp;base64,')
            || str_starts_with($url, 'data:image/gif;base64,')
        ) {
            return strlen($url) < 3_500_000;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }

        return false;
    }

    private function deleteStoredImage(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        if (!str_starts_with($relativePath, '/uploads/mail/product-cards/')) {
            return;
        }
        $full = BASE_PATH . '/public' . $relativePath;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    private function absoluteUrl(string $pathOrUrl): string
    {
        $v = trim($pathOrUrl);
        if ($v === '') {
            return '';
        }
        // asset() puede devolver ?v= — quitar query para URL limpia en correo opcionalmente se deja.
        if (preg_match('#^https?://#i', $v) === 1) {
            return $v;
        }
        $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
        if ($base === '') {
            return $v;
        }
        if (str_starts_with($v, '/')) {
            return $base . $v;
        }

        return $base . '/' . ltrim($v, '/');
    }

    private function htmlWideCard(
        string $url,
        string $logo,
        string $name,
        string $desc,
        bool $showDesc,
        string $badgeHtml,
        string $cta,
        string $accent
    ): string {
        $descBlock = ($showDesc && $desc !== '')
            ? '<p style="margin:0 0 10px;font-size:13px;line-height:1.4;color:#64748b;">' . $desc . '</p>'
            : '';

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse;margin:10px 0;border:1px solid #e6ebf2;border-radius:14px;'
            . 'overflow:hidden;background:#ffffff;">'
            . '<tr><td style="padding:0;background:linear-gradient(160deg,#f7f9fc,#e8eef7);text-align:center;'
            . 'height:140px;vertical-align:middle;position:relative;">'
            . '<a href="' . $url . '" style="text-decoration:none;display:block;padding:16px;">'
            . '<img src="' . $logo . '" alt="' . $name . '" width="220" '
            . 'style="max-width:220px;max-height:100px;width:auto;height:auto;border:0;display:inline-block;">'
            . '</a>'
            . ($badgeHtml !== ''
                ? '<div style="padding:0 12px 12px 12px;text-align:left;">' . $badgeHtml . '</div>'
                : '')
            . '</td></tr>'
            . '<tr><td style="padding:14px 16px 16px;">'
            . '<p style="margin:0 0 6px;font-size:16px;font-weight:800;color:' . $accent . ';line-height:1.3;">'
            . '<a href="' . $url . '" style="color:' . $accent . ';text-decoration:none;">' . $name . '</a></p>'
            . $descBlock
            . '<a href="' . $url . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;'
            . 'text-decoration:none;font-weight:700;font-size:13px;padding:9px 14px;border-radius:8px;">'
            . $cta . '</a>'
            . '</td></tr></table>';
    }

    private function htmlSquareCard(
        string $url,
        string $logo,
        string $name,
        string $badgeHtml,
        string $cta,
        string $accent
    ): string {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse;border:1px solid #e6ebf2;border-radius:12px;'
            . 'overflow:hidden;background:#ffffff;max-width:260px;">'
            . '<tr><td style="padding:12px;background:linear-gradient(160deg,#f7f9fc,#e8eef7);text-align:center;'
            . 'height:110px;vertical-align:middle;">'
            . '<a href="' . $url . '" style="text-decoration:none;">'
            . '<img src="' . $logo . '" alt="' . $name . '" width="140" '
            . 'style="max-width:140px;max-height:80px;width:auto;height:auto;border:0;">'
            . '</a></td></tr>'
            . '<tr><td style="padding:10px 12px 12px;text-align:center;">'
            . ($badgeHtml !== '' ? '<div style="margin:0 0 8px;">' . $badgeHtml . '</div>' : '')
            . '<p style="margin:0 0 8px;font-size:13px;font-weight:800;color:' . $accent . ';line-height:1.25;">'
            . '<a href="' . $url . '" style="color:' . $accent . ';text-decoration:none;">' . $name . '</a></p>'
            . '<a href="' . $url . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;'
            . 'text-decoration:none;font-weight:700;font-size:12px;padding:7px 10px;border-radius:8px;">'
            . $cta . '</a>'
            . '</td></tr></table>';
    }

    private function htmlRowCard(
        string $url,
        string $logo,
        string $name,
        string $desc,
        bool $showDesc,
        string $badgeHtml,
        string $cta,
        string $accent
    ): string {
        $descBlock = ($showDesc && $desc !== '')
            ? '<p style="margin:0 0 8px;font-size:12px;line-height:1.4;color:#64748b;">' . $desc . '</p>'
            : '';

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse;margin:10px 0;border:1px solid #e6ebf2;border-radius:12px;'
            . 'overflow:hidden;background:#ffffff;">'
            . '<tr>'
            . '<td width="38%" style="padding:12px;background:linear-gradient(160deg,#f7f9fc,#e8eef7);'
            . 'text-align:center;vertical-align:middle;">'
            . '<a href="' . $url . '" style="text-decoration:none;">'
            . '<img src="' . $logo . '" alt="' . $name . '" width="150" '
            . 'style="max-width:150px;max-height:90px;width:auto;height:auto;border:0;">'
            . '</a></td>'
            . '<td width="62%" style="padding:14px 16px;vertical-align:middle;">'
            . ($badgeHtml !== '' ? '<div style="margin:0 0 8px;">' . $badgeHtml . '</div>' : '')
            . '<p style="margin:0 0 6px;font-size:15px;font-weight:800;color:' . $accent . ';">'
            . '<a href="' . $url . '" style="color:' . $accent . ';text-decoration:none;">' . $name . '</a></p>'
            . $descBlock
            . '<a href="' . $url . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;'
            . 'text-decoration:none;font-weight:700;font-size:12px;padding:8px 12px;border-radius:8px;">'
            . $cta . '</a>'
            . '</td></tr></table>';
    }
}
