#! /bin/sh
## backupdb.sh -- HotCRP database backup to stdout
## HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C CONFNAME=
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh

export PROG=$0
export FLAGS=
structure=false
pc=false
gzip=false
max_allowed_packet=1000M
output=
options_file=
while [ $# -gt 0 ]; do
    shift=1
    case "$1" in
    --structure|--schema) structure=true;;
    --pc) pc=true;;
    -z|--g|--gz|--gzi|--gzip) gzip=true;;
    -c|--co|--con|--conf|--confi|--config|-c*|--co=*|--con=*|--conf=*|--confi=*|--config=*)
        parse_common_argument "$@";;
    -n|--n|--na|--nam|--name|-n*|--n=*|--na=*|--nam=*|--name=*)
        parse_common_argument "$@";;
    --max_allowed_packet=*)
        max_allowed_packet="`echo "$1" | sed 's/^[^=]*=//'`";;
    -*) FLAGS="$FLAGS $1";;
    *)  if [ -z "$output" ]; then
            output="$1"
        else
            echo "Usage: $PROG [-c CONFIGFILE] [-n CONFNAME] [--schema] [--pc] [-z] [MYSQL-OPTIONS] [OUTPUT]" 1>&2; exit 1
        fi;;
    esac
    shift $shift
done

if ! findoptions >/dev/null; then
    echo "backupdb.sh: Can't read options file! Is this a CRP directory?" 1>&2
    exit 1
fi

get_dboptions backupdb.sh
FLAGS="$FLAGS --max_allowed_packet=$max_allowed_packet"

### Test mysqldump binary
check_mysqlish MYSQL mysql
check_mysqlish MYSQLDUMP mysqldump
set_myargs "$dbuser" "$dbpass"

if $gzip && test -n "$output"; then
    echotail="$dbname | gzip > $output"
    tailcmd="gzip >'$output'"
elif $gzip; then
    echotail="$dbname | gzip"
    tailcmd=gzip
elif test -n "$output"; then
    echotail="$dbname > $output"
    tailcmd="cat >'$output'"
else
    echotail="$dbname"
    tailcmd=cat
fi

database_dump () {
    if $pc; then
        eval "$MYSQLDUMP $FLAGS $myargs $dbname --where='(roles & 7) != 0' ContactInfo"
        pcs=`echo 'select group_concat(contactId) from ContactInfo where (roles & 7) != 0' | eval "$MYSQL $myargs $FLAGS -N $dbname"`
        eval "$MYSQLDUMP $myargs $FLAGS --where='contactId in ($pcs)' $dbname ContactAddress"
        eval "$MYSQLDUMP $myargs $FLAGS $dbname PCMember Chair ChairAssistant Settings ChairTag TopicArea"
    else
        eval "$MYSQLDUMP $myargs $FLAGS $dbname"
    fi
    echo
    echo "--"
    echo "-- Force HotCRP to invalidate server caches"
    echo "--"
    echo "INSERT INTO "'`Settings` (`name`,`value`)'" VALUES ('frombackup',UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=greatest(value,values(value));"
}

get_sversion () {
    cat
    echo "select concat('insert into Settings (name, value) values (''', name, ''', ', value, ');') from Settings where name='sversion' or name='allowPaperOption';" | eval "$MYSQL $myargs $FLAGS -N $dbname"
}

echo + $MYSQLDUMP $myargs_redacted $FLAGS $echotail 1>&2
if $structure; then
    eval "$MYSQLDUMP $myargs $FLAGS $dbname" | sed '/^LOCK/d
/^INSERT/d
/^UNLOCK/d
/^\/\*/d
/^)/s/AUTO_INCREMENT=[0-9]* //
/^--$/N
/^--.*-- Dumping data/N
/^--.*-- Dumping data.*--/d
/^-- Dump/d' | get_sversion | eval "$tailcmd"
else
    database_dump | eval "$tailcmd"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
