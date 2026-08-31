<?php
declare(strict_types=1);

// Run once on the host. Never print secrets or replace existing credentials.
$directory = dirname(__DIR__) . '/private';
if (file_exists($directory)) {
    throw new RuntimeException('Private directory already exists; refusing to replace secrets.');
}
umask(0077);
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create private directory.');
}
foreach (['db-password', 'db-root-password', 'admin-password'] as $name) {
    $path = $directory . '/' . $name;
    $stream = fopen($path, 'xb');
    $value = bin2hex(random_bytes(32)) . "\n";
    if ($stream === false || fwrite($stream, $value) !== strlen($value)) {
        throw new RuntimeException('Cannot create a test secret.');
    }
    fclose($stream);
    // Docker mounts just the file for different container UIDs. On the host,
    // the 0700 parent prevents other users from traversing to these files.
    if (!chmod($path, 0444)) {
        throw new RuntimeException('Cannot set secret permissions.');
    }
}
echo "Test credentials generated privately; values not displayed.\n";
