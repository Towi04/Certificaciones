<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Integrations\Mailer;
use App\Mail\MailBranding;
use App\Repositories\PartnerRepository;
use App\Support\Settings;
use PDO;

/**
 * Alta y edición de partners (usuario + ficha + nivel de precio).
 */
final class PartnerAdminService
{
    public const TIERS = ['cncm', 'a', 'b', 'c'];

    private PDO $pdo;
    private PartnerRepository $partners;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->partners = new PartnerRepository();
    }

    /** @return array<string, string> */
    public static function tierLabels(): array
    {
        return [
            'cncm' => 'CNCM',
            'a' => 'Nivel A',
            'b' => 'Nivel B',
            'c' => 'Nivel C',
        ];
    }

    /**
     * @param array{
     *   email:string,password?:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string,
     *   code:string,display_name:string,tier:string,notes?:string,is_active?:bool|int|string,
     *   must_change_password?:bool|int|string,send_email?:bool|int|string
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
        if (!in_array($tier, self::TIERS, true)) {
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

            $this->pdo->prepare(
                'INSERT INTO partners (user_id, code, display_name, tier, notes, is_active)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $userId,
                $code,
                $display,
                $tier,
                $notes !== '' ? $notes : null,
                $active ? 1 : 0,
            ]);
            $partnerId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $emailSent = false;
        $emailError = null;
        $shouldEmail = array_key_exists('send_email', $data) ? !empty($data['send_email']) : true;
        if ($shouldEmail) {
            $mail = $this->sendAccessEmail([
                'email' => $email,
                'first_name' => $first,
                'last_name_p' => $lastP,
                'display_name' => $display,
                'code' => $code,
                'tier' => $tier,
            ], $plain, true);
            $emailSent = $mail['ok'];
            $emailError = $mail['error'];
        }

        return [
            'partner_id' => $partnerId,
            'user_id' => $userId,
            'plain_password' => $plain,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ];
    }

    /**
     * @param array{
     *   email:string,password?:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string,
     *   code:string,display_name:string,tier:string,notes?:string,is_active?:bool|int|string,
     *   must_change_password?:bool|int|string,send_email?:bool|int|string
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
        if (!in_array($tier, self::TIERS, true)) {
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

            $this->pdo->prepare(
                'UPDATE partners
                 SET code = ?, display_name = ?, tier = ?, notes = ?, is_active = ?
                 WHERE id = ?'
            )->execute([
                $code,
                $display,
                $tier,
                $notes !== '' ? $notes : null,
                $active ? 1 : 0,
                $partnerId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $emailSent = false;
        $emailError = null;
        $shouldEmail = !empty($data['send_email']) && $plain !== '';
        if ($shouldEmail) {
            $mail = $this->sendAccessEmail([
                'email' => $email,
                'first_name' => $first,
                'last_name_p' => $lastP,
                'display_name' => $display,
                'code' => $code,
                'tier' => $tier,
            ], $plain, false);
            $emailSent = $mail['ok'];
            $emailError = $mail['error'];
        }

        return [
            'plain_password' => $plain !== '' ? $plain : null,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ];
    }

    /**
     * @param array{
     *   email:string,first_name:string,last_name_p:string,display_name:string,code:string,tier:string
     * } $partner
     * @return array{ok:bool,error:?string}
     */
    private function sendAccessEmail(array $partner, string $plainPassword, bool $isNew): array
    {
        try {
            $name = trim($partner['first_name'] . ' ' . $partner['last_name_p']);
            $loginUrl = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/') . '/login';
            $tierLabel = self::tierLabels()[$partner['tier']] ?? strtoupper($partner['tier']);
            $appName = (string) (Env::get('APP_NAME', 'Instituto DOCEO') ?? 'Instituto DOCEO');

            if ($isNew) {
                $subject = 'Acceso portal partner — ' . $appName;
                $intro = "Te creamos una cuenta de partner en {$appName}.";
            } else {
                $subject = 'Nueva contraseña portal partner — ' . $appName;
                $intro = "Actualizamos el acceso de tu cuenta partner en {$appName}.";
            }

            $text = "Hola {$name},\n\n"
                . "{$intro}\n\n"
                . "Partner: {$partner['display_name']} ({$partner['code']})\n"
                . "Nivel de precio: {$tierLabel}\n\n"
                . "Inicia sesión aquí: {$loginUrl}\n"
                . "Usuario (correo): {$partner['email']}\n"
                . "Contraseña temporal: {$plainPassword}\n\n"
                . "Te recomendamos cambiar la contraseña al entrar.\n\n"
                . "— {$appName}\n";

            $inner = '<p>Hola ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Partner:</strong> ' . htmlspecialchars($partner['display_name'], ENT_QUOTES, 'UTF-8')
                . ' (' . htmlspecialchars($partner['code'], ENT_QUOTES, 'UTF-8') . ')<br>'
                . '<strong>Nivel de precio:</strong> ' . htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Usuario:</strong> ' . htmlspecialchars($partner['email'], ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Contraseña temporal:</strong> ' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#315285;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;">Iniciar sesión</a></p>'
                . '<p style="font-size:13px;color:#667;">Te recomendamos cambiar la contraseña al entrar.</p>';

            (new Mailer())->send(
                $partner['email'],
                $subject,
                $text,
                ['html' => true, 'body_html' => MailBranding::wrap($inner)]
            );

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            error_log('[Doceo] Partner access email: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Genera nueva contraseña temporal y la envía por correo (para partners ya creados).
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

        $mail = $this->sendAccessEmail([
            'email' => (string) $partner['email'],
            'first_name' => (string) $partner['first_name'],
            'last_name_p' => (string) $partner['last_name_p'],
            'display_name' => (string) $partner['display_name'],
            'code' => (string) $partner['code'],
            'tier' => (string) $partner['tier'],
        ], $plain, false);

        return [
            'plain_password' => $plain,
            'email_sent' => $mail['ok'],
            'email_error' => $mail['error'],
        ];
    }
}
