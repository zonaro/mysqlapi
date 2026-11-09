<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php bin/generate-keypair.php /absolute/outside/webroot/mysqlapi-private.pem\n");
    exit(1);
}
$target = $argv[1];
$parent = realpath(dirname($target));
$projectRoot = realpath(__DIR__ . '/..');
if (!$parent || !str_starts_with($target, '/') || $parent === $projectRoot || str_starts_with($parent, $projectRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "The key must be saved in an existing directory outside the web root.\n");
    exit(1);
}
if (file_exists($target)) {
    fwrite(STDERR, "Refusing to overwrite an existing private key.\n");
    exit(1);
}
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($key === false || !openssl_pkey_export($key, $pem)) {
    fwrite(STDERR, "Unable to generate the server key.\n");
    exit(1);
}
$oldUmask = umask(0077);
try {
    $file = fopen($target, 'x');
    if ($file === false) {
        throw new RuntimeException('Unable to create the key file');
    }
    try {
        if (fwrite($file, $pem) !== strlen($pem)) {
            throw new RuntimeException('Unable to save the key file');
        }
    } finally {
        fclose($file);
    }
    chmod($target, 0600);
    fwrite(STDOUT, "Key generated at {$target}. Set MYSQLAPI_PRIVATE_KEY_FILE to this path.\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    umask($oldUmask);
}
