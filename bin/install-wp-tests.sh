#!/usr/bin/env sh

set -eu

: "${WP_CORE_DIR:=/tmp/wordpress}"
: "${WP_TESTS_DIR:=/tmp/wordpress-tests-lib}"
: "${WP_TESTS_DB_HOST:=test-database}"
: "${WP_TESTS_DB_NAME:=term_steward_tests}"
: "${WP_TESTS_DB_USER:=root}"
: "${WP_TESTS_DB_PASSWORD:=root}"
: "${WP_VERSION:=6.6.2}"

if [ ! -f "${WP_CORE_DIR}/wp-load.php" ]; then
	mkdir -p "${WP_CORE_DIR}"
	curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
		| tar --extract --gzip --strip-components=1 --directory "${WP_CORE_DIR}"
fi

if [ ! -f "${WP_TESTS_DIR}/includes/bootstrap.php" ]; then
	mkdir -p "${WP_TESTS_DIR}"
	svn export --quiet --force \
		"https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes" \
		"${WP_TESTS_DIR}/includes"
	svn export --quiet --force \
		"https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data" \
		"${WP_TESTS_DIR}/data"
	svn export --quiet --force \
		"https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php" \
		"${WP_TESTS_DIR}/wp-tests-config.php"
fi

sed -i "s/youremptytestdbnamehere/${WP_TESTS_DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s/yourusernamehere/${WP_TESTS_DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s/yourpasswordhere/${WP_TESTS_DB_PASSWORD}/" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|localhost|${WP_TESTS_DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "${WP_TESTS_DIR}/wp-tests-config.php"

# Refresh an existing shared test-library volume after project identifiers change.
sed -i "s|define( 'DB_NAME', '[^']*' );|define( 'DB_NAME', '${WP_TESTS_DB_NAME}' );|" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|define( 'DB_USER', '[^']*' );|define( 'DB_USER', '${WP_TESTS_DB_USER}' );|" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|define( 'DB_PASSWORD', '[^']*' );|define( 'DB_PASSWORD', '${WP_TESTS_DB_PASSWORD}' );|" "${WP_TESTS_DIR}/wp-tests-config.php"
sed -i "s|define( 'DB_HOST', '[^']*' );|define( 'DB_HOST', '${WP_TESTS_DB_HOST}' );|" "${WP_TESTS_DIR}/wp-tests-config.php"
