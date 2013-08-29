#! /bin/sh
## createdb.sh -- HotCRP database setup
## HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
export PROGDIR=`echo "$0" | sed 's,[^/]*$,,'`
test -z "$PROGDIR" && PROGDIR=.
. $PROGDIR/dbhelper.sh

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

PROG=$0
FLAGS=""
DBUSER=""
DBNAME=""
PASSWORD=""
distoptions_file=distoptions.inc
options_file=options.inc
needpassword=false
force=false
while [ $# -gt 0 ]; do
    case "$1" in
    -p|--pas|--pass|--passw|--passwo|--passwor|--password)
	needpassword=true; shift;;
    -u|--us|--use|--user)
        DBUSER="$2"; shift 2;;
    -u*)
        DBUSER="`echo "$1" | sed s/^-u//`"; shift;;
    --u=*|--us=*|--use=*|--user=*)
	DBUSER="`echo "$1" | sed 's/^[^=]*=//'`"; shift;;
    -p*)
        PASSWORD="`echo "$1" | sed s/^-p//`"; shift;;
    --pas=*|--pass=*|--passw=*|--passwo=*|--passwor=*|--password=*)
        PASSWORD="`echo "$1" | sed 's/^[^=]*=//'`"; shift;;
    --he|--hel|--help)
	help;;
    --force)
        force=true; shift;;
    -*)
	FLAGS="$FLAGS '$1'"; shift;;
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

### Test mysql binary
check_mysqlish MYSQL mysql
check_mysqlish MYSQLADMIN mysqladmin

# attempt to secure password handling
# (It is considered insecure to supply a MySQL password on the command
# line; in some MySQL versions it actually generates a warning.)
if $needpassword; then
    echo_n "Enter MySQL password: "
    stty -echo; trap "stty echo; exit 1" INT
    read PASSWORD
    stty echo; trap - INT
    echo
fi
set_myargs "$DBUSER" "$PASSWORD"


if ! (echo 'show databases;' | eval $MYSQL $myargs $FLAGS >/dev/null); then
    echo "Could not run $MYSQL $myargs_redacted $FLAGS. Did you enter the right password?" 1>&2
    exit 1
fi
grants=`echo 'show grants;' | eval $MYSQL $myargs $FLAGS | grep -i -e create -e all | grep -i 'on \*\.\*'`
if ! $force && test -z "$grants"; then
    echo 1>&2
    echo "* This account doesn't appear to have the privilege to create MySQL databases." 1>&2
    echo "* Try 'sudo $PROG' and/or supply '--user' and '--password' options." 1>&2
    echo "* If you think this message is in error, run '$PROG --force'." 1>&2
    echo 1>&2
    exit 1
fi


echo "Creating the database and database user for your conference."
echo "Access is allowed only from the local host."
echo

echo_dbname () {
    cat <<__EOF__
$DBNAME
__EOF__
}

default_dbname=
x="`getdbopt dbName 2>/dev/null`"
x="`eval "echo $x"`"
if test -n "$x"; then
    bad="`eval "echo $x" | tr -d a-zA-Z0-9_.-`"
    if test -z "$bad"; then default_dbname="`echo $x`"; fi
fi

while true; do
    echo_n "Enter database name (NO SPACES)"
    if [ -z "$DBNAME" ]; then
	test -n "$default_dbname" && echo_n " [default $default_dbname]"
	echo_n ": "
	read -r DBNAME
    else
	echo ": $DBNAME"
    fi

    test -z "$DBNAME" -a -n "$default_dbname" && DBNAME="$default_dbname"
    x="`echo_dbname | tr -d a-zA-Z0-9_.-`"
    c="`echo_dbname | wc -c`"
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
    random="`head -c 48 /dev/urandom 2>/dev/null | tr -d '\000'`"
    test -z "$random" && random="`head -c 48 /dev/random 2>/dev/null | tr -d '\000'`"
    test -z "$random" && random="`openssl rand 48 2>/dev/null | tr -d '\000'`"
    echo "$random" | awk '
