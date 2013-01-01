#! /bin/sh
## createdb.sh -- HotCRP database setup program
## HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

## Create the database. The assumption is that database
## name and user name and password are all the same

help () {
    echo "Code/createdb.sh performs MySQL database setup for HotCRP."
    echo
    echo "Usage: Code/createdb.sh [MYSQLOPTIONS] [DBNAME]"
    echo
    echo "MYSQLOPTIONS are sent to mysql and mysqladmin."
    echo "Common options include '--user=ADMIN_USERNAME' and '--password=ADMIN_PASSWORD'"
    echo "to select a database admin user able to create new tables."
    exit 0
}

echo_n () {
	# suns can't echo -n, and Mac OS X can't echo "x\c"
	echo "$@" | tr -d '
'
}

export PROG=$0
export FLAGS=""
export FLAGS_NOP=""
export ECHOFLAGS=""
DBNAME=""
needpassword=false
force=false
while [ $# -gt 0 ]; do
    case "$1" in
    -p|--pas|--pass|--passw|--passwo|--passwor|--password)
	needpassword=true; shift;;
    -u|--us|--use|--user)
	FLAGS="$FLAGS '$1'"; ECHOFLAGS="$ECHOFLAGS '$1'"; shift
	[ $# -gt 0 ] && { FLAGS="$FLAGS '$1'"; ECHOFLAGS="$ECHOFLAGS '$1'"; shift; }
	;;
    -u*|--us=*|--use=*|--user=*)
	FLAGS="$FLAGS '$1'"; ECHOFLAGS="$ECHOFLAGS '$1'"; shift;;
    -p*)
	FLAGS="$FLAGS '$1'"; ECHOFLAGS="$ECHOFLAGS -p'<REDACTED>'"; shift;;
    --pas=*|--pass=*|--passw=*|--passwo=*|--passwor=*|--password=*)
	FLAGS="$FLAGS '$1'"; ECHOFLAGS="$ECHOFLAGS `echo '$1' | sed 's/=.*//'`='<REDACTED>'"; shift;;
    --he|--hel|--help)
	help;;
    --force)
        force=true; shift;;
    -*)
	FLAGS="$FLAGS '$1'"; FLAGS_NOP="$FLAGS_NOP '$1'"; ECHOFLAGS="$ECHOFLAGS '$1'"; shift;;
    *)
	if [ -z "$DBNAME" ]; then
	    DBNAME="$1"; shift
	else
	    echo "Usage: $PROG [MYSQLOPTIONS]" 1>&2
	    echo "Type Code/createdb.sh --help for more information." 1>&2
	    exit 1
	fi;;
    esac
done

if $needpassword; then
    echo_n "Enter MySQL password: "
    stty -echo; trap "stty echo; exit 1" INT
    read PASSWORD
    stty echo; trap - INT
    echo
    FLAGS="$FLAGS -p'$PASSWORD'"; ECHOFLAGS="$ECHOFLAGS -p'<REDACTED>'"
fi

### Test mysql binary
if test -z "$MYSQL"; then
    MYSQL=mysql
    ! $MYSQL --version >/dev/null 2>&1 && mysql5 --version >/dev/null 2>&1 && MYSQL=mysql5
fi
if test -z "$MYSQLADMIN"; then
    MYSQLADMIN=mysqladmin
    ! $MYSQLADMIN --version >/dev/null 2>&1 && mysqladmin5 --version >/dev/null 2>&1 && MYSQLADMIN=mysqladmin5
fi

if ! $MYSQL --version >/dev/null 2>&1; then
    echo "The $MYSQL binary doesn't appear to work."
    echo "Set the MYSQL environment variable and try again."
    exit 1
fi
if ! $MYSQLADMIN --version >/dev/null 2>&1; then
    echo "The $MYSQLADMIN binary doesn't appear to work." 1>&2
    echo "Set the MYSQLADMIN environment variable and try again." 1>&2
    exit 1
fi
if ! (echo 'show databases;' | eval $MYSQL $FLAGS >/dev/null); then
    echo "Could not run $MYSQL $ECHOFLAGS. Did you enter the right password?" 1>&2
    exit 1
fi
grants=`echo 'show grants;' | eval $MYSQL $FLAGS | grep -i -e create -e all`
if ! $force && test -z "$grants"; then
    echo "This MySQL account does not appear to have the privilege to create databases." 1>&2
    echo "Use '--user=USER' and '--password=PASSWORD' options to specify another user." 1>&2
    echo "If you think this message is in error, run '$PROG --force'" 1>&2
    echo "to try again." 1>&2
    exit 1
fi


PROGDIR=`echo "$0" | sed 's,[^/]*$,,'`


echo "This will create the database for your conference."
echo "The database name and database user are set to the same thing."
echo "Access is allowed only from the local host."
echo

echo_dbname () {
    cat <<__EOF__
$DBNAME
__EOF__
}

