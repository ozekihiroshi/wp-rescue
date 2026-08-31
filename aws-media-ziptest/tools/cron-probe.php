<?php
/** Test-only MU plugin: no S3 operations, no production-plugin modification. */
if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST) {
    return;
}
add_action('odbfs3_ziptest_cron_probe', static function (): void {
    update_option('odbfs3_ziptest_cron_result', [
        'doing_cron' => defined('DOING_CRON') && DOING_CRON,
        'sapi' => PHP_SAPI,
        'utc' => gmdate('c'),
    ], false);
});