BEGIN { for (i = 0; i < 256; ++i) { ord[sprintf("%c", i)] = i; } }
{ for (i = 1; i <= length($0); ++i) { printf("%d\n", ord[substr($0, i, 1)]); } }'
    # generate some very low-quality random bytes in case all the
    # higher-quality mechanisms fail
    awk 'BEGIN { srand(); for (i = 0; i < 256; ++i) { printf("%d\n", rand() * 256); } }' < /dev/null
}

generate_password () {
    awk 'BEGIN {
    npwchars = split("a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =", pwchars, " ");
    pw = ""; nvow = 0;
}
{   x = ($0 % npwchars); if (x < 30) ++nvow;
    pw = pw pwchars[x + 1];
    if (length(pw) >= '"$1"' + nvow / 3) exit;
}
END { printf("%s\n", pw); }'
}

default_dbpass=
x="`getdbopt dbPassword 2>/dev/null`"
x="`eval "echo $x"`"
test -n "$x" -a "$DBNAME" = "$default_dbname" && default_dbpass="$x"
test -z "$default_dbpass" && default_dbpass=`generate_random_ints | generate_password 12`
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
echo


sql_dbpass () {
    echo_dbpass | sql_quote
}

php_dbpass () {
    echo_dbpass | sed -e 's,\([\\"'"'"']\),\\\1,g'
}


echo
if [ -z "$myargs$FLAGS" ]; then
    echo "Creating database."
    echo "This should work if you are root and haven't changed the default mysql"
    echo "administrative password. If you have changed the password, you will need to"
    echo "run '$PROG -p' or '$PROG -pPASSWD' (no space)."
fi
echo "+ echo 'show databases;' | $MYSQL $myargs_redacted $FLAGS | grep $DBNAME"
echo 'show databases;' | eval $MYSQL $myargs $FLAGS >/dev/null || exit 1
echo 'show databases;' | eval $MYSQL $myargs $FLAGS | grep $DBNAME >/dev/null 2>&1
dbexists="$?"
echo "+ echo 'select User from user group by User;' | $MYSQL $myargs_redacted $FLAGS mysql | grep $DBNAME"
echo 'select User from user group by User;' | eval $MYSQL $myargs $FLAGS mysql >/dev/null || exit 1
echo 'select User from user group by User;' | eval $MYSQL $myargs $FLAGS mysql | grep '^'$DBNAME'$' >/dev/null 2>&1
userexists="$?"
createdbuser=y
if [ "$dbexists" = 0 -o "$userexists" = 0 ]; then
    echo
    test "$dbexists" = 0 && echo "A database named '$DBNAME' already exists!"
    test "$userexists" = 0 && echo "A user named '$DBNAME' already exists!"
    while true; do
	echo_n "Delete and recreate database and user? [Y/n] "
	read createdbuser
	expr "$createdbuser" : "[ynqYNQ].*" >/dev/null && break
	test -z "$createdbuser" && break
    done
    expr "$createdbuser" : "[nNqQ].*" >/dev/null && echo "Exiting" && exit 0
    expr "$createdbuser" : "[nN].*" >/dev/null || createdbuser=y

    if [ "$createdbuser" = y -a "$dbexists" = 0 ]; then
	echo "+ $MYSQLADMIN $myargs_redacted $FLAGS -f drop $DBNAME"
	eval $MYSQLADMIN $myargs $FLAGS -f drop $DBNAME || exit 1
    fi
fi
if [ "$createdbuser" = y ]; then
    echo
    echo "Creating $DBNAME database."
    echo "+ $MYSQLADMIN $myargs_redacted $FLAGS --default-character-set=utf8 create $DBNAME"
    eval $MYSQLADMIN $myargs $FLAGS --default-character-set=utf8 create $DBNAME || exit 1

    echo "Creating $DBNAME user and password."
    eval $MYSQL $myargs $FLAGS mysql <<__EOF__ || exit 1
DELETE FROM user WHERE user='$DBNAME';
DELETE FROM db WHERE User='$DBNAME';
FLUSH PRIVILEGES;

CREATE USER '$DBNAME'@'localhost' IDENTIFIED BY '`sql_dbpass`',
    '$DBNAME'@'127.0.0.1' IDENTIFIED BY '`sql_dbpass`',
    '$DBNAME'@'localhost.localdomain' IDENTIFIED BY '`sql_dbpass`';

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
    eval $MYSQLADMIN $myargs $FLAGS reload || exit 1

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
    echo_n "Populate database? [Y/n] "
    read populatedb
    expr "$populatedb" : "[ynqYNQ].*" >/dev/null && break
    test -z "$populatedb" && break
