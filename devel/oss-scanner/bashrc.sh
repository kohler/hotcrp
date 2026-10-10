## bashrc.sh -- interactive-shell notice for the OSS Scanner image
## Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if [ ! -S /run/mysqld/mysqld.sock ]; then
    echo 1>&2
    echo "NOTE: \`hotcrp-scanctl start\` starts MariaDB, php-fpm, and nginx." 1>&2
    echo "      \`hotcrp-scanctl help\` has more." 1>&2
    echo 1>&2
fi
