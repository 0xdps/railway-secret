<?php

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Simple .env loader
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line || strpos($line, '#') === 0) continue;
        
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Remove optional quotes
            $value = trim($value, '"\'');
            
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

/**
 * Get configuration for a specific time unit.
 * @return array{divisor: int, label: string, suffix: string}
 */
function getUnitConfig(string $unit): array
{
    $map = [
        'minute' => ['divisor' => 60,    'label' => 'minutes', 'suffix' => 'm'],
        'hour'   => ['divisor' => 3600,  'label' => 'hours',   'suffix' => 'h'],
        'day'    => ['divisor' => 86400, 'label' => 'days',    'suffix' => 'd'],
    ];
    return $map[$unit] ?? $map['day'];
}


