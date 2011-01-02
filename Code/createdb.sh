#! /bin/sh
## createdb.sh -- HotCRP database setup program
## HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

## Create the database. The assumption is that database
## name and user name and password are all the same

help () {
    echo "Code/createdb.sh performs MySQL database setup for HotCRP."
    echo
    echo "Usage: Code/createdb.sh [MYSQLOPTIONS] [DBNAME]"
    echo
    echo "MYSQLOPTIONS are sent to mysql and mysqladmin."
    echo "Common options include '--user ADMIN_USERNAME' and '--password ADMIN_PASSWORD'"
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
DBNAME=""
while [ $# -gt 0 ]; do
    case "$1" in
    -p|--pas|--pass|--passw|--passwo|--passwor|--password)
	FLAGS="$FLAGS '$1'"; shift;;
    -u|--us|--use|--user)
	FLAGS="$FLAGS '$1'"; shift
	[ $# -gt 0 ] && { FLAGS="$FLAGS '$1'"; shift; }
	;;
    -u*|--us=*|--use=*|--user=*|-p*|--pas=*|--pass=*|--passw=*|--passwo=*|--passwor=*|--password=*)
	FLAGS="$FLAGS '$1'"; shift;;
    --he|--hel|--help)
	help;;
    -*)
	FLAGS="$FLAGS '$1'"; FLAGS_NOP="$FLAGS_NOP '$1'"; shift;;
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
    if test -z "$x" -a -n "$DBNAME"; then break; fi
    echo 1>&2
    echo "The database name must only contain characters in [-.a-zA-Z0-9_]." 1>&2
    DBNAME=
done


echo_dbpass () {
    cat <<__EOF__
$DBPASS
__EOF__
}

while true; do
    echo_n "Enter password for mysql user $DBNAME [default $DBNAME]: "
    read -r DBPASS
    if [ -z "`echo_dbpass`" ]; then DBPASS=$DBNAME; fi
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
echo "+ echo 'show databases;' | mysql $FLAGS | grep $DBNAME"
echo 'show databases;' | eval mysql $FLAGS >/dev/null || exit 1
echo 'show databases;' | eval mysql $FLAGS | grep $DBNAME >/dev/null 2>&1
dbexists="$?"
echo "+ echo 'select User from user group by User;' | mysql $FLAGS mysql | grep $DBNAME"
echo 'select User from user group by User;' | eval mysql $FLAGS mysql >/dev/null || exit 1
echo 'select User from user group by User;' | eval mysql $FLAGS mysql | grep '^'$DBNAME'$' >/dev/null 2>&1
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
	echo "+ mysqladmin $FLAGS -f drop $DBNAME"
	eval mysqladmin $FLAGS -f drop $DBNAME || exit 1
    fi
fi
if [ "$createdbuser" = y ]; then
    echo
    echo "Creating $DBNAME database."
    echo "+ mysqladmin $FLAGS --default-character-set=utf8 create $DBNAME"
    eval mysqladmin $FLAGS --default-character-set=utf8 create $DBNAME || exit 1

    echo "Creating $DBNAME user and password."
    eval mysql $FLAGS mysql <<__EOF__ || exit 1
DELETE FROM user WHERE user='$DBNAME';
INSERT INTO user SET
    Host='127.0.0.1',
    User='$DBNAME',
    Password=PASSWORD('`sql_dbpass`'),
    Select_priv='Y',
    Insert_priv='Y',
    Update_priv='Y',
    Delete_priv='Y',
    Create_priv='Y',
    Drop_priv='Y',
    Index_priv='Y',
    Alter_priv='Y',
    Lock_tables_priv='Y',
    Create_tmp_table_priv='Y';

INSERT INTO user SET
    Host='localhost.localdomain',
    User='$DBNAME',
    Password=PASSWORD('`sql_dbpass`'),
    Select_priv='Y',
    Insert_priv='Y',
    Update_priv='Y',
    Delete_priv='Y',
    Create_priv='Y',
    Drop_priv='Y',
    Index_priv='Y',
    Alter_priv='Y',
    Lock_tables_priv='Y',
    Create_tmp_table_priv='Y';

INSERT INTO user SET
    Host='localhost',
    User='$DBNAME',
    Password=PASSWORD('`sql_dbpass`'),
    Select_priv='Y',
    Insert_priv='Y',
    Update_priv='Y',
    Delete_priv='Y',
    Create_priv='Y',
    Drop_priv='Y',
    Index_priv='Y',
    Alter_priv='Y',
    Lock_tables_priv='Y',
    Create_tmp_table_priv='Y';

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
    eval mysqladmin $FLAGS reload || exit 1

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
    FLAGS_SCHEMA="-u $DBNAME -p'`echo_dbpass`' $FLAGS_NOP"
    echo mysql "$FLAGS_SCHEMA" $DBNAME "<" ${PROGDIR}schema.sql
    eval mysql "$FLAGS_SCHEMA" $DBNAME < ${PROGDIR}schema.sql || exit 1
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
fi
