<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/Api.php';

$tests = 0;
function check(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function fails(callable $callback): bool
{
    try {
        $callback();
        return false;
    } catch (InvalidArgumentException | MysqlApiHttpException $e) {
        return true;
    }
}

check(mysqlapi_b64_decode(mysqlapi_b64_encode("\x00\xfftest")) === "\x00\xfftest", 'base64url round trip');
check(fails(fn () => mysqlapi_b64_decode('invalid+base64')), 'invalid base64 rejected');
check(mysqlapi_is_public_ip('8.8.8.8'), 'public IPv4');
check(mysqlapi_is_public_ip('2606:4700:4700::1111'), 'public IPv6');
foreach (['127.0.0.1', '10.0.0.1', '192.168.1.10', '169.254.169.254', '::1', 'fc00::1', '::ffff:127.0.0.1'] as $private) {
    check(!mysqlapi_is_public_ip($private), 'blocked ' . $private);
}
check(fails(fn () => mysqlapi_validate_host('127.0.0.1')), 'local MySQL denied');
check(fails(fn () => mysqlapi_validate_host('localhost')), 'localhost denied');
check(fails(fn () => mysqlapi_validate_host('https://example.com')), 'URL host denied');
check(mysqlapi_format_data([[['name' => 'Alice', 'id' => 3]]], 'single', []) === 'Alice', 'single');
check(mysqlapi_format_data([[['name' => 'Alice', 'id' => 3]]], 'row', []) === ['name' => 'Alice', 'id' => 3], 'row');
check(mysqlapi_format_data([[['name' => 'Alice', 'id' => 3]]], 'pairs', []) === [['Alice' => 3]], 'pairs');
check(mysqlapi_format_data([[['name' => 'Alice']]], 'list', []) === ['Alice'], 'list');
check(mysqlapi_format_data([], 'single', []) === null, 'empty single');
check(mysqlapi_format_data([], 'namedsets', []) instanceof stdClass, 'empty namedsets');
check(mysqlapi_format_data([[['x' => 1]], [['x' => 2]]], 'namedrows', ['first', 'second']) === ['first' => ['x' => 1], 'second' => ['x' => 2]], 'namedrows');

putenv('MYSQLAPI_API_KEYS=test-first-key,second-key');
check(mysqlapi_authorize(['authorization' => 'Bearer second-key']) === hash('sha256', 'second-key'), 'API auth');
check(fails(fn () => mysqlapi_authorize(['authorization' => 'Bearer invalid'])), 'bad API key rejected');

$limitDir = sys_get_temp_dir() . '/mysqlapi-test-' . bin2hex(random_bytes(8));
try {
    check(mysqlapi_rate_limit('client', 2, $limitDir, 100), 'rate limit first');
    check(mysqlapi_rate_limit('client', 2, $limitDir, 101), 'rate limit second');
    check(!mysqlapi_rate_limit('client', 2, $limitDir, 102), 'rate limit enforced');
    check(mysqlapi_rate_limit('client', 2, $limitDir, 161), 'rate limit resets');
} finally {
    @unlink($limitDir . '/' . hash('sha256', 'client'));
    @rmdir($limitDir);
}

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
check($key !== false && openssl_pkey_export($key, $pem), 'generate P-256 key');
$keyFile = tempnam(sys_get_temp_dir(), 'mysqlapi-key-test-');
try {
    file_put_contents($keyFile, $pem);
    putenv('MYSQLAPI_PRIVATE_KEY_FILE=' . $keyFile);
    $server = mysqlapi_server_key();
    $command = ['node', __DIR__ . '/crypto-vector.mjs', $server['publicKey'], $server['kid']];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'start WebCrypto test');
    $json = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'WebCrypto generated a token: ' . $error);
    $vector = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $connection = mysqlapi_decrypt_token($vector['token'], $vector['key'], $server);
    check($connection['host'] === 'db.example.com' && $connection['password'] === 'a-secret', 'WebCrypto → PHP token decryption');
    check(fails(fn () => mysqlapi_decrypt_token($vector['token'], mysqlapi_b64_encode(random_bytes(32)), $server)), 'wrong user key rejected');
    check(fails(fn () => mysqlapi_decrypt_token($vector['token'] . 'x', $vector['key'], $server)), 'altered token rejected');
} finally {
    @unlink($keyFile);
}

echo "OK: {$tests} tests passed\n";
