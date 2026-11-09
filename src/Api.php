<?php
declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

final class MysqlApiHttpException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

function mysqlapi_env_int(string $name, int $default, int $min, int $max): int
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
        throw new RuntimeException('Invalid server configuration: ' . $name);
    }
    return (int) $value;
}

function mysqlapi_env_enabled(string $name): bool
{
    return in_array(strtolower((string) getenv($name)), ['1', 'true', 'yes'], true);
}

/** @param array<string,string> $headers */
function mysqlapi_authorize(array $headers): string
{
    $keys = array_values(array_filter(array_map('trim', explode(',', (string) getenv('MYSQLAPI_API_KEYS'))), 'strlen'));
    if ($keys === []) {
        if (mysqlapi_env_enabled('MYSQLAPI_ALLOW_UNAUTHENTICATED')) {
            return 'local-experiment';
        }
        throw new MysqlApiHttpException(503, 'Service API keys are not configured');
    }
    $authorization = $headers['authorization'] ?? '';
    if (!preg_match('/^Bearer ([^\s]+)$/iD', $authorization, $matches)) {
        throw new MysqlApiHttpException(401, 'API authentication required');
    }
    $valid = false;
    foreach ($keys as $expected) {
        $valid = hash_equals($expected, $matches[1]) || $valid;
    }
    if (!$valid) {
        throw new MysqlApiHttpException(401, 'Invalid API credentials');
    }
    return hash('sha256', $matches[1]);
}

/** @param array<string,string> $headers */
function mysqlapi_headers(): array
{
    $result = [];
    foreach (getallheaders() ?: [] as $key => $value) {
        $result[strtolower((string) $key)] = (string) $value;
    }
    return $result;
}

