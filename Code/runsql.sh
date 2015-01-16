#! /bin/sh
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
echo "ERROR: Code/runsql.sh is obsolete, use lib/runsql.sh." 1>&2
exit 1
