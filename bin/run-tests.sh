#!/usr/bin/env sh

set -eu

/app/bin/install-wp-tests.sh
/app/vendor/bin/phpunit
/app/vendor/bin/phpunit --group ajax
