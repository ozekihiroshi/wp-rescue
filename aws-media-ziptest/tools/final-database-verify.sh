#!/bin/sh
set -eu

restore_database=${1:?Pass the exact restore database name}

case "$restore_database" in
    odbfs3_restore_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]) ;;
    *) echo "Invalid restore database name." >&2; exit 2 ;;
esac

test -r /run/secrets/db_root_password
export MYSQL_PWD
MYSQL_PWD=$(cat /run/secrets/db_root_password)

existing=$(mariadb -uroot -Nse \
    "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${restore_database}'")
test "$existing" = "$restore_database"

source_tables=$(mariadb -uroot -Nse \
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA='odbfs3_ziptest' ORDER BY TABLE_NAME")
restore_tables=$(mariadb -uroot -Nse \
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA='${restore_database}' ORDER BY TABLE_NAME")

test -n "$source_tables"
test "$source_tables" = "$restore_tables"

table_count=0
checksum_count=0
for table in $restore_tables; do
    check_result=$(mariadb -uroot -Nse \
        "CHECK TABLE \`${restore_database}\`.\`${table}\`" | awk '{print $4}')
    test "$check_result" = "OK"

    table_count=$((table_count + 1))
    if test "$table" = "ziptest_options"; then
        continue
    fi

    source_checksum=$(mariadb -uroot -Nse \
        "CHECKSUM TABLE \`odbfs3_ziptest\`.\`${table}\`" | awk '{print $2}')
    restore_checksum=$(mariadb -uroot -Nse \
        "CHECKSUM TABLE \`${restore_database}\`.\`${table}\`" | awk '{print $2}')
    test -n "$source_checksum"
    test "$source_checksum" = "$restore_checksum"
    checksum_count=$((checksum_count + 1))
done

source_settings=$(mariadb -uroot -Nse \
    "SELECT SHA2(CONCAT(option_name, option_value, autoload), 256)
     FROM \`odbfs3_ziptest\`.\`ziptest_options\`
     WHERE option_name='secure_s3_storage_settings'")
restore_settings=$(mariadb -uroot -Nse \
    "SELECT SHA2(CONCAT(option_name, option_value, autoload), 256)
     FROM \`${restore_database}\`.\`ziptest_options\`
     WHERE option_name='secure_s3_storage_settings'")
test -n "$source_settings"
test "$source_settings" = "$restore_settings"

printf '{"result":"database_restore_verified","source_database":"odbfs3_ziptest","restore_database":"%s","tables_checked":%d,"table_checksums_matched":%d,"dynamic_options_table_checked":true,"settings_row_matched":true}\n' \
    "$restore_database" "$table_count" "$checksum_count"
