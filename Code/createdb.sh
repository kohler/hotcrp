#! /bin/sh
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
echo "WARNING: Code/createdb.sh is deprecated, use lib/createdb.sh." 1>&2
exec ${LIBDIR}../lib/createdb.sh "$@"
