<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Env;

final class MoodleClient
{
    public function call(string $function, array $params = []): array
    {
        $url = Env::require('MOODLE_URL');
        $token = Env::require('MOODLE_TOKEN');

        $payload = array_merge($params, [
            'wstoken' => $token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json',
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('No se pudo iniciar cURL para Moodle.');
        }

        // Encoding RFC1738 + arrays anidados (users[0][username]=…) — formato nativo REST de Moodle.
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC1738);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL Moodle: {$error}");
        }

        if ($raw === false || trim((string) $raw) === '') {
            throw new \RuntimeException("Respuesta vacía de Moodle (HTTP {$status}).");
        }

        $rawTrim = trim((string) $raw);
        $decoded = json_decode($rawTrim, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $snippet = mb_substr(preg_replace('/\s+/', ' ', $rawTrim) ?? $rawTrim, 0, 180);
            throw new \RuntimeException(
                'Moodle no devolvió JSON válido (HTTP ' . $status . '): ' . $snippet
            );
        }

        // Varias WS (p. ej. enrol_manual_enrol_users) responden JSON null = éxito.
        if ($decoded === null) {
            return [];
        }

        if (!is_array($decoded)) {
            // Escalares raros: tratar como éxito vacío si no es estructura de error.
            return [];
        }

        if (isset($decoded['exception'])) {
            $message = (string) ($decoded['message'] ?? $decoded['exception']);
            $code = (string) ($decoded['errorcode'] ?? 'moodle_error');
            $debug = trim((string) ($decoded['debuginfo'] ?? ''));
            if ($debug !== '' && !str_contains($message, $debug)) {
                $message .= ' (' . $debug . ')';
            }
            $hint = self::hintForError($function, $code, $message . ' ' . $debug);
            throw new \RuntimeException("Moodle [{$code}] en {$function}: {$message}" . ($hint !== '' ? ' — ' . $hint : ''));
        }

