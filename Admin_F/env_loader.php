<?php
/**
 * env_loader.php — Lightweight .env file parser
 * Reads key=value pairs from .env and makes them available via getenv() and $_ENV
 */

function load_env($path = null) {
    if ($path === null) {
        $path = __DIR__ . '/.env';
    }

    if (!file_exists($path)) {
        error_log("env_loader: .env file not found at $path");
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Parse KEY=VALUE
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Remove surrounding quotes if present
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Only set if not already defined (system env takes precedence)
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }

    return true;
}

// Auto-load on include
load_env();
?>
