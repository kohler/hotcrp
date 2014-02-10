#! /bin/sh
## runsql.sh -- HotCRP database shell
## HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh
export PROG=$0

usage () {
    if [ -z "$1" ]; then status=1; else status=$1; fi
    echo "Usage: $PROG [-c CONFIGFILE] [MYSQL-OPTIONS]
       $PROG --show-password EMAIL
       $PROG --set-password EMAIL PASSWORD
       $PROG --create-user EMAIL [COLUMN=VALUE...]" |
       if [ $status = 0 ]; then cat; else cat 1>&2; fi
    exit $status
}

export FLAGS=
mode=
makeuser=
makeusercols=
makeuservals=
pwuser=
pwvalue=
cmdlinequery=
options_file=
while [ $# -gt 0 ]; do
    case "$1" in
    --show-password=*)
        test -z "$mode" || usage
	pwuser="`echo "+$1" | sed 's/^[^=]*=//'`"; mode=showpw;;
    --show-password)
	test "$#" -gt 1 -a -z "$mode" || usage
	pwuser="$2"; shift; mode=showpw;;
    --set-password)
        test "$#" -eq 3 -a -z "$mode" || usage
        pwuser="$2"; pwvalue="$3"; shift; shift; mode=setpw;;
    -c|--co|--con|--conf|--confi|--config)
        test "$#" -gt 1 -a -z "$options_file" || usage
        options_file="$2"; shift;;
    -c*)
        test -z "$options_file" || usage
        options_file="`echo "$1" | sed 's/^-c//'`";;
    --co=*|--con=*|--conf=*|--confi=*|--config=*)
        test -z "$options_file" || usage
        options_file="`echo "$1" | sed 's/^[^=]*=//'`";;
    --create-user)
        test "$#" -gt 1 -a -z "$mode" || usage
        makeuser="$2"; mode=makeuser; shift;;
    --help) usage 0;;
    -*)
        if [ "$mode" = cmdlinequery ]; then
            cmdlinequery="$cmdlinequery $1"
        else
            FLAGS="$FLAGS $1"
        fi;;
    *)
        if [ "$mode" = makeuser ] && expr "$1" : "[a-zA-Z0-9_]*=" >/dev/null; then
            colname=`echo "$1" | sed 's/=.*//'`
            collen=`echo "$colname" | wc -c`
            collen=`expr $collen + 1`
            colvalue=`echo "$1" | tail -c +$collen`
            makeusercols="$makeusercols,$colname"
            makeuservals="$makeuservals,'`echo "$colvalue" | sql_quote`'"
        elif [ "$mode" = "" ]; then
            mode=cmdlinequery
            cmdlinequery="$1"
        elif [ "$mode" = cmdlinequery ]; then
            cmdlinequery="$cmdlinequery $1"
        else usage; fi
    esac
    shift
done

if ! findoptions >/dev/null; then
    echo "runsql.sh: Can't read options file! Is this a CRP directory?" 1>&2
    exit 1
fi

dbname="`getdbopt dbName 2>/dev/null`"
dbuser="`getdbopt dbUser 2>/dev/null`"
dbpass="`getdbopt dbPassword 2>/dev/null`"
test -z "$dbname" -o -z "$dbuser" -o -z "$dbpass" && { echo "runsql.sh: Cannot extract database run options from `findoptions`!" 1>&2; exit 1; }

check_mysqlish MYSQL mysql
set_myargs "$dbuser" "$dbpass"
exitval=0

if test -n "$pwuser"; then
    pwuser="`echo "+$pwuser" | sed -e 's,^.,,' | sql_quote`"
    if test "$mode" = showpw; then
        echo "select concat(email, ',', if(substr(password,1,1)=' ','<HASH>',password)) from ContactInfo where email like '$pwuser' and disabled=0" | eval "$MYSQL $myargs -N $FLAGS $dbname"
    else
        pwvalue="`echo "+$pwvalue" | sed -e 's,^.,,' | sql_quote`"
        query="update ContactInfo set password='$pwvalue' where email='$pwuser'; select row_count()"
        nupdates="`echo "$query" | eval "$MYSQL $myargs -N $FLAGS $dbname"`"
        if [ $nupdates = 0 ]; then
            echo "no such user" 1>&2; exitval=1
        elif [ $nupdates != 1 ]; then
            echo "$nupdates users updated" 1>&2
        fi
    fi
elif test "$mode" = makeuser; then
    echo "insert into ContactInfo (email,password$makeusercols) values ('`echo "$makeuser" | sql_quote`',''$makeuservals)" | eval "$MYSQL $myargs -N $FLAGS $dbname"
elif test "$mode" = cmdlinequery; then
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    echo "$cmdlinequery" | eval "$MYSQL $myargs $FLAGS $dbname"
else
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    eval "$MYSQL $myargs $FLAGS $dbname"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
exit $exitval