function mysqlapi_is_public_ip(string $ip): bool
{
    // PHP's reserved and private filters cover loopback, link-local, multicast and RFC1918.
    // Reject IPv4-mapped IPv6 explicitly as well.
    if (str_starts_with(strtolower($ip), '::ffff:')) {
        return mysqlapi_is_public_ip(substr($ip, 7));
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function mysqlapi_validate_host(string $hostname): void
{
    $isIp = filter_var($hostname, FILTER_VALIDATE_IP) !== false;
    if (strlen($hostname) > 253 || $hostname === '' || str_contains($hostname, "\0") ||
        (!$isIp && (preg_match('/[\s\/@:]/', $hostname) || str_contains($hostname, '\\')))) {
        throw new InvalidArgumentException('Invalid database host');
    }
    if (mysqlapi_env_enabled('MYSQLAPI_ALLOW_PRIVATE_NETWORKS')) {
        return;
    }
    if ($isIp) {
        $ips = [$hostname];
    } else {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.?$/iD', $hostname)) {
            throw new InvalidArgumentException('Invalid database host');
        }
        $ips = gethostbynamel($hostname) ?: [];
        foreach (dns_get_record($hostname, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }
    if ($ips === []) {
        throw new InvalidArgumentException('Database host could not be resolved');
    }
    foreach (array_unique($ips) as $ip) {
        if (!mysqlapi_is_public_ip($ip)) {
            throw new InvalidArgumentException('Private or reserved database destinations are not allowed');
        }
    }
    // Defense in depth only: DNS can change after validation. Block private egress
    // at the host/container firewall as documented in SECURITY.md.
}

/** @param array<string,mixed> $connection
 *  @return array{host:string,user:string,password:string,database:string,port:int,socket:?string}
 */
function mysqlapi_validate_connection(array $connection): array
{
    foreach (['host', 'user', 'password', 'database'] as $field) {
        if (!isset($connection[$field]) || !is_string($connection[$field]) || strlen($connection[$field]) > 255) {
            throw new InvalidArgumentException('Invalid database connection');
        }
    }
    $host = trim($connection['host']);
    $user = trim($connection['user']);
    $database = trim($connection['database']);
    if ($user === '' || $database === '' || $host === '' || str_contains($user, "\0") || str_contains($database, "\0")) {
        throw new InvalidArgumentException('Invalid database connection');
    }
    $port = $connection['port'] ?? 3306;
    if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Invalid database port');
    }
    $socket = $connection['socket'] ?? null;
    if ($socket !== null && (!is_string($socket) || $socket === '' || strlen($socket) > 255 || !mysqlapi_env_enabled('MYSQLAPI_ALLOW_UNIX_SOCKETS'))) {
        throw new InvalidArgumentException('Unix sockets are disabled');
    }
    mysqlapi_validate_host($host);
    return ['host' => $host, 'user' => $user, 'password' => $connection['password'], 'database' => $database, 'port' => $port, 'socket' => $socket];
}

function mysqlapi_rate_limit(string $identity, int $limit, ?string $directory = null, ?int $now = null): bool
{
    $directory ??= sys_get_temp_dir() . '/mysqlapi-rate-' . substr(hash('sha256', __DIR__), 0, 12);
    $now ??= time();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Rate limiter unavailable');
    }
    $path = $directory . '/' . hash('sha256', $identity);
    $file = fopen($path, 'c+');
    if ($file === false) {
        throw new RuntimeException('Rate limiter unavailable');
    }
    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException('Rate limiter unavailable');
        }
        $state = json_decode(stream_get_contents($file) ?: '', true);
        $start = is_array($state) && is_int($state['start'] ?? null) ? $state['start'] : $now;
        $count = is_array($state) && is_int($state['count'] ?? null) ? $state['count'] : 0;
        if ($start > $now || $start <= $now - 60) {
            $start = $now;
            $count = 0;
        }
        $count++;
        rewind($file);
        ftruncate($file, 0);
        fwrite($file, json_encode(['start' => $start, 'count' => $count]));
        fflush($file);
        return $count <= $limit;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

/** @param list<list<array<string, mixed>>> $sets */
function mysqlapi_format_data(array $sets, string $type, array $names): mixed
{
    $firstSet = $sets[0] ?? [];
    $firstRow = $firstSet[0] ?? null;
    switch (strtolower($type)) {
        case 'single':
        case 'value':
            return $firstRow !== null ? (array_values($firstRow)[0] ?? null) : null;
        case 'pair':
        case 'pairs':
            return array_map(static function (array $row): array {
                $values = array_values($row);
                return $values ? [(string) $values[0] => $values[count($values) - 1]] : [];
            }, $firstSet);
        case 'table':
        case 'view':
        case 'set':
            return $firstSet;
        case 'row':
        case 'first':
            return $firstRow;
        case 'list':
        case 'array':
        case 'values':
            return array_map(static fn (array $row) => array_values($row)[0] ?? null, $firstSet);
        case 'namedsets':
        case 'namedset':
        case 'namedrows':
        case 'namedrow':
            $named = [];
            foreach ($sets as $index => $set) {
                $name = $names[$index] ?? ((str_starts_with($type, 'namedrow')) ? 'row' : 'set') . $index;
                $named[$name] = str_starts_with($type, 'namedrow') ? ($set[0] ?? null) : $set;
            }
            return $named === [] ? (object) [] : $named;
        default:
            return $sets;
    }
}

/** @param array{host:string,user:string,password:string,database:string,port:int,socket:?string} $connection */
function mysqlapi_execute(array $connection, string $sql, int $rowLimit, int $byteLimit, int $connectTimeout): array
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = mysqli_init();
    if (!$mysqli) {
        throw new RuntimeException('Unable to initialize MySQL');
    }
    try {
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $connectTimeout);
        $flags = 0;
        if (mysqlapi_env_enabled('MYSQLAPI_MYSQL_TLS_REQUIRED')) {
            $ca = getenv('MYSQLAPI_MYSQL_CA_FILE');
            if (!$ca || !is_readable($ca)) {
                throw new RuntimeException('MySQL TLS CA is not configured');
            }
            $mysqli->ssl_set(null, null, $ca, null, null);
            if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                $mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            }
            $flags = MYSQLI_CLIENT_SSL;
        }
        $mysqli->real_connect($connection['host'], $connection['user'], $connection['password'], $connection['database'], $connection['port'], $connection['socket'], $flags);
        $mysqli->set_charset('utf8mb4');
        if (mysqlapi_env_enabled('MYSQLAPI_MYSQL_TLS_REQUIRED')) {
            $tls = $mysqli->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch_assoc();
            if (empty($tls['Value'])) {
                throw new RuntimeException('Encrypted database connection required');
            }
        }
        $mysqli->multi_query($sql);
        $sets = [];
        $totalRows = 0;
        $totalBytes = 0;
        do {
            $rows = [];
            $result = $mysqli->use_result();
            if ($result !== false) {
                try {
                    while ($row = $result->fetch_assoc()) {
                        $totalRows++;
                        $totalBytes += strlen(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
                        if ($totalRows > $rowLimit || $totalBytes > $byteLimit) {
                            throw new MysqlApiHttpException(413, 'Query result exceeds configured limits');
                        }
                        $rows[] = $row;
                    }
                } finally {
                    $result->free();
                }
                $sets[] = $rows;
            }
            if (!$mysqli->more_results()) {
                break;
            }
            if (!$mysqli->next_result()) {
                throw new RuntimeException('Unable to read all SQL results');
            }
        } while (true);
        return $sets;
    } finally {
        $mysqli->close();
    }
}