while true; do
    echo_n "Enter database name (NO SPACES): "
    if [ -z "$DBNAME" ]; then
	read -r DBNAME
    else
	echo "$DBNAME"
    fi

    x=`echo_dbname | tr -d a-zA-Z0-9_.-`
    c=`echo_dbname | wc -c`
    if test -z "$DBNAME"; then
	echo 1>&2
	echo "You must enter a database name." 1>&2
    elif test -n "$x"; then
	echo 1>&2
	echo "The database name must only contain characters in [-.a-zA-Z0-9_]." 1>&2
    elif test "$c" -gt 16; then
	echo 1>&2
	echo "The database name can be at most 16 characters long." 1>&2
    else
	break
    fi
    DBNAME=
done


echo_dbpass () {
    cat <<__EOF__
$DBPASS
__EOF__
}

generate_random_ints () {
    random="`openssl rand 16 2>/dev/null`"
    test -z "$random" && random="`head -c 16 /dev/random 2>/dev/null`"
    test -z "$random" && random="`head -c 16 /dev/urandom 2>/dev/null`"
    echo "$random" | awk '
BEGIN { for (i = 0; i < 256; ++i) { ord[sprintf("%c", i)] = i; } }
{ for (i = 0; i < length($0); ++i) { printf("%d\n", ord[substr($0, i, 1)]); } }'
    awk 'BEGIN { for (i = 0; i < 256; ++i) { printf("%d\n", rand() * 256); } }' < /dev/null
}

generate_password () {
    awk 'BEGIN {
    npwchars = split("a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =", pwchars, " ");
    pw = "";
}
{ pw = pw pwchars[($0 % npwchars) + 1]; if (length(pw) >= 16) { printf("%s\n", pw); exit; } }'
}

default_dbpass=`generate_random_ints | generate_password`
while true; do
    echo_n "Enter password for mysql user $DBNAME [default $default_dbpass]: "
    stty -echo; trap "stty echo; exit 1" INT
    read -r DBPASS
    stty echo; trap - INT
    if [ -z "`echo_dbpass`" ]; then DBPASS=$default_dbpass; fi
    x=`echo_dbpass | tr -d -c '\000'"'"`
    if test -z "$x" >/dev/null; then break; fi
    echo 1>&2
    echo "The database password can't contain single quotes or null characters." 1>&2
done


sql_dbpass () {
    echo_dbpass | sed -e 's,\([\\"'"'"']\),\\\1,g' | sed -e 's,,\\Z,g'
}

php_dbpass () {
    echo_dbpass | sed -e 's,\([\\"'"'"']\),\\\1,g'
}


echo
echo "Creating database."
if [ -z "$FLAGS" ]; then
    echo "This should work if you are root and haven't changed the default mysql"
    echo "administrative password.  If you have changed the password, you will need to"
    echo "run '$PROG -p' or '$PROG -pPASSWD' (no space)."
fi
echo "+ echo 'show databases;' | $MYSQL $ECHOFLAGS | grep $DBNAME"
echo 'show databases;' | eval $MYSQL $FLAGS >/dev/null || exit 1
echo 'show databases;' | eval $MYSQL $FLAGS | grep $DBNAME >/dev/null 2>&1
dbexists="$?"
echo "+ echo 'select User from user group by User;' | $MYSQL $ECHOFLAGS mysql | grep $DBNAME"
echo 'select User from user group by User;' | eval $MYSQL $FLAGS mysql >/dev/null || exit 1
echo 'select User from user group by User;' | eval $MYSQL $FLAGS mysql | grep '^'$DBNAME'$' >/dev/null 2>&1
userexists="$?"
createdbuser=y
if [ "$dbexists" = 0 -o "$userexists" = 0 ]; then
    echo
    test "$dbexists" = 0 && echo "A database named '$DBNAME' already exists!"
    test "$userexists" = 0 && echo "A user named '$DBNAME' already exists!"
    echo "Do you want to delete and recreate the database and/or user?"
    while true; do
	echo_n "Delete and recreate [Y], continue [n], or quit [q]? "
	read createdbuser
	expr "$createdbuser" : "[ynqYNQ].*" >/dev/null && break
	test -z "$createdbuser" && break
    done
    expr "$createdbuser" : "[qQ].*" >/dev/null && echo "Exiting..." && exit 0
    expr "$createdbuser" : "[nN].*" >/dev/null || createdbuser=y

    if [ "$createdbuser" = y -a "$dbexists" = 0 ]; then
	echo "+ $MYSQLADMIN $ECHOFLAGS -f drop $DBNAME"
	eval $MYSQLADMIN $FLAGS -f drop $DBNAME || exit 1
    fi
