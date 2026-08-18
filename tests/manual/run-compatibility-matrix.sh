#!/usr/bin/env bash

set -Eeuo pipefail

readonly DB_CONTAINER='wp-rescue-db-local'
readonly DOCKER_NETWORK='wp-rescue_internal'
readonly DB_HOST='wp-rescue-db:3306'
readonly WP_ADMIN_PASSWORD='matrix-test-password-only'

readonly SCRIPT_DIR="$(
    cd "$(dirname "${BASH_SOURCE[0]}")"
    pwd
)"
readonly WP_RESCUE_DIR="$(
    cd "${SCRIPT_DIR}/../.."
    pwd
)"
readonly PLUGIN_SOURCE_DIR="$(
    cd "${WP_RESCUE_DIR}/../secure-s3-storage-for-wordpress"
    pwd
)"
readonly RESTORE_TEST_FILE="${PLUGIN_SOURCE_DIR}/tests/manual/test-database-restore.php"
readonly RELEASE_MOUNT_PATH='/release/ozeki-database-backup-for-s3.zip'

readonly -a PHP_VERSIONS=('8.1' '8.3')
readonly -a WORDPRESS_VERSIONS=('5.9.13' '7.0.2' '7.1-RC2')

DB_ROOT_PASSWORD=''
DB_USER=''
DB_PASSWORD=''
PLUGIN_ZIP="${PLUGIN_ZIP:-}"
ACTIVE_CONTAINER=''
ACTIVE_VOLUME=''
ACTIVE_SOURCE_DATABASE=''
ACTIVE_DATABASES=()

require_command() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "Required command is missing: $1" >&2
        exit 1
    }
}

resolve_plugin_zip() {
    local -a release_zips=()

    if [[ -z "${PLUGIN_ZIP}" ]]; then
        mapfile -t release_zips < <(
            find "${PLUGIN_SOURCE_DIR}/build" \
                -maxdepth 1 \
                -type f \
                -name 'ozeki-database-backup-for-s3-*.zip' \
                -print
        )

        if [[ "${#release_zips[@]}" -ne 1 ]]; then
            echo 'Expected exactly one release ZIP in the plugin build directory.' >&2
            return 1
        fi

        PLUGIN_ZIP="${release_zips[0]}"
    fi

    PLUGIN_ZIP="$(realpath "${PLUGIN_ZIP}")"

    [[ -f "${PLUGIN_ZIP}" ]] || {
        echo "Release ZIP not found: ${PLUGIN_ZIP}" >&2
        return 1
    }
    [[ -f "${RESTORE_TEST_FILE}" ]] || {
        echo "Restore test not found: ${RESTORE_TEST_FILE}" >&2
        return 1
    }

    unzip -tq "${PLUGIN_ZIP}" >/dev/null
}

validate_database_name() {
    [[ "$1" =~ ^secure_s3_matrix_[a-f0-9]{12}$ ]] \
        || [[ "$1" =~ ^secure_s3_restore_test_[a-f0-9]{12}$ ]] \
        || {
        echo "Unsafe temporary database name: $1" >&2
        return 1
    }
}

drop_active_databases() {
    local database
    local sql=''

    for database in "${ACTIVE_DATABASES[@]}"; do
        validate_database_name "${database}"
        sql+="DROP DATABASE IF EXISTS ${database};"
    done

    if [[ -n "${sql}" ]]; then
        docker exec \
            -e MYSQL_PWD="${DB_ROOT_PASSWORD}" \
            "${DB_CONTAINER}" \
            mariadb -uroot -e "${sql}"
    fi

    ACTIVE_DATABASES=()
}

cleanup_active_cell() {
    if [[ -n "${ACTIVE_CONTAINER}" ]]; then
        if [[ "${ACTIVE_CONTAINER}" =~ ^secure-s3-matrix-[a-f0-9]{12}$ ]]; then
            docker rm -f "${ACTIVE_CONTAINER}" >/dev/null 2>&1 || true
        else
            echo "Refusing to remove unexpected container: ${ACTIVE_CONTAINER}" >&2
        fi

        ACTIVE_CONTAINER=''
    fi

    if [[ -n "${ACTIVE_VOLUME}" ]]; then
        if [[ "${ACTIVE_VOLUME}" =~ ^secure_s3_matrix_[a-f0-9]{12}$ ]]; then
            docker volume rm "${ACTIVE_VOLUME}" >/dev/null 2>&1 || true
        else
            echo "Refusing to remove unexpected volume: ${ACTIVE_VOLUME}" >&2
        fi

        ACTIVE_VOLUME=''
    fi

    drop_active_databases
}

trap cleanup_active_cell EXIT

