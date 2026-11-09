<?php
function app_env($key, $default = '') {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    $file = __DIR__ . '/local.env';
    if (is_file($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') !== false) {
                [$k, $val] = explode('=', $line, 2);
                if (trim($k) === $key) {
                    $v = trim($val);
                    // Remove quotes if present
                    if (preg_match('/^"(.*)"$/', $v, $matches)) $v = $matches[1];
                    if (preg_match("/^'(.*)'$/", $v, $matches)) $v = $matches[1];
                    return $v;
                }
            }
        }
    }
    return $default;
}
