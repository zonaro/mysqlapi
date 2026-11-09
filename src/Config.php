<?php
declare(strict_types=1);

/** Keep credentials outside the public directory by default. */
function mysqlapi_env_path(): string
{
    $override = getenv('MYSQLAPI_ENV_FILE');
    if ($override !== false && $override !== '') {
        return $override;
    }
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $projectRoot = realpath(__DIR__ . '/..');
    if ($documentRoot && $projectRoot &&
        ($projectRoot === $documentRoot || str_starts_with($projectRoot, $documentRoot . DIRECTORY_SEPARATOR))) {
        return dirname($documentRoot) . '/.env';
    }
    return dirname(__DIR__, 2) . '/.env';
}

function mysqlapi_env_is_public(string $path): bool
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $parent = realpath(dirname($path));
    return $documentRoot !== false && $parent !== false &&
        ($parent === $documentRoot || str_starts_with($parent, $documentRoot . DIRECTORY_SEPARATOR));
}

function mysqlapi_load_env(): bool
{
    $path = mysqlapi_env_path();
    if (!is_file($path)) {
        return false;
    }
    if (mysqlapi_env_is_public($path)) {
        throw new RuntimeException('Installation configuration must be outside the web root');
    }
    if (!is_readable($path)) {
        throw new RuntimeException('Installation configuration is not readable');
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^(MYSQLAPI_[A-Z0-9_]+)=(.*)$/D', $line, $matches)) {
            throw new RuntimeException('Invalid installation configuration');
        }
        $value = trim($matches[2]);
        if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
            $value = stripcslashes(substr($value, 1, -1));
        }
        if (getenv($matches[1]) === false) {
            putenv($matches[1] . '=' . $value);
        }
    }
    return true;
}

function mysqlapi_env_quote(string $value): string
{
    return '"' . addcslashes($value, "\\\"\n\r") . '"';
}
