#! /bin/sh
## runsql.sh -- HotCRP database shell
## HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
export PROGDIR=`echo "$0" | sed 's,[^/]*$,,'`
test -z "$PROGDIR" && PROGDIR=.
. $PROGDIR/dbhelper.sh

usage () {
    echo "Usage: $PROG [MYSQL-OPTIONS]
       $PROG --show-password EMAIL" 1>&2
    exit 1
}

export PROG=$0
export FLAGS=
show_password=
options_file=options.inc
while [ $# -gt 0 ]; do
    case "$1" in
    --show-password=*)
        test -z "$show_password" || usage
	show_password="`echo "+$1" | sed 's/^[^=]*=//'`";;
    --show-password)
	test "$#" -gt 1 -a -z "$show_password" || usage
	show_password="$2"; shift;;
    --help) usage;;
    -*)	FLAGS="$FLAGS $1";;
    *) usage;;
    esac
    shift
done

if [ ! -r "${PROGDIR}${options_file}" ]; then
    echo "runsql.sh: Can't read ${PROGDIR}${options_file}! Is this a CRP directory?" 1>&2
    exit 1
fi

dbname="`getdbopt dbName 2>/dev/null`"
dbuser="`getdbopt dbUser 2>/dev/null`"
dbpass="`getdbopt dbPassword 2>/dev/null`"
test -z "$dbname" -o -z "$dbuser" -o -z "$dbpass" && { echo "runsql.sh: Cannot extract database run options from ${options_file}!" 1>&2; exit 1; }

check_mysqlish MYSQL mysql
set_myargs "$dbuser" "$dbpass"

if test -n "$show_password"; then
    show_password="`echo "+$show_password" | sed -e 's,^.,,' | sql_quote`"
    echo "select concat(email, ',', password) from ContactInfo where email like '$show_password' and disabled=0" | eval "$MYSQL $myargs -N $FLAGS $dbname"
else
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    eval "$MYSQL $myargs $FLAGS $dbname"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
