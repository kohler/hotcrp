#! /bin/sh
## backupdb.sh -- HotCRP script to back up database
## Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

# Now relies on PHP.
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
exec php ${LIBDIR}../batch/backupdb.php "$@"
