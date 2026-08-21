<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Integrations\Mailer;
use App\Integrations\MoodleClient;
use App\Support\Settings;
use PDO;

/**
 * Alta / matrícula Moodle para trackings de cursos (platform_type=moodle).
 */
final class MoodleEnrolmentService
{
    private PDO $pdo;
    private MoodleClient $moodle;

    public function __construct(?MoodleClient $moodle = null)
    {
        $this->pdo = Connection::get();
        $this->moodle = $moodle ?? new MoodleClient();
    }

    public static function isConfigured(): bool
    {
        $url = trim((string) (Env::get('MOODLE_URL', '') ?? ''));
        $token = trim((string) (Env::get('MOODLE_TOKEN', '') ?? ''));

        return $url !== '' && $token !== '';
    }

    /**
     * @return array{
     *   ok:bool,
     *   skipped?:bool,
     *   reason?:string,
     *   username?:string,
     *   password?:?string,
     *   created_user?:bool,
     *   course_id?:int,
     *   access_starts_at?:string,
     *   access_ends_at?:string
     * }
     */
    public function syncTracking(int $trackingId, ?int $actorUserId = null, bool $sendEmail = true): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Moodle no configurado (MOODLE_URL / MOODLE_TOKEN).'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pr.platform_type,
                    pr.moodle_course_id, pr.access_months,
                    pu.matricula,
                    u.email, u.first_name, u.last_name_p, u.last_name_m, u.phone
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             JOIN users u ON u.id = t.student_user_id
             WHERE t.id = ?
             LIMIT 1'
        );
        $stmt->execute([$trackingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        if ((string) ($row['platform_type'] ?? '') !== 'moodle') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'Producto sin platform_type=moodle.'];
        }

        $courseId = (int) ($row['moodle_course_id'] ?? 0);
        if ($courseId < 1) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Falta moodle_course_id en el producto.'];
        }

        $email = strtolower(trim((string) $row['email']));
        $firstname = trim((string) $row['first_name']);
        $lastname = trim((string) (($row['last_name_p'] ?? '') . ' ' . ($row['last_name_m'] ?? '')));
        $password = Settings::defaultStudentPassword();
        $usernameBase = MoodleClient::sanitizeUsername(
            explode('@', $email)[0] !== '' ? explode('@', $email)[0] : ('alumno' . $trackingId)
        );

        $existing = $this->moodle->findUserByEmail($email);
        $createdUser = false;
        $plainPassword = null;

        if ($existing !== null) {
            $moodleUserId = (int) $existing['id'];
            $username = (string) ($existing['username'] ?? $usernameBase);
            // Restablece a la clave estándar para que el alumno pueda entrar
            try {
                $this->moodle->updateUserPassword($moodleUserId, $password, true);
                $plainPassword = $password;
            } catch (\Throwable $e) {
                error_log('[Doceo] Moodle reset password: ' . $e->getMessage());
                // Si ya tenía clave en el tracking, la reutilizamos en el correo
                $plainPassword = !empty($row['moodle_password']) ? (string) $row['moodle_password'] : null;
            }
        } else {
            $username = $this->uniqueUsername($usernameBase);
            $created = $this->moodle->createUser([
                'username' => $username,
                'password' => $password,
                'firstname' => $firstname !== '' ? $firstname : 'Alumno',
                'lastname' => $lastname !== '' ? $lastname : 'DOCEO',
                'email' => $email,
                'force_password_change' => true,
            ]);
            $moodleUserId = (int) $created['id'];
            $username = (string) $created['username'];
            $plainPassword = (string) ($created['password'] ?? $password);
            $createdUser = true;
        }

        $months = (int) ($row['access_months'] ?? 6);
        if ($months < 1) {
            $months = 6;
        }
        $start = time();
        $end = strtotime('+' . $months . ' months', $start) ?: ($start + $months * 30 * 86400);

        $this->moodle->enrolUser($moodleUserId, $courseId, 5, $start, $end, 0);

        $startsAt = date('Y-m-d H:i:s', $start);
        $endsAt = date('Y-m-d H:i:s', $end);

        $this->pdo->prepare(
            'UPDATE trackings
             SET moodle_username = ?, moodle_password = COALESCE(?, moodle_password),
                 moodle_access_starts_at = ?, moodle_access_ends_at = ?
             WHERE id = ?'
        )->execute([
            $username,
            $plainPassword,
            $startsAt,
            $endsAt,
            $trackingId,
        ]);

        $this->pdo->prepare(
            'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id)
             VALUES (?,?,?,?)'
        )->execute([
            $trackingId,
            'alta_moodle',
            ($createdUser ? 'Usuario Moodle creado' : 'Usuario Moodle existente')
                . ' · ' . $username . ' · curso ' . $courseId
                . ' · acceso hasta ' . $endsAt,
            $actorUserId,
        ]);

        // Avanza a "activo" si el pipeline lo tiene
        try {
            $trackSvc = new TrackingService();
            $trackSvc->setStep($trackingId, 'activo', $actorUserId, 'Acceso Moodle activo', 'waiting_student');
        } catch (\Throwable $e) {
            error_log('[Doceo] Moodle step activo: ' . $e->getMessage());
        }

        if ($sendEmail) {
            $this->sendAccessEmail($row, $username, $plainPassword ?? $password, $startsAt, $endsAt);
        }

        return [
            'ok' => true,
            'username' => $username,
            'password' => $plainPassword,
            'created_user' => $createdUser,
            'course_id' => $courseId,
            'access_starts_at' => $startsAt,
            'access_ends_at' => $endsAt,
        ];
    }

    private function uniqueUsername(string $base): string
    {
        $candidate = $base;
        for ($i = 0; $i < 8; $i++) {
            $found = $this->moodle->findUserByUsername($candidate);
            if ($found === null) {
                return $candidate;
            }
            $candidate = MoodleClient::sanitizeUsername($base . ($i + 1));
        }

        return MoodleClient::sanitizeUsername($base . substr(bin2hex(random_bytes(2)), 0, 4));
    }

    /** @param array<string, mixed> $row */
    private function sendAccessEmail(
        array $row,
        string $username,
        string $password,
        string $startsAt,
        string $endsAt
    ): void {
        try {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''));
            $campus = 'https://campus.institutodoceo.com';
            $moodleUrl = (string) (Env::get('MOODLE_URL', '') ?? '');
            if (preg_match('#^(https?://[^/]+)#', $moodleUrl, $m)) {
                $campus = $m[1];
            }
            $product = (string) ($row['product_name'] ?? 'curso');
            $text = "Hola {$name},\n\nYa tienes acceso a {$product} en Campus DOCEO.\n\n"
                . "URL: {$campus}\n"
                . "Usuario: {$username}\n"
                . "Contraseña: {$password}\n"
                . "Acceso: {$startsAt} → {$endsAt}\n\n"
                . "Te pedirá cambiar la contraseña al entrar.\n\n— Instituto DOCEO\n";
            $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
                . '<p>Ya tienes acceso a <strong>' . htmlspecialchars($product) . '</strong> en Campus DOCEO.</p>'
                . '<p><a href="' . htmlspecialchars($campus) . '">' . htmlspecialchars($campus) . '</a><br>'
                . '<strong>Usuario:</strong> ' . htmlspecialchars($username) . '<br>'
                . '<strong>Contraseña:</strong> ' . htmlspecialchars($password) . '<br>'
                . '<strong>Vigencia:</strong> ' . htmlspecialchars($startsAt) . ' → ' . htmlspecialchars($endsAt) . '</p>'
                . '<p>Te pedirá cambiar la contraseña al entrar.</p><p>— Instituto DOCEO</p>';

            (new Mailer())->send(
                (string) $row['email'],
                'Acceso Campus DOCEO — ' . ($row['matricula'] ?? ''),
                $text,
                ['html' => true, 'body_html' => $html]
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] Moodle access email: ' . $e->getMessage());
        }
    }
}
