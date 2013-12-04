#! /bin/sh
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
echo "WARNING: Code/runsql.sh is deprecated, use lib/runsql.sh." 1>&2
exec ${LIBDIR}../lib/runsql.sh "$@"
