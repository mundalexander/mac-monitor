<?php
/**
 * auth-v2.php — Auth-Kern: PIN (PBKDF2) + Passkey (WebAuthn, rein PHP).
 *
 * Konfiguration (secrets.php, nicht committet):
 *   define('LOGIN_PIN_HASH', 'pbkdf2$sha256$150000$<saltHex>$<hashHex>');
 *     → erzeugt mit: php tools/gen-pin.php 1234
 *   define('WEBAUTHN_RP_ID', 'mund.bplaced.net');   // optional, Default: Host
 *   define('WEBAUTHN_RP_NAME', 'mac-monitor');      // optional
 *
 * Passkey-Credentials: webauthn-credentials.json im Server-Verzeichnis
 * (per .htaccess gesperrt). Registration erfordert eine bestehende
 * Session (PIN- oder Token-Login) — Passkeys kann nur anmelden, wer
 * sich einmal mit PIN/Token ausgewiesen hat.
 *
 * Seit 1.2.0: KEIN Token-Login mehr. Browser melden sich mit PIN oder
 * Passkey an; Maschinen/Agenten nutzen Geräteschlüssel (siehe auth.php).
 *
 * WebAuthn-Implementierung: "none"-Attestation voll geprüft;
 * packed/fido-u2f werden akzeptiert, aber ohne Attestationsketten-
 * prüfung (Single-User-Setup, Credential wird beim Register-Request
 * des authentifizierten Besitzers gesetzt — vertretbar).
 * Algorithmen: ES256 (Plattform/Fingerabdruck-Standard), ES384, RS256.
 */

if (is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}