function mysqlapi_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function mysqlapi_handle_request(): void
{
    $requestId = bin2hex(random_bytes(8));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('X-Request-ID: ' . $requestId);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', (string) getenv('MYSQLAPI_ALLOWED_ORIGINS'))));
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 600');
    }
    try {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if (!mysqlapi_env_enabled('MYSQLAPI_ALLOW_HTTP') && !in_array($https, ['on', '1'], true)) {
            throw new MysqlApiHttpException(403, 'HTTPS is required');
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }
        if ($method === 'GET' && isset($_GET['public-key'])) {
            $key = mysqlapi_server_key();
            mysqlapi_json(200, ['version' => 1, 'kid' => $key['kid'], 'algorithm' => 'ECDH-P256+HKDF-SHA256+AES-256-GCM', 'publicKey' => $key['publicKey'], 'maxAgeDays' => mysqlapi_env_int('MYSQLAPI_MAX_TOKEN_AGE_DAYS', 90, 1, 365)]);
            return;
        }
        if ($method === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
            readfile(__DIR__ . '/../setup.html');
            return;
        }
        if ($method !== 'POST') {
            header('Allow: GET, POST, OPTIONS');
            throw new MysqlApiHttpException(405, 'Method not allowed');
        }
        if (!str_starts_with(strtolower(mysqlapi_headers()['content-type'] ?? ''), 'application/json')) {
            throw new MysqlApiHttpException(415, 'Content-Type must be application/json');
        }
        $identity = mysqlapi_authorize(mysqlapi_headers());
        $maxBody = mysqlapi_env_int('MYSQLAPI_MAX_BODY_BYTES', 65536, 1024, 1048576);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
            throw new MysqlApiHttpException(413, 'Request is too large');
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBody + 1);
        if ($raw === false || strlen($raw) > $maxBody) {
            throw new MysqlApiHttpException(413, 'Request is too large');
        }
        $request = json_decode($raw, true);
        if (!is_array($request) || !is_string($request['token'] ?? null) || !is_string($request['key'] ?? null) || !is_string($request['sql'] ?? null)) {
            throw new MysqlApiHttpException(400, 'token, key and sql are required');
        }
        $sql = trim($request['sql']);
        if ($sql === '') {
            throw new MysqlApiHttpException(400, 'SQL cannot be empty');
        }
        $type = strtolower(trim((string) ($request['responseType'] ?? 'sets')));
        if (!in_array($type, ['sets', 'single', 'value', 'pairs', 'pair', 'table', 'view', 'set', 'row', 'first', 'list', 'array', 'values', 'namedsets', 'namedset', 'namedrows', 'namedrow'], true)) {
            throw new MysqlApiHttpException(400, 'Invalid responseType');
        }
        $names = $request['dataNames'] ?? [];
        if (!is_array($names) || count($names) > 50 || count(array_filter($names, static fn ($name) => !is_string($name) || strlen($name) > 64)) > 0) {
            throw new MysqlApiHttpException(400, 'Invalid dataNames');
        }
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!mysqlapi_rate_limit($identity . '|' . $remoteIp, mysqlapi_env_int('MYSQLAPI_RATE_LIMIT_PER_MINUTE', 60, 1, 10000))) {
            header('Retry-After: 60');
            throw new MysqlApiHttpException(429, 'Rate limit exceeded');
        }
        try {
            $connection = mysqlapi_validate_connection(mysqlapi_decrypt_token($request['token'], $request['key'], mysqlapi_server_key(), mysqlapi_env_int('MYSQLAPI_MAX_TOKEN_AGE_DAYS', 90, 1, 365)));
        } catch (InvalidArgumentException $e) {
            throw new MysqlApiHttpException(400, 'Invalid token, user key or database destination');
        }
        $sets = mysqlapi_execute($connection, $sql, mysqlapi_env_int('MYSQLAPI_MAX_ROWS', 1000, 1, 100000), mysqlapi_env_int('MYSQLAPI_MAX_RESULT_BYTES', 2097152, 1024, 16777216), mysqlapi_env_int('MYSQLAPI_CONNECT_TIMEOUT', 5, 1, 30));
        $data = mysqlapi_format_data($sets, $type, array_values($names));
        mysqlapi_json(200, ['status' => $data === [] || $data === null ? 'EMPTY' : 'OK', 'responseType' => $type, 'dataNames' => $names, 'data' => $data, 'requestId' => $requestId]);
    } catch (MysqlApiHttpException $e) {
        mysqlapi_json($e->httpStatus, ['status' => 'ERROR', 'message' => $e->getMessage(), 'requestId' => $requestId, 'data' => null]);
    } catch (Throwable $e) {
        error_log('[mysqlapi] request=' . $requestId . ' class=' . get_class($e));
        mysqlapi_json(502, ['status' => 'ERROR', 'message' => 'Request could not be processed', 'requestId' => $requestId, 'data' => null]);
    }
}
