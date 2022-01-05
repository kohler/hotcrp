#! /bin/sh
## restoredb.sh -- HotCRP database restore from backup
## Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C CONFNAME=
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh

usage () {
    echo "Usage: $PROG [-c CONFIGFILE] [-n CONFNAME] [MYSQL-OPTIONS] BACKUPFILE" 1>&2
    exit 1
}

export PROG=$0
export FLAGS=""
max_allowed_packet=1000M
input=
inputfilter=
options_file=
while [ $# -gt 0 ]; do
    shift=1
    case "$1" in
    -c|--co|--con|--conf|--confi|--config|-c*|--co=*|--con=*|--conf=*|--confi=*|--config=*)
        parse_common_argument "$@";;
    -n|--n|--na|--nam|--name|-n*|--n=*|--na=*|--nam=*|--name=*)
        parse_common_argument "$@";;
    --no-password-f|--no-password-fi|--no-password-fil|--no-password-file)
        parse_common_argument "$@";;
    --max_allowed_packet=*)
        max_allowed_packet="`echo "$1" | sed 's/^[^=]*=//'`";;
    -*) FLAGS="$FLAGS $1";;
    *)  if [ -z "$input" ]; then input="$1"; else usage; fi;;
    esac
    shift $shift
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

get_dboptions restoredb.sh
FLAGS="$FLAGS --max_allowed_packet=$max_allowed_packet"

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