fi
if [ "$createdbuser" = y ]; then
    echo
    echo "Creating $DBNAME database."
    echo "+ $MYSQLADMIN $ECHOFLAGS --default-character-set=utf8 create $DBNAME"
    eval $MYSQLADMIN $FLAGS --default-character-set=utf8 create $DBNAME || exit 1

    echo "Creating $DBNAME user and password."
    eval $MYSQL $FLAGS mysql <<__EOF__ || exit 1
DELETE FROM user WHERE user='$DBNAME';
INSERT INTO user SET
    Host='127.0.0.1',
    User='$DBNAME',
    Password=PASSWORD('`sql_dbpass`');

INSERT INTO user SET
    Host='localhost.localdomain',
    User='$DBNAME',
    Password=PASSWORD('`sql_dbpass`');

INSERT INTO user SET
    Host='localhost',
    User='$DBNAME',
    Password=PASSWORD('`sql_dbpass`');

DELETE FROM db WHERE db='$DBNAME';
INSERT INTO db SET
    Host='127.0.0.1',
    Db='$DBNAME',
    User='$DBNAME',
    Select_priv='Y',
    Insert_priv='Y',
    Update_priv='Y',
    Delete_priv='Y',
    Create_priv='Y',
    Drop_priv='Y',
    Index_priv='Y',
    References_priv='Y',
    Alter_priv='Y',
    Lock_tables_priv='Y',
    Create_tmp_table_priv='Y';

INSERT INTO db SET
    Host='localhost.localdomain',
    Db='$DBNAME',
    User='$DBNAME',
    Select_priv='Y',
    Insert_priv='Y',
    Update_priv='Y',
    Delete_priv='Y',
    Create_priv='Y',
    Drop_priv='Y',
    Index_priv='Y',
    References_priv='Y',
    Alter_priv='Y',
    Lock_tables_priv='Y',
    Create_tmp_table_priv='Y';

INSERT INTO db SET
    Host='localhost',
    Db='$DBNAME',
    User='$DBNAME',
    Select_priv='Y',
    Insert_priv='Y',
    Update_priv='Y',
    Delete_priv='Y',
    Create_priv='Y',
    Drop_priv='Y',
    Index_priv='Y',
    References_priv='Y',
    Alter_priv='Y',
    Lock_tables_priv='Y',
    Create_tmp_table_priv='Y';

__EOF__
##

    echo "Reloading grant tables."
    eval $MYSQLADMIN $FLAGS reload || exit 1

    if [ ! -r "${PROGDIR}schema.sql" ]; then
	echo "Can't read schema.sql!  You'll have to populate the database yourself."
	exit 1
    fi
else
    echo
    echo "Continuing with existing database and user."
fi

##
## Populate the database schema
##
echo
echo "Now, we will populate the database with the schema."
echo "However, if you need to restore from a backup you don't want to populate."
echo "If the preceeding steps worked, you won't need to enter a password."
while true; do
    echo_n "Populate database [Y/n]? "
    read populatedb
    expr "$populatedb" : "[ynqYNQ].*" >/dev/null && break
    test -z "$populatedb" && break
done
expr "$populatedb" : "[qQ].*" >/dev/null && echo "Exiting..." && exit 0
expr "$populatedb" : "[nN].*" >/dev/null || populatedb=y
echo

if [ "$populatedb" = y ]; then
    echo "Populating database."
    ECHOFLAGS_SCHEMA="-u $DBNAME -p'<REDACTED>' $FLAGS_NOP"
    FLAGS_SCHEMA="-u $DBNAME -p'`echo_dbpass`' $FLAGS_NOP"
    echo "+ $MYSQL $ECHOFLAGS_SCHEMA $DBNAME < ${PROGDIR}schema.sql"
    eval $MYSQL "$FLAGS_SCHEMA" $DBNAME < ${PROGDIR}schema.sql || exit 1
fi

##
## Create options.inc
##

create_options_inc () {
    awk 'BEGIN { p = 1 }
/^\$Opt\[.dbName.\]/ { p = 0 }
{ if (p) print }' < ${PROGDIR}distoptions.inc
    cat <<__EOF__
\$Opt["dbName"] = "$DBNAME";
\$Opt["dbPassword"] = "`php_dbpass`";
__EOF__
    awk 'BEGIN { p = 0 }
/^\$Opt\[.shortName.\]/ { p = 1 }
{ if (p) print }' < ${PROGDIR}distoptions.inc
}

if [ -r "${PROGDIR}options.inc" ]; then
    echo
    echo "*** Your Code/options.inc file already exists."
    echo "*** Edit it to use the database name, username, and password you chose."
    echo
elif [ -r "${PROGDIR}distoptions.inc" ]; then
    echo "Creating Code/options.inc..."
    create_options_inc > ${PROGDIR}options.inc
    if [ -n "$SUDO_USER" ]; then
	echo chown $SUDO_USER ${PROGDIR}options.inc
	chown $SUDO_USER ${PROGDIR}options.inc
    fi
    chmod o-rwx ${PROGDIR}options.inc
fi