        return $decoded;
    }

    /** Mensaje accionable para errores frecuentes del webservice Moodle. */
    private static function hintForError(string $function, string $code, string $message): string
    {
        $code = strtolower($code);
        $hay = strtolower($function . ' ' . $code . ' ' . $message);
        if ($code === 'accessexception' || str_contains($hay, 'control de acceso') || str_contains($hay, 'access control')) {
            return 'El token no puede ejecutar esta función. En Moodle → Administración del sitio → Servidor → Servicios web: '
                . '(1) agrega "' . $function . '" al servicio externo del PDV, '
                . '(2) el usuario del token necesita capacidades: webservice/rest:use, moodle/user:viewdetails, moodle/user:create, '
                . 'moodle/course:view, enrol/manual:enrol (contexto sistema o curso), '
                . '(3) el usuario debe estar autorizado en el servicio. Luego usa “Sincronizar Moodle” en el caso.';
        }
        if ($code === 'invalidtoken' || str_contains($hay, 'invalid token')) {
            return 'Revisa MOODLE_TOKEN en .env (token del servicio externo, no la clave de login).';
        }
        if ($code === 'invalidparameter' || str_contains($hay, 'parámetro inválido') || str_contains($hay, 'invalid parameter')) {
            if (str_contains($hay, 'password') || str_contains($hay, 'contraseña')) {
                return 'La contraseña no cumple la política de Moodle (Administración → Seguridad → Políticas del sitio).';
            }
            if (str_contains($hay, 'username') || str_contains($hay, 'usuario')) {
                return 'El nombre de usuario no es válido para Moodle (solo a-z, 0-9, .-_).';
            }
            if (str_contains($hay, 'lang') || str_contains($hay, 'language')) {
                return 'Idioma no instalado en Moodle; el PDV ya omite lang por defecto (MOODLE_USER_LANG).';
            }
            if (str_contains($hay, 'profile') || str_contains($hay, 'custom')) {
                return 'Hay campos de perfil obligatorios en Moodle; hazlos opcionales o rellena defaults.';
            }

            return 'Revisa política de contraseñas, username, e-mail y campos de perfil obligatorios. '
                . 'El detalle suele venir en debuginfo del error.';
        }
        if (str_contains($hay, 'password')) {
            return 'La contraseña generada no cumple la política de Moodle; ajusta la política o el generador del PDV.';
        }

        return '';
    }

    /**
     * Prueba las funciones que el PDV necesita sin crear usuarios reales.
     * Usa core_webservice_get_site_info (lista de funciones del token) + get_users / get_courses.
     *
     * @return array<string, array{ok:bool,error?:string,detail?:mixed}>
     */
    public function probeRequiredFunctions(): array
    {
        $required = [
            'core_webservice_get_site_info',
            'core_course_get_courses',
            'core_user_get_users_by_field',
            'core_user_create_users',
            'enrol_manual_enrol_users',
        ];
        $out = [];

        $site = null;
        try {
            $site = $this->call('core_webservice_get_site_info');
            $out['core_webservice_get_site_info'] = [
                'ok' => true,
                'detail' => [
                    'sitename' => $site['sitename'] ?? null,
                    'username' => $site['username'] ?? null,
                    'userid' => $site['userid'] ?? null,
                    'release' => $site['release'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            $out['core_webservice_get_site_info'] = ['ok' => false, 'error' => $e->getMessage()];
            foreach ($required as $fn) {
                if (!isset($out[$fn])) {
                    $out[$fn] = ['ok' => false, 'error' => 'Sin site_info; no se pudo listar funciones del token.'];
                }
            }

            return $out;
        }

        $allowed = [];
        foreach ($site['functions'] ?? [] as $fnRow) {
            if (is_array($fnRow) && !empty($fnRow['name'])) {
                $allowed[(string) $fnRow['name']] = true;
            } elseif (is_string($fnRow)) {
                $allowed[$fnRow] = true;
            }
        }

        foreach (['core_course_get_courses', 'core_user_get_users_by_field', 'core_user_create_users', 'enrol_manual_enrol_users'] as $fn) {
            if ($allowed !== [] && empty($allowed[$fn])) {
                $out[$fn] = [
                    'ok' => false,
                    'error' => 'No está en el servicio externo del token. Agrégala en Moodle → Servicios web → Funciones.',
                ];
            }
        }

        if (!isset($out['core_course_get_courses'])) {
            try {
                $n = count($this->getCourses());
                $out['core_course_get_courses'] = ['ok' => true, 'detail' => ['courses' => $n]];
            } catch (\Throwable $e) {
                $out['core_course_get_courses'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        if (!isset($out['core_user_get_users_by_field'])) {
            try {
                // Búsqueda inocua: no falla si no hay resultados
                $this->getUsersByField('email', ['nobody-pdv-probe@institutodoceo.com']);
                $out['core_user_get_users_by_field'] = ['ok' => true];
            } catch (\Throwable $e) {
                $out['core_user_get_users_by_field'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // create + enrol: sin ejecutar (evitar usuarios basura). Si están en la lista del token, OK.
        if (!isset($out['core_user_create_users'])) {
            $out['core_user_create_users'] = !empty($allowed['core_user_create_users'])
                ? ['ok' => true]
                : [
                    'ok' => false,
                    'error' => 'No está en el servicio externo del token (necesaria para crear alumnos).',
                ];
        }
        if (!isset($out['enrol_manual_enrol_users'])) {
            $out['enrol_manual_enrol_users'] = !empty($allowed['enrol_manual_enrol_users'])
                ? ['ok' => true]
                : [
                    'ok' => false,
                    'error' => 'No está en el servicio externo del token (necesaria para matricular).',
                ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function getCourses(): array
    {
        $result = $this->call('core_course_get_courses');
        return array_values($result);
    }

    /** @return list<array<string, mixed>> */
    public function getUsersByField(string $field, array $values): array
    {
        $params = ['field' => $field];
        foreach (array_values($values) as $i => $value) {
            $params['values[' . $i . ']'] = (string) $value;
        }
        $result = $this->call('core_user_get_users_by_field', $params);

        return array_values(is_array($result) ? $result : []);
    }

    public function findUserByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $users = $this->getUsersByField('email', [$email]);
        foreach ($users as $user) {
            if (is_array($user) && !empty($user['id'])) {
                return $user;
            }
        }

        return null;
    }

    public function findUserByUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return null;
        }
        $users = $this->getUsersByField('username', [$username]);
        foreach ($users as $user) {
            if (is_array($user) && !empty($user['id'])) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param array{
     *   username?:string,
     *   password?:string,
     *   firstname?:string,
     *   lastname?:string,
     *   email?:string,
     *   force_password_change?:bool
     * } $data
     * @return array{id:int,username:string,password:?string,created:bool}
     */
    public function createUser(array $data): array
    {
        $username = self::sanitizeUsername((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = self::defaultPassword();
        }
        $firstname = self::sanitizePersonName((string) ($data['firstname'] ?? 'Alumno'), 'Alumno');
        $lastname = self::sanitizePersonName((string) ($data['lastname'] ?? 'Doceo'), 'Doceo');
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $forceChange = !empty($data['force_password_change']);

        if ($username === '' || $email === '' || $password === '') {
            throw new \InvalidArgumentException('username, email y password son obligatorios para crear usuario Moodle.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('e-mail inválido para Moodle: ' . $email);
        }

        $langEnv = trim((string) (Env::get('MOODLE_USER_LANG', '') ?? ''));
        $langs = array_values(array_unique(array_filter([
            $langEnv !== '' ? $langEnv : null,
            'en',
            'es',
            'es_mx',
        ])));

        $prefs = $forceChange
            ? [
                ['type' => 'auth_forcepasswordchange', 'value' => '1'],
            ]
            : [];

        /** @var list<array<string, mixed>> $userVariants */
        $userVariants = [];
        foreach ($langs as $lang) {
            $row = [
                'username' => $username,
                'password' => $password,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'auth' => 'manual',
                'lang' => $lang,
            ];
            if ($prefs !== []) {
                $row['preferences'] = $prefs;
            }
            $userVariants[] = $row;
        }
        $noLang = [
            'username' => $username,
            'password' => $password,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'auth' => 'manual',
        ];
        if ($prefs !== []) {
            $noLang['preferences'] = $prefs;
        }
        $userVariants[] = $noLang;

        $ascii = [
            'username' => $username,
            'password' => $password,
            'firstname' => self::toAsciiName($firstname, 'Alumno'),
            'lastname' => self::toAsciiName($lastname, 'Doceo'),
            'email' => $email,
            'auth' => 'manual',
            'lang' => 'en',
        ];
        if ($prefs !== []) {
            $ascii['preferences'] = $prefs;
        }
        $userVariants[] = $ascii;

        $errors = [];
        foreach ($userVariants as $idx => $user) {
            try {
                $result = $this->call('core_user_create_users', ['users' => [$user]]);
                $created = is_array($result[0] ?? null) ? $result[0] : null;
                if (!$created || empty($created['id'])) {
                    throw new \RuntimeException('Moodle no devolvió el ID del usuario creado.');
                }

                return [
                    'id' => (int) $created['id'],
                    'username' => (string) ($created['username'] ?? $username),
                    'password' => $password,
                    'created' => true,
                ];
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = 'intento ' . ($idx + 1) . ': ' . $msg;
                if (!str_contains(strtolower($msg), 'invalidparameter')) {
                    throw $e;
                }
            }
        }

        // Último recurso: Moodle genera clave y luego la fijamos a la estándar.
        try {
            $boot = [
                'username' => $username,
                'firstname' => self::toAsciiName($firstname, 'Alumno'),
                'lastname' => self::toAsciiName($lastname, 'Doceo'),
                'email' => $email,
                'auth' => 'manual',
                'lang' => 'en',
                'createpassword' => 1,
            ];
            if ($prefs !== []) {
                $boot['preferences'] = $prefs;
            }
            $result = $this->call('core_user_create_users', ['users' => [$boot]]);
            $created = is_array($result[0] ?? null) ? $result[0] : null;
            if (!$created || empty($created['id'])) {
                throw new \RuntimeException('Moodle no devolvió el ID del usuario creado.');
            }
            $id = (int) $created['id'];
            $this->updateUserPassword($id, $password, $forceChange);

            return [
                'id' => $id,
                'username' => (string) ($created['username'] ?? $username),
                'password' => $password,
                'created' => true,
            ];
        } catch (\Throwable $e) {
            $errors[] = 'createpassword: ' . $e->getMessage();
        }

        throw new \RuntimeException(
            'No se pudo crear el usuario Moodle (invalidparameter). '
            . 'Revisa política de contraseñas (debe aceptar Doceo*1234), idioma y campos de perfil. '
            . 'Detalle: ' . implode(' | ', array_slice($errors, 0, 3))
        );
    }

    public function updateUserPassword(int $userId, string $password, bool $forceChange = true): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('userId Moodle inválido.');
        }
        $password = trim($password);
        if ($password === '') {
            $password = self::defaultPassword();
        }
        $user = [
            'id' => $userId,
            'password' => $password,
        ];
        if ($forceChange) {
            $user['preferences'] = [
                ['type' => 'auth_forcepasswordchange', 'value' => '1'],
            ];
        }
        $this->call('core_user_update_users', ['users' => [$user]]);
    }

    public static function defaultPassword(): string
    {
        $fromEnv = trim((string) (Env::get('MOODLE_DEFAULT_PASSWORD', '') ?? ''));

        return $fromEnv !== '' ? $fromEnv : 'Doceo*1234';
    }

    /** Username Moodle: por defecto solo a-z 0-9 _ (compatible sin “usernames extendidos”). */
    public static function sanitizeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        // Puntos/guiones → guion bajo (si el sitio no tiene usernames extendidos, . y - invalidan el parámetro)
        $username = str_replace(['.', '-'], '_', $username);
        $username = preg_replace('/[^a-z0-9_]+/', '', $username) ?? '';
        $username = trim($username, '_');
        $username = preg_replace('/_+/', '_', $username) ?? $username;
        if (strlen($username) > 90) {
            $username = substr($username, 0, 90);
        }
        if (strlen($username) < 2) {
            $username = 'alumno' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        return $username;
    }

    private static function sanitizePersonName(string $name, string $fallback): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        // Quita controles; deja letras/acentos/espacios/guiones comunes en MX
        $name = preg_replace('/[^\p{L}\p{N} .\'\-]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = $fallback;
        }

        return mb_substr($name, 0, 100);
    }

    private static function toAsciiName(string $name, string $fallback): string
    {
        $name = self::sanitizePersonName($name, $fallback);
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($trans) && trim($trans) !== '') {
            $name = $trans;
        }
        $name = preg_replace('/[^a-zA-Z0-9 .\'\-]/', '', $name) ?? '';
        $name = trim($name);

        return $name !== '' ? mb_substr($name, 0, 100) : $fallback;
    }

    /** @deprecated Usar defaultPassword() */
    public static function strongPassword(): string
    {
        return self::defaultPassword();
    }

    public function enrolUser(
        int $userId,
        int $courseId,
        int $roleId = 5,
        ?int $timestart = null,
        ?int $timeend = null,
        int $suspend = 0
    ): void {
        if ($userId < 1 || $courseId < 1) {
            throw new \InvalidArgumentException('userId y courseId Moodle inválidos.');
        }
        $enrolment = [
            'roleid' => $roleId,
            'userid' => $userId,
            'courseid' => $courseId,
            'suspend' => $suspend > 0 ? 1 : 0,
        ];
        if ($timestart !== null && $timestart > 0) {
            $enrolment['timestart'] = $timestart;
        }
        if ($timeend !== null && $timeend > 0) {
            $enrolment['timeend'] = $timeend;
        }
        $this->call('enrol_manual_enrol_users', ['enrolments' => [$enrolment]]);
    }

    /** Suspende (1) o reactiva (0) la matrícula manual en un curso. */
    public function setEnrolmentSuspended(int $userId, int $courseId, int $suspend = 1, int $roleId = 5): void
    {
        $this->enrolUser($userId, $courseId, $roleId, null, null, $suspend > 0 ? 1 : 0);
    }
}
