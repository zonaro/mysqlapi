<?php
declare(strict_types=1);

/** Stateless connection tokens: ECDH P-256 + HKDF-SHA256 + AES-256-GCM. */

function mysqlapi_b64_decode(string $value): string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        throw new InvalidArgumentException('Invalid encoded value');
    }
    $raw = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    if ($raw === false) {
        throw new InvalidArgumentException('Invalid encoded value');
    }
    return $raw;
}

function mysqlapi_b64_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @return array{private: OpenSSLAsymmetricKey, publicKey: string, kid: string} */
function mysqlapi_server_key(): array
{
    $path = getenv('MYSQLAPI_PRIVATE_KEY_FILE');
    if (!$path || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Server encryption key is not configured');
    }
    $private = openssl_pkey_get_private(file_get_contents($path));
    if ($private === false) {
        throw new RuntimeException('Invalid server encryption key');
    }
    $details = openssl_pkey_get_details($private);
    if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
        throw new RuntimeException('Server key must use the P-256 curve');
    }
    $publicDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);
    if ($publicDer === false) {
        throw new RuntimeException('Invalid public key');
    }
    return ['private' => $private, 'publicKey' => mysqlapi_b64_encode($publicDer), 'kid' => substr(hash('sha256', $publicDer), 0, 16)];
}

/** @return array<string, mixed> */
function mysqlapi_decrypt_token(string $token, string $userKey, array $serverKey, int $maxAgeDays = 90): array
{
    if (strlen($token) > 8192 || !str_starts_with($token, 'mapi1.')) {
        throw new InvalidArgumentException('Invalid connection token');
    }
    $decoded = json_decode(mysqlapi_b64_decode(substr($token, 6)), true);
    if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1 || ($decoded['kid'] ?? null) !== $serverKey['kid']) {
        throw new InvalidArgumentException('Invalid or expired connection token');
    }
    foreach (['epk', 'salt', 'iv', 'ct'] as $field) {
        if (!isset($decoded[$field]) || !is_string($decoded[$field])) {
            throw new InvalidArgumentException('Invalid connection token');
        }
    }
    $userBytes = mysqlapi_b64_decode($userKey);
    $salt = mysqlapi_b64_decode($decoded['salt']);
    $iv = mysqlapi_b64_decode($decoded['iv']);
    $ciphertext = mysqlapi_b64_decode($decoded['ct']);
    if (strlen($userBytes) !== 32 || strlen($salt) !== 16 || strlen($iv) !== 12 || strlen($ciphertext) < 17 || strlen($ciphertext) > 4096) {
        throw new InvalidArgumentException('Invalid connection token or key');
    }

    $publicDer = mysqlapi_b64_decode($decoded['epk']);
    if (strlen($publicDer) > 256) {
        throw new InvalidArgumentException('Invalid ephemeral public key');
    }
    $ephemeralPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $ephemeral = openssl_pkey_get_public($ephemeralPem);
    $details = $ephemeral !== false ? openssl_pkey_get_details($ephemeral) : false;
    if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
        throw new InvalidArgumentException('Invalid ephemeral public key');
    }
    $shared = openssl_pkey_derive($ephemeral, $serverKey['private'], 32);
    if ($shared === false || strlen($shared) !== 32) {
        throw new InvalidArgumentException('Invalid key agreement');
    }
    $key = hash_hkdf('sha256', $shared . $userBytes, 32, 'mysqlapi-connection-v1', $salt);
    $aad = 'mysqlapi|v1|' . $serverKey['kid'];
    $plaintext = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($ciphertext, -16), $aad);
    if ($plaintext === false) {
        throw new InvalidArgumentException('Invalid connection token or key');
    }
    $connection = json_decode($plaintext, true);
    if (!is_array($connection) || !is_int($connection['iat'] ?? null) || !is_int($connection['exp'] ?? null)) {
        throw new InvalidArgumentException('Invalid connection token');
    }
    $now = time();
    if ($connection['iat'] > $now + 300 || $connection['exp'] < $now || $connection['exp'] <= $connection['iat'] ||
        $connection['exp'] - $connection['iat'] > $maxAgeDays * 86400) {
        throw new InvalidArgumentException('Invalid or expired connection token');
    }
    return $connection;
}