create_database() {
    local database="$1"

    validate_database_name "${database}"

    docker exec \
        -e MYSQL_PWD="${DB_ROOT_PASSWORD}" \
        "${DB_CONTAINER}" \
        mariadb -uroot -e \
        "CREATE DATABASE ${database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON ${database}.* TO '${DB_USER}'@'%';"

    ACTIVE_DATABASES+=("${database}")
}

run_wp_cli() {
    local php_version="$1"
    shift

    docker run --rm \
        --user 33:33 \
        --entrypoint php \
        --network "${DOCKER_NETWORK}" \
        -e HOME=/tmp \
        -e WORDPRESS_DB_HOST="${DB_HOST}" \
        -e WORDPRESS_DB_NAME="${ACTIVE_SOURCE_DATABASE}" \
        -e WORDPRESS_DB_USER="${DB_USER}" \
        -e WORDPRESS_DB_PASSWORD="${DB_PASSWORD}" \
        -v "${ACTIVE_VOLUME}:/var/www/html" \
        -v "${PLUGIN_ZIP}:${RELEASE_MOUNT_PATH}:ro" \
        "wordpress:cli-php${php_version}" \
        -d memory_limit=512M /usr/local/bin/wp \
        --path=/var/www/html \
        --skip-themes \
        "$@"
}

prepare_wordpress_files() {
    local php_version="$1"
    local wordpress_version="$2"

    docker volume create "${ACTIVE_VOLUME}" >/dev/null

    docker run --rm \
        --user 0:0 \
        --entrypoint php \
        -v "${ACTIVE_VOLUME}:/matrix" \
        "wordpress:cli-php${php_version}" \
        -d memory_limit=512M /usr/local/bin/wp \
        core download \
        --version="${wordpress_version}" \
        --path=/matrix \
        --force \
        --allow-root \
        --quiet

    docker run --rm \
        --user 0:0 \
        --entrypoint sh \
        -v "${ACTIVE_VOLUME}:/matrix" \
        "wordpress:cli-php${php_version}" \
        -c 'chown -R 33:33 /matrix'
}


start_wordpress_container() {
    local php_version="$1"
    local source_database="$2"
    local image="secure-s3-matrix-php:${php_version}"
    local attempt

    docker run -d \
        --entrypoint apache2-foreground \
        --name "${ACTIVE_CONTAINER}" \
        --network "${DOCKER_NETWORK}" \
        -e WORDPRESS_DB_HOST="${DB_HOST}" \
        -e WORDPRESS_DB_NAME="${source_database}" \
        -e WORDPRESS_DB_USER="${DB_USER}" \
        -e WORDPRESS_DB_PASSWORD="${DB_PASSWORD}" \
        -e WORDPRESS_TABLE_PREFIX=wp_ \
        -v "${ACTIVE_VOLUME}:/var/www/html" \
        "${image}" >/dev/null

    for attempt in $(seq 1 60); do
        if docker exec "${ACTIVE_CONTAINER}" test -f /var/www/html/wp-config.php; then
            return
        fi

        sleep 1
    done

    docker logs "${ACTIVE_CONTAINER}" >&2
    echo 'Timed out waiting for wp-config.php.' >&2
    return 1
}

install_release_plugin() {
    local php_version="$1"

    run_wp_cli "${php_version}" plugin install \
        "${RELEASE_MOUNT_PATH}" \
        --force \
        --activate \
        --quiet

    run_wp_cli "${php_version}" plugin is-active ozeki-database-backup-for-s3

    run_wp_cli "${php_version}" eval '
        if (! class_exists("SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client")) {
            throw new RuntimeException("Scoped AWS SDK class is unavailable.");
        }
        if (class_exists("Aws\\S3\\S3Client")) {
            throw new RuntimeException("Unscoped AWS SDK class is autoloadable.");
        }
    '
}

stage_restore_test() {
    local php_version="$1"

    docker run --rm \
        --user 0:0 \
        --entrypoint sh \
        -v "${ACTIVE_VOLUME}:/var/www/html" \
        -v "${RESTORE_TEST_FILE}:/matrix-tests/test-database-restore.php:ro" \
        "wordpress:cli-php${php_version}" \
        -c '
            set -eu
            test_dir=/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3/tests/manual
            mkdir -p "${test_dir}"
            cp /matrix-tests/test-database-restore.php "${test_dir}/test-database-restore.php"
            chown -R 33:33 /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3/tests
        '
}

verify_restore_backend() {
    local backend="$1"
    local restore_database="$2"

    create_database "${restore_database}"

    docker exec \
        -e RESTORE_TEST_DB_NAME="${restore_database}" \
        -e RESTORE_TEST_BACKEND="${backend}" \
        "${ACTIVE_CONTAINER}" \
        php \
        /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3/tests/manual/test-database-restore.php
}

