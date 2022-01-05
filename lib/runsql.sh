#! /bin/sh
## runsql.sh -- HotCRP database shell
## Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C CONFNAME=
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh
export PROG=$0

usage () {
    if [ -z "$1" ]; then status=1; else status=$1; fi
    echo "Usage: $PROG [-n CONFNAME | -c CONFIGFILE] [MYSQL-OPTIONS]
       $PROG --create-user EMAIL [COLUMN=VALUE...]" |
       if [ $status = 0 ]; then cat; else cat 1>&2; fi
    exit $status
}

export FLAGS=
mode=
makeuser=
makeusercols=
makeuservals=
makeuserpassword=true
cmdlinequery=
options_file=
while [ $# -gt 0 ]; do
    shift=1
    case "$1" in
    --create-user)
        test "$#" -gt 1 -a -z "$mode" || usage
        makeuser="$2"; mode=makeuser; shift;;
    --show-opt=*|--show-option=*)
        test -z "$mode" || usage
        optname="`echo "+$1" | sed 's/^[^=]*=//'`"; mode=showopt;;
    --show-opt|--show-option)
        test "$#" -gt 1 -a -z "$mode" || usage
        optname="$2"; shift; mode=showopt;;
    --json-dbopt)
        test -z "$mode" || usage
        mode=json_dbopt;;
    -c|--co|--con|--conf|--confi|--config|-c*|--co=*|--con=*|--conf=*|--confi=*|--config=*)
        parse_common_argument "$@";;
    -n|--n|--na|--nam|--name|-n*|--n=*|--na=*|--nam=*|--name=*)
        parse_common_argument "$@";;
    --no-password-f|--no-password-fi|--no-password-fil|--no-password-file)
        parse_common_argument "$@";;
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
            test "$colname" = password && makeuserpassword=false
        elif [ "$mode" = "" ]; then
            mode=cmdlinequery
            cmdlinequery="$1"
        elif [ "$mode" = cmdlinequery ]; then
            cmdlinequery="$cmdlinequery $1"
        else usage; fi
    esac
    shift $shift
done

if ! findoptions >/dev/null; then
    echo "runsql.sh: No options file" 1>&2
    exit 1
fi

get_dboptions runsql.sh

if test "$mode" = json_dbopt; then
    eval "x0=$dbname;x1=$dbuser;x2=$dbpass;x3=$dbhost"
    echo_n '{"dbName":'; echo_n "$x0" | json_quote
    echo_n ',"dbUser":'; echo_n "$x1" | json_quote
    echo_n ',"dbPassword":'; echo_n "$x2" | json_quote
    echo_n ',"dbHost":'; if [ -z "$x3" ]; then echo_n 'null'; else echo_n "$x3" | json_quote; fi
    echo '}'
    exit
fi

check_mysqlish MYSQL mysql
set_myargs "$dbuser" "$dbpass"
exitval=0

if test "$mode" = showopt; then
    if test -n "`echo "$optname" | tr -d A-Za-z0-9._:-`"; then
        echo "bad option name" 1>&2; exitval=1
    else
        opt="`getdbopt "$optname" 2>/dev/null`"
        optopt="`echo "select data from Settings where name='opt.$optname'" | eval "$MYSQL $myargs -N $FLAGS $dbname"`"
        if test -n "$optopt"; then eval "echo $optopt"; else eval "echo $opt"; fi
    fi
elif test "$mode" = makeuser; then
    if $makeuserpassword; then
        makeusercols="$makeusercols,password"
        makeuservals="$makeuservals,''"
    fi
    echo "insert into ContactInfo (email$makeusercols) values ('`echo "$makeuser" | sql_quote`'$makeuservals)" | eval "$MYSQL $myargs -N $FLAGS $dbname"
elif test "$mode" = cmdlinequery; then
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    echo "$cmdlinequery" | eval "$MYSQL $myargs $FLAGS $dbname"
else
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    eval "$MYSQL $myargs $FLAGS $dbname"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
exit $exitval
