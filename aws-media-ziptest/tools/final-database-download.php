<?php
declare(strict_types=1);

use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

try {
    if (
        PHP_SAPI !== 'cli'
        || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest'
        || getenv('WORDPRESS_DB_HOST') !== 'db:3306'
    ) {
        throw new RuntimeException('Wrong environment.');
    }

    define('DISABLE_WP_CRON', true);
    umask(0077);
    require '/var/www/html/wp-load.php';
    global $wpdb;

    if (
        ! defined('ODBFS3_ISOLATED_ZIPTEST')
        || ! ODBFS3_ISOLATED_ZIPTEST
        || $wpdb->prefix !== 'ziptest_'
    ) {
        throw new RuntimeException('Isolation guard failed.');
    }

    $key = $argv[1] ?? '';
    if (
        preg_match(
            '#^wordpress-test/media-cron-ziptest/backups/database/[0-9]{4}/[0-9]{2}/[0-9]{2}/db-odbfs3_ziptest-[0-9]{8}-[0-9]{6}\.sql\.gz$#D',
            $key
        ) !== 1
    ) {
        throw new RuntimeException('Unexpected database backup key.');
    }

    $options = get_option('secure_s3_storage_settings', []);
    if (
        ($options['region'] ?? '') !== 'ap-northeast-1'
        || ($options['bucket'] ?? '') !== 'ceri-secure-s3-storage-test'
        || ($options['prefix'] ?? '') !== 'wordpress-test/media-cron-ziptest/'
        || ($options['backup_schedule'] ?? '') !== 'disabled'
        || ($options['retention_keep_count'] ?? -1) !== 0
    ) {
        throw new RuntimeException('Unexpected test settings.');
    }

    $directory = '/var/lib/odbfs3-work/database-restore-' . bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create restore directory.');
    }

    $gzipPath = $directory . '/backup.sql.gz';
    $gzipStream = SecureTemporaryFile::openForWriting($gzipPath);
    try {
        $client = (new S3ClientFactory())->create($options['region']);
        $result = $client->getObject([
            'Bucket' => $options['bucket'],
            'Key' => $key,
            '@http' => [
                'sink' => $gzipStream,
                'connect_timeout' => 5,
                'timeout' => 120,
            ],
        ]);
        fflush($gzipStream);
    } finally {
        if (is_resource($gzipStream)) {
            fclose($gzipStream);
        }
    }

    clearstatcache(true, $gzipPath);
    $gzipBytes = filesize($gzipPath);
    if (
        $gzipBytes === false
        || ! in_array($result['ContentLength'], [$gzipBytes, (string) $gzipBytes], true)
        || file_get_contents($gzipPath, false, null, 0, 2) !== "\x1f\x8b"
    ) {
        throw new RuntimeException('Downloaded gzip differs.');
    }

    $sqlPath = $directory . '/backup.sql';
    $source = gzopen($gzipPath, 'rb');
    $destination = SecureTemporaryFile::openForWriting($sqlPath);
    if ($source === false) {
        throw new RuntimeException('Cannot open downloaded gzip.');
    }

    try {
        while (! gzeof($source)) {
            $chunk = gzread($source, 1048576);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read downloaded gzip.');
            }
            if ($chunk !== '') {
                CompleteStreamWriter::writeAll(
                    $chunk,
                    static fn (string $remaining): int|false => fwrite($destination, $remaining),
                    'Cannot write expanded SQL.'
                );
            }
        }
        fflush($destination);
    } finally {
        gzclose($source);
        fclose($destination);
    }

    clearstatcache(true, $sqlPath);
    $sqlBytes = filesize($sqlPath);
    $prefix = file_get_contents($sqlPath, false, null, 0, min(65536, (int) $sqlBytes));
    if (
        $sqlBytes === false
        || $sqlBytes < 1024
        || ! is_string($prefix)
        || ! str_contains($prefix, 'MariaDB dump')
        || ! str_contains($prefix, 'Table structure for table')
    ) {
        throw new RuntimeException('Expanded SQL is not a database dump.');
    }

    echo json_encode([
        'result' => 'database_s3_download_verified',
        'bucket' => $options['bucket'],
        'key' => $key,
        'directory' => $directory,
        'gzip_bytes' => $gzipBytes,
        'gzip_sha256' => hash_file('sha256', $gzipPath),
        'sql_bytes' => $sqlBytes,
        'sql_sha256' => hash_file('sha256', $sqlPath),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'database_download_failed=' . get_class($exception)
        . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . PHP_EOL
    );
    exit(1);
}