// ════════════════════════════════════════════════════════════════════
// Konfiguration
// ════════════════════════════════════════════════════════════════════
function mm_auth_config(): array {
    return [
        'pin_hash'  => defined('LOGIN_PIN_HASH') ? trim((string)constant('LOGIN_PIN_HASH')) : '',
        'rp_id'     => defined('WEBAUTHN_RP_ID') && trim((string)constant('WEBAUTHN_RP_ID')) !== ''
                        ? trim((string)constant('WEBAUTHN_RP_ID'))
                        : (string)($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'rp_name'   => defined('WEBAUTHN_RP_NAME') ? (string)constant('WEBAUTHN_RP_NAME') : 'mac-monitor',
        'cred_file' => defined('WEBAUTHN_CRED_FILE')
                        ? (string)constant('WEBAUTHN_CRED_FILE')
                        : __DIR__ . '/webauthn-credentials.json',
    ];
}

// ════════════════════════════════════════════════════════════════════
// PIN (PBKDF2-SHA256)
// ════════════════════════════════════════════════════════════════════
/** Hash-String fuer secrets.php erzeugen (CLI-Tool nutzt das hier). */
function mm_pin_hash(string $pin, int $iterations = 150000): string {
    $salt = random_bytes(16);
    return 'pbkdf2$sha256$' . $iterations . '$' . bin2hex($salt) . '$'
        . bin2hex(hash_pbkdf2('sha256', $pin, $salt, $iterations, 32, true));
}

function mm_pin_verify(string $pin, string $stored): bool {
    $p = explode('$', $stored);
    if (count($p) !== 5 || $p[0] !== 'pbkdf2' || $p[1] !== 'sha256') return false;
    $iter = (int)$p[2];
    $salt = @hex2bin($p[3]);
    $want = @hex2bin($p[4]);
    if ($iter < 1000 || $salt === false || $want === false || $want === '') return false;
    $got = hash_pbkdf2('sha256', $pin, $salt, $iter, strlen($want), true);
    return hash_equals($want, $got);
}

/**
 * Login-Versuch pruefen: ausschließlich PIN (4-8 Ziffern).
 * @return ?string  'pin' bei Erfolg, null bei Ablehnung.
 */
function mm_login_check(string $given): ?string {
    $given = trim($given);
    if ($given === '') return null;
    $cfg = mm_auth_config();
    if ($cfg['pin_hash'] !== '' && preg_match('/^\d{4,8}$/', $given) === 1
        && mm_pin_verify($given, $cfg['pin_hash'])) {
        return 'pin';
    }
    return null;
}

// ════════════════════════════════════════════════════════════════════
// Session + Brute-Force-Bremse
// ════════════════════════════════════════════════════════════════════
function mm_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('MACMON');
        @session_set_cookie_params([
            'lifetime' => 0,            // Session-Cookie; Dauer regelt "Gerät merken"
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
}

function mm_grant_session(string $method): void {
    session_regenerate_id(true);
    $_SESSION['planner_ok'] = true;
    $_SESSION['mm_auth']    = $method; // 'pin' | 'passkey' | 'remember'
    unset($_SESSION['mm_fails'], $_SESSION['mm_lock_until']);
    if (function_exists('mm_ip_clear')) mm_ip_clear();
}

// "Gerät merken" ist seit 1.2.0 in auth.php (DB-basiert, widerrufbar).

/** true = Login-Versuch erlaubt; false = temporär gesperrt. */
function mm_login_allowed(): bool {
    // IP-basierte Sperre (greift auch ohne Session-Cookie, z. B. curl)
    if (function_exists('mm_ip_allowed') && !mm_ip_allowed()) return false;
    $until = (int)($_SESSION['mm_lock_until'] ?? 0);
    return $until === 0 || time() >= $until;
}

function mm_login_fail(): void {
    if (function_exists('mm_ip_fail')) mm_ip_fail();
    $n = (int)($_SESSION['mm_fails'] ?? 0) + 1;
    $_SESSION['mm_fails'] = $n;
    if ($n >= 5) {
        // mit jeder weiteren Fehlquote länger: 60s, 120s, 180s ...
        $_SESSION['mm_lock_until'] = time() + 60 * ($n - 4);
    }
}

// ════════════════════════════════════════════════════════════════════
// Base64url
// ════════════════════════════════════════════════════════════════════
function mm_b64url(string $d): string {
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function mm_b64url_dec(string $s): ?string {
    $s = preg_replace('/[^A-Za-z0-9\-_]/', '', $s) ?? '';
    $d = base64_decode(strtr($s, '-_', '+/'), true);
    return $d === false ? null : $d;
}

// ════════════════════════════════════════════════════════════════════
// Minimaler CBOR-Decoder (RFC 8949, Subset ohne indefinite Längen)
// ════════════════════════════════════════════════════════════════════
function mm_cbor_decode(string $s, int &$o) {
    if ($o >= strlen($s)) throw new RuntimeException('CBOR: unerwartendes Ende');
    $b  = ord($s[$o++]);
    $mt = $b >> 5;
    $ai = $b & 31;
    $val = null;
    if ($ai < 24)      { $val = $ai; }
    elseif ($ai === 24){ if ($o >= strlen($s)) throw new RuntimeException('CBOR: Ende'); $val = ord($s[$o++]); }
    elseif ($ai === 25){ $v = unpack('n', substr($s, $o, 2)); if (!$v) throw new RuntimeException('CBOR: Ende'); $val = $v[1]; $o += 2; }
    elseif ($ai === 26){ $v = unpack('N', substr($s, $o, 4)); if (!$v) throw new RuntimeException('CBOR: Ende'); $val = $v[1]; $o += 4; }
    elseif ($ai === 27){ $v = unpack('J', substr($s, $o, 8)); if (!$v) throw new RuntimeException('CBOR: Ende'); $val = $v[1]; $o += 8; }
    elseif ($ai === 31){ throw new RuntimeException('CBOR: indefinite Länge nicht unterstützt'); }
    else               { throw new RuntimeException('CBOR: reserviertes Argument'); }

    switch ($mt) {
        case 0: return (int)$val;
        case 1: return -1 - (int)$val;
        case 2:
        case 3: {
            $len = (int)$val;
            if ($o + $len > strlen($s)) throw new RuntimeException('CBOR: String überläuft');
            $out = substr($s, $o, $len); $o += $len;
            return $out;
        }
        case 4: {
            $arr = [];
            for ($i = 0; $i < (int)$val; $i++) $arr[] = mm_cbor_decode($s, $o);
            return $arr;
        }
        case 5: {
            $map = [];
            for ($i = 0; $i < (int)$val; $i++) {
                $k = mm_cbor_decode($s, $o);
                $map[is_int($k) ? $k : (string)$k] = mm_cbor_decode($s, $o);
            }
            return $map;
        }
        case 6: // Tag: Inhalt direkt weiter decoden
            return mm_cbor_decode($s, $o);
        case 7:
            if ($ai === 20) return false;
            if ($ai === 21) return true;
            if ($ai === 22) return null;
            if ($ai === 25) { // half float
                $h = (int)$val;
                $sign = ($h & 0x8000) ? -1.0 : 1.0;
                $exp  = ($h >> 10) & 31;
                $man  = $h & 0x3FF;
                if ($exp === 0)   return $sign * $man * 2 ** -24;
                if ($exp === 31)  return $man ? NAN : INF;
                return $sign * (1 + $man / 1024) * (2 ** ($exp - 15));
            }
            if ($ai === 26) { // single float
                $v = unpack('G', pack('N', (int)$val));
                return $v ? $v[1] : 0.0;
            }
            if ($ai === 27) { // double
                $v = unpack('E', pack('J', (int)$val));
                return $v ? $v[1] : 0.0;
            }
            throw new RuntimeException('CBOR: Simple-Type nicht unterstützt');
    }
    throw new RuntimeException('CBOR: Major-Type nicht unterstützt');
}

// ════════════════════════════════════════════════════════════════════
// DER/PEM-Baukasten (COSE → Public-Key)
// ════════════════════════════════════════════════════════════════════
function mm_der_len(int $n): string {
    if ($n < 0x80)  return chr($n);
    if ($n < 0x100) return "\x81" . chr($n);
    return "\x82" . pack('n', $n);
}
function mm_der_tlv(int $tag, string $content): string {
    return chr($tag) . mm_der_len(strlen($content)) . $content;
}
function mm_der_seq(string $c): string { return mm_der_tlv(0x30, $c); }
function mm_der_oid(string $bytes): string { return mm_der_tlv(0x06, $bytes); }
function mm_der_uint(int $tag, string $be): string {
    $b = ltrim($be, "\x00");
    if ($b === '') $b = "\x00";
    if (ord($b[0]) & 0x80) $b = "\x00" . $b;
    return mm_der_tlv($tag, $b);
}
function mm_der_pem(string $der): string {
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** COSE-Key (Assoziativfeld aus CBOR) → PEM. Unterstützt EC2 P-256/P-384 und RSA. */
function mm_cose_to_pem(array $cose): ?string {
    $kty = $cose[1] ?? 0;
    if ($kty === 2) { // EC2
        $crv = $cose[-1] ?? 0;
        $x   = $cose[-2] ?? '';
        $y   = $cose[-3] ?? '';
        $oidEc = mm_der_oid("\x2a\x86\x48\xce\x3d\x02\x01"); // 1.2.840.10045.2.1 (ecPublicKey)
        if ($crv === 1 && strlen($x) === 32 && strlen($y) === 32) {
            $curveOid = mm_der_oid("\x2a\x86\x48\xce\x3d\x03\x01\x07"); // P-256
        } elseif ($crv === 2 && strlen($x) === 48 && strlen($y) === 48) {
            $curveOid = mm_der_oid("\x2b\x81\x04\x00\x22");             // P-384 (1.3.132.0.34)
        } else {
            return null;
        }
        $alg   = mm_der_seq($oidEc . $curveOid);
        $point = "\x04" . $x . $y;
        $bits  = "\x03" . mm_der_len(strlen($point) + 1) . "\x00" . $point;
        return mm_der_pem(mm_der_seq($alg . $bits));
    }
    if ($kty === 3) { // RSA
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if ($n === '' || $e === '') return null;
        $rsaPub = mm_der_seq(mm_der_uint(0x02, $n) . mm_der_uint(0x02, $e));
        $alg    = mm_der_seq(mm_der_oid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $bits   = "\x03" . mm_der_len(strlen($rsaPub) + 1) . "\x00" . $rsaPub;
        return mm_der_pem(mm_der_seq($alg . $bits));
    }
    return null;
}

/** ECDSA-Rohsignatur (r||s) → DER (für openssl_verify). */
function mm_ec_sig_raw_to_der(string $sig): string {
    $half = intdiv(strlen($sig), 2);
    $r = mm_der_uint(0x02, substr($sig, 0, $half));
    $s = mm_der_uint(0x02, substr($sig, $half));
    return mm_der_seq($r . $s);
}

// ════════════════════════════════════════════════════════════════════
// WebAuthn-Server
// ════════════════════════════════════════════════════════════════════
/** Label auf 40 Zeichen kürzen, UTF-8-sicher auch ohne mbstring. */
function mm_clip_label(string $s): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, 40);
    if (function_exists('iconv_strimwidth')) return iconv_strimwidth($s, 0, 40, '', 'UTF-8');
    return substr($s, 0, 40);
}

class MMWebAuthn {
    private const ALGOS = [
        -7   => OPENSSL_ALGO_SHA256, // ES256
        -35  => OPENSSL_ALGO_SHA384, // ES384
        -257 => OPENSSL_ALGO_SHA256, // RS256
    ];

    /** Erlaubte Origins: https auf rp_id, plus localhost für lokale Tests. */
    public static function origins(string $rpId): array {
        $list = ['https://' . $rpId];
        if ($rpId === 'localhost' || str_starts_with($rpId, 'localhost:')) {
            $list[] = 'http://' . $rpId;
        }
        return $list;
    }

    private static function checkClientData(array $cd, string $type, string $rpId, string $shortType): void {
        if (($cd['type'] ?? '') !== $type) {
            throw new RuntimeException('WebAuthn: falscher Typ');
        }
        $stored = $_SESSION['wa_ch'] ?? null;
        if (!is_array($stored) || ($stored['type'] ?? '') !== $shortType) {
            throw new RuntimeException('WebAuthn: keine Challenge aktiv');
        }
        if (time() - (int)$stored['time'] > 180) {
            unset($_SESSION['wa_ch']);
            throw new RuntimeException('WebAuthn: Challenge abgelaufen');
        }
        $given = mm_b64url_dec((string)($cd['challenge'] ?? ''));
        unset($_SESSION['wa_ch']); // Einmalig
        if ($given === null || !hash_equals($stored['challenge'], $given)) {
            throw new RuntimeException('WebAuthn: Challenge stimmt nicht');
        }
        if (!in_array((string)($cd['origin'] ?? ''), self::origins($rpId), true)) {
            throw new RuntimeException('WebAuthn: Origin nicht erlaubt');
        }
    }

    private static function newChallenge(string $type): string {
        $ch = random_bytes(32);
        $_SESSION['wa_ch'] = ['challenge' => $ch, 'time' => time(), 'type' => $type];
        return $ch;
    }

    public static function userId(): string {
        return 'macmon-owner';
    }

    // ── Registrierung ────────────────────────────────────────────────
    public static function registerBegin(array $cfg): array {
        $excluded = [];
        foreach (self::loadCreds($cfg) as $c) {
            $excluded[] = ['type' => 'public-key', 'id' => $c['id']];
        }
        return [
            'publicKey' => [
                'challenge'  => mm_b64url(self::newChallenge('create')),
                'rp'         => ['id' => $cfg['rp_id'], 'name' => $cfg['rp_name']],
                'user'       => [
                    'id'          => self::userId(),
                    'name'        => 'mac-monitor',
                    'displayName' => 'mac-monitor',
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],   // ES256 (Plattform)
                    ['type' => 'public-key', 'alg' => -257], // RS256 (Fallback)
                    ['type' => 'public-key', 'alg' => -35],  // ES384
                ],
                'timeout'    => 60000,
                'attestation'=> 'none',
                'authenticatorSelection' => [
                    'authenticatorAttachment' => 'platform',
                    'userVerification'        => 'required',
                    'residentKey'             => 'preferred',
                ],
                'excludeCredentials' => $excluded,
            ],
        ];
    }

    public static function registerFinish(array $cfg, string $clientDataB64, string $attObjB64, string $label): array {
        $cdRaw = mm_b64url_dec($clientDataB64);
        $attRaw = mm_b64url_dec($attObjB64);
        if ($cdRaw === null || $attRaw === null) throw new RuntimeException('WebAuthn: Base64 ungültig');
        $cd = json_decode($cdRaw, true);
        if (!is_array($cd)) throw new RuntimeException('WebAuthn: clientDataJSON ungültig');
        self::checkClientData($cd, 'webauthn.create', $cfg['rp_id'], 'create');

        $o = 0;
        $att = mm_cbor_decode($attRaw, $o);
        if (!is_array($att)) throw new RuntimeException('WebAuthn: attestationObject ungültig');
        $authData = (string)($att['authData'] ?? '');
        $fmt      = (string)($att['fmt'] ?? '');
        if ($fmt !== 'none' && $fmt !== 'packed' && $fmt !== 'fido-u2f') {
            throw new RuntimeException('WebAuthn: Attestationsformat nicht unterstützt');
        }
        // rpIdHash muss sha256(rp_id) sein
        if (substr($authData, 0, 32) !== hash('sha256', $cfg['rp_id'], true)) {
            throw new RuntimeException('WebAuthn: rpIdHash passt nicht');
        }
        $flags = ord($authData[32] ?? "\x00");
        if (!($flags & 0x01)) throw new RuntimeException('WebAuthn: UP-Flag fehlt');
        if (!($flags & 0x40)) throw new RuntimeException('WebAuthn: AT-Flag fehlt');

        $ad  = self::parseAuthData($authData);
        $pem = mm_cose_to_pem($ad['cose']);
        if ($pem === null) throw new RuntimeException('WebAuthn: COSE-Key nicht unterstützt');
        $alg = (int)($ad['cose'][3] ?? -7);
        if (!isset(self::ALGOS[$alg])) throw new RuntimeException('WebAuthn: Algorithmus nicht unterstützt');

        $creds = self::loadCreds($cfg);
        $id    = mm_b64url($ad['credId']);
        $creds = array_values(array_filter($creds, fn($c) => $c['id'] !== $id));
        $creds[] = [
            'id'        => $id,
            'type'      => 'public-key',
            'pem'       => $pem,
            'alg'       => $alg,
            'counter'   => $ad['counter'],
            'label'     => mm_clip_label(preg_replace('/[^\p{L}\p{N} \-_.\/]/u', '', $label) ?: 'Passkey'),
            'created'   => time(),
        ];
        self::saveCreds($cfg, $creds);
        return ['id' => $id, 'label' => end($creds)['label'] ?? 'Passkey'];
    }

    // ── Assertion (Login) ────────────────────────────────────────────
    public static function assertBegin(array $cfg): array {
        $allow = [];
        foreach (self::loadCreds($cfg) as $c) {
            $allow[] = ['type' => 'public-key', 'id' => $c['id']];
        }
        return [
            'publicKey' => [
                'challenge'      => mm_b64url(self::newChallenge('get')),
                'timeout'        => 60000,
                'rpId'           => $cfg['rp_id'],
                'allowCredentials' => $allow, // leer → Discoverable-Credentials
                'userVerification' => 'required',
            ],
        ];
    }

    public static function assertFinish(array $cfg, string $credIdB64, string $clientDataB64, string $authDataB64, string $sigB64): bool {
        $cdRaw    = mm_b64url_dec($clientDataB64);
        $authRaw  = mm_b64url_dec($authDataB64);
        $sig      = mm_b64url_dec($sigB64);
        if ($cdRaw === null || $authRaw === null || $sig === null) {
            throw new RuntimeException('WebAuthn: Base64 ungültig');
        }
        $cd = json_decode($cdRaw, true);
        if (!is_array($cd)) throw new RuntimeException('WebAuthn: clientDataJSON ungültig');
        self::checkClientData($cd, 'webauthn.get', $cfg['rp_id'], 'get');

        $creds = self::loadCreds($cfg);
        $cred  = null;
        foreach ($creds as $i => $c) {
            if (hash_equals($c['id'], $credIdB64)) { $cred = $c; break; }
        }
        if ($cred === null) throw new RuntimeException('WebAuthn: Credential unbekannt');

        if (substr($authRaw, 0, 32) !== hash('sha256', $cfg['rp_id'], true)) {
            throw new RuntimeException('WebAuthn: rpIdHash passt nicht');
        }
        $flags   = ord($authRaw[32] ?? "\x00");
        $counter = unpack('N', substr($authRaw, 33, 4))[1] ?? 0;
        if (!($flags & 0x01)) throw new RuntimeException('WebAuthn: UP-Flag fehlt');
        // Signatzzähler: erhöhen Pflicht, außer Gerät meldet beidseitig 0.
        if ($counter !== 0 || (int)$cred['counter'] !== 0) {
            if ($counter <= (int)$cred['counter']) {
                throw new RuntimeException('WebAuthn: Counter-Downgrade (möglicher Klon)');
            }
        }
        $alg = (int)$cred['alg'];
        if (!isset(self::ALGOS[$alg])) throw new RuntimeException('WebAuthn: Algorithmus nicht unterstützt');
        $signed = $authRaw . hash('sha256', $cdRaw, true);
        $sigDer = in_array($alg, [-7, -35], true) ? mm_ec_sig_raw_to_der($sig) : $sig;
        $key = openssl_pkey_get_public($cred['pem']);
        if ($key === false) throw new RuntimeException('WebAuthn: Public-Key lesbar?');
        $ok = openssl_verify($signed, $sigDer, $key, self::ALGOS[$alg]);
        if ($ok !== 1) throw new RuntimeException('WebAuthn: Signatur ungültig');

        // Counter zurückschreiben
        foreach ($creds as $i => $c) {
            if ($c['id'] === $cred['id']) { $creds[$i]['counter'] = $counter; break; }
        }
        self::saveCreds($cfg, $creds);
        return true;
    }

    // ── Credential-Store ─────────────────────────────────────────────
    public static function parseAuthData(string $ad): array {
        if (strlen($ad) < 55) throw new RuntimeException('WebAuthn: authData zu kurz');
        $clen = unpack('n', substr($ad, 53, 2))[1] ?? 0;
        if ($clen === 0 || $clen > 1024 || strlen($ad) < 55 + $clen) {
            throw new RuntimeException('WebAuthn: credId-Länge unplausibel');
        }
        $o = 55 + $clen;
        $cose = mm_cbor_decode($ad, $o);
        if (!is_array($cose)) throw new RuntimeException('WebAuthn: COSE-Key ungültig');
        return [
            'credId' => substr($ad, 55, $clen),
            'cose'   => $cose,
            'counter'=> unpack('N', substr($ad, 33, 4))[1] ?? 0,
        ];
    }

    public static function loadCreds(array $cfg): array {
        if (!is_file($cfg['cred_file'])) return [];
        $raw  = (string)file_get_contents($cfg['cred_file']);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function saveCreds(array $cfg, array $creds): void {
        $tmp = $cfg['cred_file'] . '.tmp';
        if (file_put_contents($tmp, json_encode($creds, JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('WebAuthn: Credential-Datei nicht schreibbar');
        }
        rename($tmp, $cfg['cred_file']);
        @chmod($cfg['cred_file'], 0600);
    }

    public static function deleteCred(array $cfg, string $id): bool {
        $creds = self::loadCreds($cfg);
        $kept  = array_values(array_filter($creds, fn($c) => $c['id'] !== $id));
        if (count($kept) === count($creds)) return false;
        self::saveCreds($cfg, $kept);
        return true;
    }
}
