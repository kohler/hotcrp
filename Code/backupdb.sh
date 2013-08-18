#! /bin/sh
##
## Back up the database, writing it to standard output
##

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
export PROGDIR=`echo "$0" | sed 's,[^/]*$,,'`
test -z "$PROGDIR" && PROGDIR=.
. $PROGDIR/dbhelper.sh

export PROG=$0
export FLAGS=
structure=false
pc=false
options_file=options.inc
while [ $# -gt 0 ]; do
    case "$1" in
    --structure|--schema) structure=true;;
    --pc) pc=true;;
    -*)	FLAGS="$FLAGS $1";;
    *)	echo "Usage: $PROG [--schema] [--pc] [MYSQL-OPTIONS]" 1>&2; exit 1;;
    esac
    shift
done

if [ ! -r "${PROGDIR}${options_file}" ]; then
    echo "backupdb.sh: Can't read ${PROGDIR}${options_file}! Is this a CRP directory?" 1>&2
    exit 1
fi

dbname="`getdbopt dbName 2>/dev/null`"
dbuser="`getdbopt dbUser 2>/dev/null`"
dbpass="`getdbopt dbPassword 2>/dev/null`"
test -z "$dbname" -o -z "$dbuser" -o -z "$dbpass" && { echo "backupdb.sh: Cannot extract database run options from ${options_file}!" 1>&2; exit 1; }

### Test mysqldump binary
check_mysqlish MYSQL mysql
check_mysqlish MYSQLDUMP mysqldump
set_myargs "$dbuser" "$dbpass"

echo + $MYSQLDUMP $myargs_redacted $FLAGS $dbname 1>&2
if $structure; then
    eval "$MYSQLDUMP $myargs $FLAGS $dbname" | sed '/^LOCK/d
/^INSERT/d
/^UNLOCK/d
/^\/\*/d
/^)/s/AUTO_INCREMENT=[0-9]* //
/^--$/N
/^--.*-- Dumping data/N
/^--.*-- Dumping data.*--/d
/^-- Dump/d'
else
    if $pc; then
	eval "$MYSQLDUMP $FLAGS $myargs $dbname --where='(roles & 7) != 0' ContactInfo"
	pcs=`echo 'select group_concat(contactId) from ContactInfo where (roles & 7) != 0' | eval "$MYSQL $myargs $FLAGS -N $dbname"`
	eval "$MYSQLDUMP $myargs $FLAGS --where='contactId in ($pcs)' $dbname ContactAddress"
	eval "$MYSQLDUMP $myargs $FLAGS $dbname PCMember Chair ChairAssistant Settings OptionType ChairTag TopicArea ReviewFormField ReviewFormOptions"
    else
	eval "$MYSQLDUMP $myargs $FLAGS $dbname"
    fi
    echo
    echo "--"
    echo "-- Force HotCRP to invalidate server caches"
    echo "--"
    echo "INSERT INTO "'`Settings` (`name`,`value`)'" VALUES ('frombackup',UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=greatest(value,values(value));"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