done
expr "$populatedb" : "[qQ].*" >/dev/null && echo "Exiting..." && exit 0
expr "$populatedb" : "[nN].*" >/dev/null || populatedb=y
echo

if [ "$populatedb" = y ]; then
    echo "Populating database."
    set_myargs $DBNAME "`echo_dbpass`"
    echo "+ $MYSQL $myargs_redacted $FLAGS $DBNAME < ${PROGDIR}schema.sql"
    eval $MYSQL $myargs $FLAGS $DBNAME < ${PROGDIR}schema.sql || exit 1
fi

##
## Create options.inc
##

create_options () {
    awk 'BEGIN { p = 1 }
/^\$Opt\[.db/ { p = 0 }
{ if (p) print }' < "${PROGDIR}${distoptions_file}"
    cat <<__EOF__
\$Opt["dbName"] = "$DBNAME";
\$Opt["dbPassword"] = "`php_dbpass`";
__EOF__
    awk 'BEGIN { p = 0 }
/^\$Opt\[.db/ { p = 1; next }
/^\$Opt\[.passwordHmacKey/ { p = 0; next }
{ if (p) print }' < "${PROGDIR}${distoptions_file}"
    cat <<__EOF__
\$Opt["passwordHmacKey"] = "`generate_random_ints | generate_password 40`";
__EOF__
    awk 'BEGIN { p = 0 }
/^\$Opt\[.passwordHmacKey/ { p = 1; next }
{ if (p) print }' < "${PROGDIR}${distoptions_file}"
}

is_group_member () {
    u="$1"; g="$2"
    if test -x /usr/bin/dsmemberutil; then
	if expr "$u" : '[0-9]*$' >/dev/null; then ua="-u"; else ua="-U"; fi
	if expr "$g" : '[0-9]*$' >/dev/null; then ga="-g"; else ga="-G"; fi
	/usr/bin/dsmemberutil checkmembership $ua "$u" $ga "$g" 2>/dev/null | grep "is a member" >/dev/null
    else
	members="`grep "^$group" /etc/group | sed 's/.*:.*:.*:/,/'`"
	echo "$members," | grep ",$u," >/dev/null
    fi
}

if [ -r "${PROGDIR}${options_file}" ]; then
    echo
    echo "* Your Code/${options_file} file already exists."
    echo "* Edit it to use the database name, username, and password you chose."
    echo
elif [ -r "${PROGDIR}${distoptions_file}" ]; then
    echo "Creating Code/${options_file}..."
    create_options > "${PROGDIR}${options_file}"
    if [ -n "$SUDO_USER" ]; then
	echo + chown $SUDO_USER "${PROGDIR}${options_file}"
	chown $SUDO_USER "${PROGDIR}${options_file}"
    fi
    chmod o-rwx "${PROGDIR}${options_file}"

    # warn about unreadable Code/options.inc
    group="`ls -l "${PROGDIR}${options_file}" | awk '{print $4}'`"

    httpd_user="`ps axho user,comm | grep -E 'httpd|apache' | uniq | grep -v root | awk 'END {if ($1) print $1}'`"

    if test -z "$httpd_user"; then
	echo
	echo "* The ${PROGDIR}${options_file} file contains sensitive data."
	echo "* You may need to change its group so the Web server can read it."
	echo
    elif ! is_group_member "$httpd_user" "$group"; then
	if [ -n "$SUDO_USER" ] && chgrp "$httpd_user" "${PROGDIR}${options_file}" 2>/dev/null; then
	    echo "Making ${PROGDIR}${options_file} readable by the Web server..."
	    echo + chgrp "$httpd_user" "${PROGDIR}${options_file}"
	else
	    echo
	    echo "* The ${PROGDIR}${options_file} file contains important data, but the Web server"
	    echo "* cannot read it. Use 'chgrp GROUP ${PROGDIR}${options_file}' to change its group."
	    echo
	fi
    fi
fi

test -n "$PASSWORDFILE" && rm -f "$PASSWORDFILE"
