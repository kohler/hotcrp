#! /bin/sh
exec php -d error_reporting=E_ALL test/run.php "$@"
