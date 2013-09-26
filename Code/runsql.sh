#! /bin/sh
## runsql.sh -- HotCRP database shell
## HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
export PROGDIR=`echo "$0" | sed 's,[^/]*$,,'`
test -z "$PROGDIR" && PROGDIR=.
. $PROGDIR/dbhelper.sh

usage () {
    if [ -z "$1" ]; then status=1; else status=$1; fi
    echo "Usage: $PROG [MYSQL-OPTIONS]
       $PROG --show-password EMAIL
       $PROG --set-password EMAIL PASSWORD" |
       if [ $status = 0 ]; then cat; else cat 1>&2; fi
    exit $status
}

export PROG=$0
export FLAGS=
pwuser=
pwvalue=
options_file=options.inc
while [ $# -gt 0 ]; do
    case "$1" in
    --show-password=*)
        test -z "$pwuser" || usage
	pwuser="`echo "+$1" | sed 's/^[^=]*=//'`";;
    --show-password)
	test "$#" -gt 1 -a -z "$pwuser" || usage
	pwuser="$2"; shift;;
    --set-password)
        test "$#" -eq 3 -a -z "$pwuser" -a -n "$3" || usage
        pwuser="$2"; pwvalue="$3"; shift; shift;;
    --help) usage 0;;
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

if test -n "$pwuser"; then
    pwuser="`echo "+$pwuser" | sed -e 's,^.,,' | sql_quote`"
    if test -z "$pwvalue"; then
        echo "select concat(email, ',', if(substr(password,1,1)=' ','<HASH>',password)) from ContactInfo where email like '$pwuser' and disabled=0" | eval "$MYSQL $myargs -N $FLAGS $dbname"
    else
        pwvalue="`echo "+$pwvalue" | sed -e 's,^.,,' | sql_quote`"
        echo "update ContactInfo set password='$pwvalue' where email='$pwuser'" | eval "$MYSQL $myargs -N $FLAGS $dbname"
    fi
else
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    eval "$MYSQL $myargs $FLAGS $dbname"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