run_cell() {
    local php_version="$1"
    local wordpress_version="$2"
    local cell_hash
    local native_hash
    local php_hash
    local source_database
    local native_database
    local php_database
    local actual_php
    local actual_wordpress

    cell_hash="$(
        printf '%s' "php-${php_version}-wp-${wordpress_version}" \
            | sha256sum \
            | cut -c1-12
    )"
    native_hash="$(
        printf '%s' "${cell_hash}-native" \
            | sha256sum \
            | cut -c1-12
    )"
    php_hash="$(
        printf '%s' "${cell_hash}-php" \
            | sha256sum \
            | cut -c1-12
    )"

    source_database="secure_s3_matrix_${cell_hash}"
    ACTIVE_SOURCE_DATABASE="${source_database}"
    native_database="secure_s3_restore_test_${native_hash}"
    php_database="secure_s3_restore_test_${php_hash}"
    ACTIVE_CONTAINER="secure-s3-matrix-${cell_hash}"
    ACTIVE_VOLUME="secure_s3_matrix_${cell_hash}"

    echo
    echo "=== PHP ${php_version} / WordPress ${wordpress_version} ==="

    create_database "${source_database}"
    prepare_wordpress_files "${php_version}" "${wordpress_version}"
    run_wp_cli "${php_version}" config create \
        --dbname="${source_database}" \
        --dbuser="${DB_USER}" \
        --dbpass="${DB_PASSWORD}" \
        --dbhost="${DB_HOST}" \
        --skip-salts \
        --force \
        --quiet
    start_wordpress_container "${php_version}" "${source_database}"

    run_wp_cli "${php_version}" core install \
        --url="http://${ACTIVE_CONTAINER}.invalid" \
        --title='Secure S3 compatibility test' \
        --admin_user=matrix_admin \
        --admin_password="${WP_ADMIN_PASSWORD}" \
        --admin_email=matrix@example.invalid \
        --skip-email \
        --quiet

    install_release_plugin "${php_version}"
    stage_restore_test "${php_version}"

    actual_php="$(
        docker exec "${ACTIVE_CONTAINER}" \
            php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'
    )"
    actual_wordpress="$(
        run_wp_cli "${php_version}" core version
    )"

    [[ "${actual_php}" == "${php_version}" ]] || {
        echo "Unexpected PHP version: ${actual_php}" >&2
        return 1
    }
    [[ "${actual_wordpress}" == "${wordpress_version}" ]] || {
        echo "Unexpected WordPress version: ${actual_wordpress}" >&2
        return 1
    }

    verify_restore_backend native "${native_database}"
    verify_restore_backend php "${php_database}"

    echo "PASS PHP ${actual_php} / WordPress ${actual_wordpress} / native"
    echo "PASS PHP ${actual_php} / WordPress ${actual_wordpress} / php"

    cleanup_active_cell
}

main() {
    local php_version
    local wordpress_version

    require_command docker
    require_command find
    require_command realpath
    require_command sha256sum
    require_command unzip

    resolve_plugin_zip

    echo "Release ZIP: ${PLUGIN_ZIP}"
    echo "Release SHA-256: $(sha256sum "${PLUGIN_ZIP}" | cut -d ' ' -f 1)"

    docker inspect "${DB_CONTAINER}" >/dev/null
    docker network inspect "${DOCKER_NETWORK}" >/dev/null

    DB_ROOT_PASSWORD="$(
        docker exec "${DB_CONTAINER}" printenv MARIADB_ROOT_PASSWORD
    )"
    DB_USER="$(
        docker exec "${DB_CONTAINER}" printenv MARIADB_USER
    )"
    DB_PASSWORD="$(
        docker exec "${DB_CONTAINER}" printenv MARIADB_PASSWORD
    )"

    [[ -n "${DB_ROOT_PASSWORD}" && -n "${DB_PASSWORD}" ]] || {
        echo 'Database credentials are unavailable.' >&2
        exit 1
    }
    [[ "${DB_USER}" =~ ^[A-Za-z0-9_]+$ ]] || {
        echo 'Database username contains unexpected characters.' >&2
        exit 1
    }

    for php_version in "${PHP_VERSIONS[@]}"; do
        docker build \
            --build-arg "PHP_VERSION=${php_version}" \
            -t "secure-s3-matrix-php:${php_version}" \
            -f "${WP_RESCUE_DIR}/wordpress/Dockerfile" \
            "${WP_RESCUE_DIR}"
    done

    for php_version in "${PHP_VERSIONS[@]}"; do
        for wordpress_version in "${WORDPRESS_VERSIONS[@]}"; do
            run_cell "${php_version}" "${wordpress_version}"
        done
    done

    echo
    echo 'Compatibility matrix: all 12 backend combinations passed.'
}

main "$@"