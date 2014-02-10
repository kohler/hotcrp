#! /bin/sh
## restoredb.sh -- HotCRP database restore from backup
## HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh

usage () {
    echo "Usage: $PROG [-c CONFIGFILE] [MYSQL-OPTIONS] BACKUPFILE" 1>&2
    exit 1
}

export PROG=$0
export FLAGS=""
input=
inputfilter=
options_file=
while [ $# -gt 0 ]; do
    case "$1" in
    -c|--co|--con|--conf|--confi|--config)
        test "$#" -gt 1 -a -z "$options_file" || usage
        options_file="$2"; shift;;
    -c*)
        test -z "$options_file" || usage
        options_file="`echo "$1" | sed 's/^-c//'`";;
    --co=*|--con=*|--conf=*|--confi=*|--config=*)
        test -z "$options_file" || usage
        options_file="`echo "$1" | sed 's/^[^=]*=//'`";;
    -*)	FLAGS="$FLAGS $1";;
    *)	if [ -z "$input" ]; then input="$1"; else usage; fi;;
    esac
    shift
done

if ! findoptions >/dev/null; then
    echo "restoredb.sh: Can't read options file! Is this a CRP directory?" 1>&2
    exit 1
elif [ -n "$input" ]; then
    inputhead=`head -c 3 "$input" | perl -pe 's/(.)/sprintf("%02x", ord($1))/ge'`
    if [ "$inputhead" = 8b1f08 -o "$inputhead" = 1f8b08 ]; then
        if [ -x /usr/bin/gzcat -o -x /bin/gzcat ]; then
            inputfilter=gzcat
        else
            inputfilter=zcat
        fi
    elif [ "$inputhead" = "425a68" ]; then
        inputfilter=bzcat
    fi
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
elif test -n "$inputfilter"; then
    echo + $inputfilter "$input" "|" $MYSQL $myargs_redacted $FLAGS $dbname 1>&2
    $inputfilter "$input" | eval "$MYSQL $myargs $FLAGS $dbname"
else
    echo + $MYSQL $myargs_redacted $FLAGS $dbname "<" "$input" 1>&2
    eval "$MYSQL $myargs $FLAGS $dbname" < "$input"
fi
