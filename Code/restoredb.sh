#! /bin/sh
## restoredb.sh -- HotCRP database restore from backup
## HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh

usage () {
    echo "Usage: $PROG [MYSQL-OPTIONS] BACKUPFILE" 1>&2
    exit 1
}

export PROG=$0
export FLAGS=""
input=
while [ $# -gt 0 ]; do
    case "$1" in
    -*)	FLAGS="$FLAGS $1";;
    *)	if [ -z "$input" ]; then input="$1"; else usage; fi;;
    esac
    shift
done

if [ -z "`findoptions`" ]; then
    echo "restoredb.sh: Can't read options file! Is this a CRP directory?" 1>&2
    exit 1
elif [ -t 0 ]; then
    echo "restoredb.sh: Standard input is a terminal" 1>&2; usage
fi

dbname="`getdbopt dbName 2>/dev/null`"
dbuser="`getdbopt dbUser 2>/dev/null`"
dbpass="`getdbopt dbPassword 2>/dev/null`"
test -z "$dbname" -o -z "$dbuser" -o -z "$dbpass" && { echo "backupdb.sh: Cannot extract database run options from `findoptions`!" 1>&2; exit 1; }

### Test mysqldump binary
check_mysqlish MYSQL mysql
set_myargs "$dbuser" "$dbpass"

if test -z "$input"; then
    echo + $MYSQL $myargs_redacted $FLAGS $dbname 1>&2
    eval "$MYSQL $myargs $FLAGS $dbname"
else
    echo + $MYSQL $myargs_redacted $FLAGS $dbname "<" "$input" 1>&2
    eval "$MYSQL $myargs $FLAGS $dbname" < "$input"
fi
