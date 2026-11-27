#! /bin/sh
## createdb.sh -- HotCRP database setup
## Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C CONFNAME=
set -f   # disable filename globbing
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh

help () {
    echo "${LIBDIR}createdb.sh performs MySQL database setup for HotCRP."
    echo
    echo "Usage: ${LIBDIR}createdb.sh [-c CONFIG] [MYSQLOPTIONS] [DBNAME]"
    echo
    echo "Options:"
    echo '  -c, --config=CONFIG     Configuration file is CONFIG [conf/options.php].'
    echo "      --minimal           Output minimal configuration file."
    echo "      --batch             Batch installation: never stop for input."
    echo "      --force             Answer yes to all questions."
    echo "      --replace           Replace existing database and user."
    echo "  -q, --quiet             Be quiet."
    echo "      --dbuser=USER,PASS  Specify database USER and PASS."
    echo "      --host=HOST         Specify database host."
    echo "      --grant-host=HOST   HOST is granted DB access privilege (in addition"
    echo '                          to `localhost`).'
    echo "      --no-default-grant-hosts"
    echo '                          Do not grant DB access to `localhost`; only'
    echo '                          `--grant-host` hosts are granted access.'
    echo "      --no-dbuser         Do not create database user."
    echo "      --no-schema         Do not load initial schema."
    echo "      --no-setup-phase    Don't give special treatment to the first user."
    echo
    echo "MYSQLOPTIONS are sent to mysql and mysqladmin."
    echo 'Common options include `--user=ADMIN_USERNAME` and `--password=ADMIN_PASSWORD`'
    echo "to select a database admin user able to create new tables."
    exit 0
}

usage () {
    echo "Usage: $PROG [MYSQLOPTIONS]" 1>&2
    echo "Type ${LIBDIR}createdb.sh --help for more information." 1>&2
    exit 1
}

set_dbuserpass () {
    if ! expr "$1" : ".*,.*" >/dev/null; then
        echo "Expected --dbuser=USER,PASS" 1>&2
        usage
    fi
    DBUSER="`echo "$1" | sed 's/^\([^,]*\),.*/\1/'`"
    DBPASS="`echo "$1" | sed 's/^[^,]*,//'`"
    dbuser_existing=true
}

add_granthost () {
    if expr "x$1" : 'x[-a-zA-Z0-9.%_/:][-a-zA-Z0-9.%_/:]*$' >/dev/null; then
        granthosts="$1$granthosts "
    else
        echo "Expected --grant-host=HOSTNAME" 1>&2
        usage
    fi
}

PROG=$0
FLAGS=""
MYCREATEDB_USER=""
DBNAME=""
DBUSER=""
DBPASS=""
DBHOST=""
PASSWORD=""
distoptions_file=distoptions.php
options_file=
minimal_options=
mycreatedb_args=" --defaults-group-suffix=_hotcrp_createdb"
has_host=false
granthosts=""
default_granthosts=true
needpassword=false
force=false
batch=false
replace=false
dbuser_existing=false
no_schema=false
quiet=false
verbose=false
qecho=echo
qecho_n=echo_n
no_password_file=false
setup_phase=cat
while [ $# -gt 0 ]; do
    shift=1
    case "$1" in
    -p|--pas|--pass|--passw|--passwo|--passwor|--password)
        needpassword=true;;
    -u|--us|--use|--user)
        MYCREATEDB_USER="$2"; shift;;
    -u*)
        MYCREATEDB_USER="`echo "$1" | sed s/^-u//`";;
    --u=*|--us=*|--use=*|--user=*)
        MYCREATEDB_USER="`echo "$1" | sed 's/^[^=]*=//'`";;
    -p*)
        PASSWORD="`echo "$1" | sed s/^-p//`";;
    --pas=*|--pass=*|--passw=*|--passwo=*|--passwor=*|--password=*)
        PASSWORD="`echo "$1" | sed 's/^[^=]*=//'`";;
    --dbuser)
        set_dbuserpass "$2"; shift;;
    --dbuser=*)
        set_dbuserpass "`echo "$1" | sed 's/^[^=]*=//'`";;
    --no-dbuser)
        dbuser_existing=true;;
    --no-password-f|--no-password-fi|--no-password-fil|--no-password-file)
        no_password_file=true;;
    --he|--hel|--help)
        help;;
    --force)
        force=true;;
    --batch)
        batch=true;;
    --minimal)
        minimal_options=y;;
    --replace)
        replace=true;;
    -q|--quie|--quiet)
        quiet=true; qecho=true; qecho_n=true;;
    -c|--co|--con|--conf|--confi|--config|-c*|--co=*|--con=*|--conf=*|--confi=*|--config=*)
        parse_common_argument "$@";;
    -n|--n|--na|--nam|--name|-n*|--n=*|--na=*|--nam=*|--name=*)
        parse_common_argument "$@";;
    --defaults-group-suffix=*)
        mycreatedb_args=; FLAGS="$FLAGS '$1'";;
    --no-schema)
        no_schema=true;;
    --no-setup-phase)
        setup_phase="grep -v 'setupPhase'";;
    -V|--verb|--verbo|--verbos|--verbose)
        verbose=true;;
    --host=*)
        DBHOST="`echo "$1" | sed 's/^[^=]*=//'`"; FLAGS="$FLAGS '$1'"; has_host=true;;
    --host)
        DBHOST="$2"; FLAGS="$FLAGS --host '$2'"; has_host=true; shift;;
    --grant-host=*)
        add_granthost "`echo "$1" | sed 's/^[^=]*=//'`";;
    --grant-host)
        add_granthost "$2"; shift;;
    --no-default-grant-hosts|--no-default-grant|--no-default-grant-host)
        default_granthosts=false;;
    -*)
        FLAGS="$FLAGS '$1'";;
    *)
        if [ -z "$DBNAME" ]; then DBNAME="$1"; else usage; fi;;
    esac
    shift $shift
done

if ! $default_granthosts && test -z "$granthosts"; then
    echo "* \`--no-default-grant-hosts\` given without \`--grant-host\`, so no host" 1>&2
    echo "* would be granted database access." 1>&2
    exit 1
fi

### Read database host from configuration file, unless given on command line
if ! $has_host; then
    x="`getdbopt dbHost 2>/dev/null`"
    x="`eval "echo $x" 2>/dev/null`"
    if test -n "$x"; then
        DBHOST="$x"; FLAGS="$FLAGS --host '$x'"; has_host=true
    fi
fi

### Test mysql binary
check_mysqlish MYSQL mysql
check_mysqlish MYSQLADMIN mysqladmin

### Print hotcrp.com message
if ! $quiet && ! $batch && ! [ -n "$options_file" -a -f "$options_file" ]; then
    echo 1>&2
    echo "* This script initializes a local HotCRP installation." 1>&2
    echo "* You are responsible for supporting this installation." 1>&2
    echo "*" 1>&2
    echo "* Supported installations are available at https://hotcrp.com/" 1>&2
    echo "* for a per-submission fee (ACM- and USENIX-sponsored conferences" 1>&2
    echo "* can take advantage of site-wide agreements)." 1>&2
    echo 1>&2
    while true; do
        echo_n "Type \"ok\" to continue: "
        read OK
        expr "$OK" : "[yYqQ].*" >/dev/null && break
        expr "$OK" : "[oO][kK].*" >/dev/null && break
    done
    expr "$OK" : "[qQ].*" >/dev/null && exit 1
    echo 1>&2
fi

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
set_myargs "$MYCREATEDB_USER" "$PASSWORD"

# check that we can run mysql
if ! (echo 'show databases;' | eval $MYSQL $mycreatedb_args $myargs $FLAGS >/dev/null); then
    echo 1>&2
    echo "* Failure running \`$MYSQL$myargs_redacted$FLAGS\`." 1>&2
    echo 1>&2
    exit 1
fi
grants=`echo 'show grants;' | eval $MYSQL $mycreatedb_args $myargs $FLAGS | grep -i -e create -e all | grep -i 'on \*\.\*'`
if ! $force && test -z "$grants"; then
    echo 1>&2
    echo "* This account doesn't appear to have the privilege to create MySQL databases." 1>&2
    echo "* Try \`sudo $PROG\` and/or supply \`--user\` and \`--password\` options." 1>&2
    echo "* If you think this message is in error, run \`$PROG --force\`." 1>&2
    test -n "$FLAGS" &&
        echo "* Or try different flags; you passed \`$FLAGS\`." 1>&2
    echo 1>&2
    exit 1
fi


remote_host=false
if $has_host; then
    case "$DBHOST" in
    localhost|127.0.0.1|::1) ;;
    *) remote_host=true;;
    esac
fi

remote_host_message () {
    echo "* The database user can only access the database locally, but this script is"
    echo "* connecting over the network. Unless HotCRP will run on the database"
    echo "* server, add \`--grant-host=HOST\` arguments, where HOST is this machine's"
    echo "* name or IP address (or \`%\` for any host)."
}

if $batch && $remote_host && test -z "$granthosts"; then
    echo 1>&2
    remote_host_message 1>&2
    echo 1>&2
    exit 1
fi

if ! $batch; then
    if $dbuser_existing; then
        echo "Creating the database for your conference."
    else
        echo "Creating the database and database user for your conference."
    fi
    if test -z "$granthosts" && $remote_host; then
        remote_host_message
        while true; do
            echo_n "Continue anyway? [y/N] "
            read OK
            expr "$OK" : "[yY].*" >/dev/null && break
            if test -z "$OK" || expr "$OK" : "[nNqQ].*" >/dev/null; then
                echo "* Quitting." 1>&2
                exit 1
            fi
        done
    elif test -z "$granthosts"; then
        echo "* Access for the database user is allowed only from the local host."
    fi
    echo
fi

echo_dbname () {
    cat <<__EOF__
$DBNAME
__EOF__
}

batch_fail () {
    if $batch; then
        echo 1>&2
        echo "* Giving up. Try \`--batch --replace\` or other arguments and try again." 1>&2
        echo 1>&2
        exit 1
    fi
}

default_dbname=
x="`getdbopt dbName 2>/dev/null`"
x="`eval "echo $x"`"
if test -n "$x"; then
    bad="`eval "echo $x" | tr -d a-zA-Z0-9_.-`"
    if test -z "$bad"; then default_dbname="`echo $x`"; fi
fi

default_dbuser=
x="`getdbopt dbUser 2>/dev/null`"
x="`eval "echo $x"`"
if test -n "$x"; then
    bad="`eval "echo $x" | tr -d a-zA-Z0-9_.-`"
    if test -z "$bad"; then default_dbuser="`echo $x`"; fi
fi
if $dbuser_existing && [ -z "$DBUSER" ]; then DBUSER="$default_dbuser"; fi

while true; do
    if [ -z "$DBNAME" -a -n "$default_dbname" ] && $batch; then
        DBNAME="$default_dbname"
    elif [ -z "$DBNAME" ] && $batch; then
        echo 1>&2
        echo "* Supply the database name on the command line or drop \`--batch\`." 1>&2
        echo 1>&2
        exit 1
    elif [ -z "$DBNAME" ]; then
        echo_n "Enter database name (NO SPACES)"
        test -n "$default_dbname" && echo_n " [default $default_dbname]"
        echo_n ": "
        read -r DBNAME
    elif ! $batch; then
        echo "Database: $DBNAME"
    fi

    test -z "$DBNAME" -a -n "$default_dbname" && DBNAME="$default_dbname"
    x="`echo_dbname | tr -d a-zA-Z0-9_.-`"
    c="`echo_dbname | tr -d '\n' | wc -c`"
    if test -z "$DBNAME"; then
        echo "* Quitting." 1>&2
        exit 1
    elif test -n "$x"; then
        echo "* The database name must only contain characters in [-.a-zA-Z0-9_]." 1>&2
    elif test "$c" -gt 64; then
        echo "* The database name can be at most 64 characters long." 1>&2
    elif test "`echo "$DBNAME" | head -c 1`" = "."; then
        echo "* The database name must not start with a period." 1>&2
    elif test "$DBNAME" = mysql -o "$DBNAME" = sys || expr "$DBNAME" : '.*_schema$' >/dev/null; then
        echo "* Database name '$DBNAME' is reserved." 1>&2
    elif ! $dbuser_existing && test "$DBNAME" = "$default_dbname" -a -n "$default_dbuser"; then
        DBUSER="$default_dbuser"; break
    elif ! $dbuser_existing && test "$c" -gt 32; then
        echo "* Database user names can be at most 32 characters long." 1>&2
        echo "* Either choose a shorter database name, or use --dbuser." 1>&2
    else
        break
    fi

    DBNAME=
    batch_fail
done


echo_dbpass () {
    cat <<__EOF__
$DBPASS
__EOF__
}

test -z "$DBUSER" && DBUSER="$DBNAME"
if expr "x$DBUSER" : "x.*['\\\\]" >/dev/null; then
    echo "* The database user name must not contain quotes or backslashes." 1>&2
    exit 1
fi
case "$DBUSER" in
root|mysql|debian-sys-maint|mysql.*|mariadb.*)
    echo "* The database user name '$DBUSER' is reserved." 1>&2
    exit 1;;
esac
if test -n "$MYCREATEDB_USER" -a "$DBUSER" = "$MYCREATEDB_USER"; then
    echo "* The database user must differ from the administrator account." 1>&2
    exit 1
fi
default_dbpass=
x="`getdbopt dbPassword 2>/dev/null`"
x="`eval "echo $x"`"
test -n "$x" -a "$DBNAME" = "$default_dbname" && default_dbpass="$x"
if test -n "$default_dbpass"; then
    default_dbpass_description="taken from `findoptions`"
else
    default_dbpass=`generate_random_ints | generate_password 12`
    default_dbpass_length="`echo_n "$default_dbpass" | wc -c`"
    default_dbpass_description="is `echo $default_dbpass_length` random characters"
fi
while test -z "$DBPASS"; do
    if ! $batch; then
        echo_n "Enter password for mysql user $DBUSER [default $default_dbpass_description]: "
        stty -echo; trap "stty echo; exit 1" INT
        read -r DBPASS
        stty echo; trap - INT
    else
        DBPASS=
    fi
    if [ -z "`echo_dbpass`" ]; then DBPASS=$default_dbpass; fi
    x=`echo_dbpass | tr -d -c '\000'"'"`
    if test -z "$x" >/dev/null; then break; fi
    echo 1>&2
    echo "* The database password must not contain single quotes or null characters." 1>&2
    batch_fail
    DBPASS=""
done
$batch || echo

sql_dbpass () {
    echo_dbpass | sql_quote
}

php_dbpass () {
    echo_dbpass | sed -e 's,\([\\"$'"'"']\),\\\1,g'
}


DBNAME_QUOTED=`echo "$DBNAME" | sed 's/[.]/[.]/g'`
$qecho
$qecho "+ echo 'show databases;' | $MYSQL$mycreatedb_args$myargs_redacted$FLAGS -N | grep '^$DBNAME_QUOTED\$'"
echo 'show databases;' | eval $MYSQL $mycreatedb_args $myargs $FLAGS -N >/dev/null || exit 1
echo 'show databases;' | eval $MYSQL $mycreatedb_args $myargs $FLAGS -N | grep "^$DBNAME_QUOTED\$" >/dev/null 2>&1
dbexists="$?"

DBUSER_QUOTED=`echo "$DBUSER" | sed 's/[.]/[.]/g'`
$qecho "+ echo 'select User from user group by User;' | $MYSQL$mycreatedb_args$myargs_redacted$FLAGS -N mysql | grep '^$DBUSER_QUOTED\$'"
echo 'select User from user group by User;' | eval $MYSQL $mycreatedb_args $myargs $FLAGS -N mysql >/dev/null || exit 1
echo 'select User from user group by User;' | eval $MYSQL $mycreatedb_args $myargs $FLAGS -N mysql | grep "^$DBUSER_QUOTED\$" >/dev/null 2>&1
userexists="$?"

createdb=y; createuser=y
if $dbuser_existing && [ "$userexists" != 0 ]; then
    echo "* The requested database user $DBUSER does not exist." 1>&2
    exit 1
elif $dbuser_existing; then
    createuser=n
fi

if [ "$createdb$dbexists" = y0 -o "$createuser$userexists" = y0 ]; then
    echo 1>&2
    test "$createdb$dbexists" = y0 && echo "* A database named '$DBNAME' already exists!" 1>&2
    test "$createuser$userexists" = y0 && echo "* A user named '$DBUSER' already exists!" 1>&2
    while ! $replace; do
        batch_fail
        echo_n "Replace? [Y/n] "
        read create
        expr "$create" : "[ynqYNQ].*" >/dev/null && break
        test -z "$create" && break
    done
    expr "$create" : "[qQ].*" >/dev/null && echo "Exiting" && exit 0
    if expr "$create" : "[nN].*" >/dev/null; then create=n; else create=y; fi
    test "$createdb$dbexists$create" = y0n && createdb=n
    test "$createuser$userexists$create" = y0n && createuser=n
fi

echo
if [ "$createdb" = y ]; then
    $qecho "Creating $DBNAME database..."
    if [ "$dbexists" = 0 ]; then
        $qecho "+ $MYSQLADMIN$mycreatedb_args$myargs_redacted$FLAGS -f drop $DBNAME"
        eval $MYSQLADMIN $mycreatedb_args $myargs $FLAGS -f drop $DBNAME || exit 1
    fi
    $qecho "+ $MYSQLADMIN$mycreatedb_args$myargs_redacted$FLAGS --default-character-set=utf8 create $DBNAME"
    eval $MYSQLADMIN $mycreatedb_args $myargs $FLAGS --default-character-set=utf8 create $DBNAME || exit 1
fi

# Database-level grants treat `_` and `%` as wildcards; escape them, unless
# MySQL's `partial_revokes` is on (then they are literal)
partial_revokes=`echo "show global variables like 'partial\\_revokes';" | \
    eval $MYSQL $mycreatedb_args $myargs $FLAGS -N 2>/dev/null | awk '{print $2}'`
if [ "x$partial_revokes" = xON ]; then
    GRANT_DBNAME="$DBNAME"
else
    GRANT_DBNAME="`echo_dbname | sed 's/[_%]/\\\\&/g'`"
fi

defaulthosts=""
if $default_granthosts; then
    defaulthosts="localhost 127.0.0.1 ::1"

    # Also include `localhost.localdomain` (for backward compatibility), but
    # only if `skip_name_resolve` is off
    skip_name_resolve=`echo 'select @@global.skip_name_resolve;' | \
        eval $MYSQL $mycreatedb_args $myargs $FLAGS -N 2>/dev/null`
    if [ "x$skip_name_resolve" != x1 ]; then
        defaulthosts="$defaulthosts localhost.localdomain"
    fi
fi
allhosts="$granthosts$defaulthosts"

if [ "$createuser" = y ]; then
    $qecho "Creating $DBUSER user and password..."
    # 1. GRANT USAGE to ensure users exist (because DROP USER errors if they don't)
    # 2. DROP USER
    # 3. CREATE USER
    for host in $allhosts; do
        echo "select User from user where User='$DBUSER' and Host='$host';" | eval $MYSQL $mycreatedb_args $myargs $FLAGS -N mysql | grep . >/dev/null 2>&1
        if [ $? = 0 ]; then
            if $verbose; then
                echo ". DROP USER '$DBUSER'@'$host';"
            fi
            eval $MYSQL $mycreatedb_args $myargs $FLAGS mysql <<__EOF__ || exit 1
DROP USER '$DBUSER'@'$host';
__EOF__
        fi
        if $verbose; then
            cat <<__EOF__
. FLUSH PRIVILEGES;
. CREATE USER '$DBUSER'@'$host' IDENTIFIED BY <REDACTED>;
__EOF__
        fi
        eval $MYSQL $mycreatedb_args $myargs $FLAGS mysql <<__EOF__ || exit 1
FLUSH PRIVILEGES;
CREATE USER '$DBUSER'@'$host' IDENTIFIED BY '`sql_dbpass`';
__EOF__
    done
fi

user_host_exists () {
    echo "select User from user where User='$DBUSER' and Host='$1';" | eval $MYSQL $mycreatedb_args $myargs $FLAGS -N mysql | grep . >/dev/null 2>&1
}

grant_access () {
    if $verbose; then
        cat <<__EOF__
. GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX,
.     REFERENCES, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES
.     ON \`$GRANT_DBNAME\`.* TO '$DBUSER'@'$1';
. GRANT RELOAD ON *.* TO '$DBUSER'@'$1';
__EOF__
    fi
    eval $MYSQL $mycreatedb_args $myargs $FLAGS mysql <<__EOF__ || exit 1
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX,
    REFERENCES, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES
    ON \`$GRANT_DBNAME\`.* TO '$DBUSER'@'$1';
GRANT RELOAD ON *.* TO '$DBUSER'@'$1';
__EOF__
}

if [ "$createdb" = y -o "$createuser" = y ]; then
    $qecho "Granting $DBUSER access to $DBNAME..."
    $verbose && echo ". DELETE FROM db WHERE db IN ('$DBNAME', '$GRANT_DBNAME') AND User='$DBUSER';"
    eval $MYSQL $mycreatedb_args $myargs $FLAGS mysql <<__EOF__ || exit 1
DELETE FROM db WHERE db IN ('$DBNAME', '$GRANT_DBNAME') AND User='$DBUSER';
__EOF__
    # GRANT fails if the user does not exist at a host; existing users
    # (`--dbuser`) are not created at new hosts
    for host in $granthosts; do
        if [ "$createuser" != y ] && ! user_host_exists "$host"; then
            echo "* The requested database user '$DBUSER'@'$host' does not exist." 1>&2
            exit 1
        fi
        grant_access "$host"
    done
    for host in $defaulthosts; do
        if [ "$createuser" != y ] && ! user_host_exists "$host"; then
            $qecho "Not granting '$DBUSER'@'$host' access: no such user"
        else
            grant_access "$host"
        fi
    done

    $qecho "Reloading grant tables..."
    eval $MYSQLADMIN $mycreatedb_args $myargs $FLAGS reload || exit 1
else
    $qecho
    $qecho "Continuing with existing database and user."
fi

##
## Populate the database schema
##
if ! $replace && ! $no_schema && test "$createdb" = n; then
    batch_fail
    echo
    echo "Do you want to replace the current database contents with a fresh install?"
    while true; do
        echo_n "Replace database contents? [Y/n] "
        read populatedb
        expr "$populatedb" : "[ynqYNQ].*" >/dev/null && break
        test -z "$populatedb" && break
    done
    expr "$populatedb" : "[qQ].*" >/dev/null && echo "Exiting..." && exit 0
    expr "$populatedb" : "[nN].*" >/dev/null && no_schema=true
    echo
fi
if ! $no_schema; then
    $qecho "Populating database..."
    set_myargs "$DBUSER" "`echo_dbpass`"
    $qecho "+ $setup_phase ${SRCDIR}schema.sql | $MYSQL$myargs_redacted$FLAGS $DBNAME"
    eval $setup_phase ${SRCDIR}schema.sql | eval $MYSQL $myargs $FLAGS $DBNAME || exit 1
fi

##
## Create options.php
##

create_options () {
    test -n "$minimal_options" && echo '<?php
global $Opt;'
    test -z "$minimal_options" && awk 'BEGIN { p = 1 }
/^\$Opt\[.db/ { p = 0 }
{ if (p) print }' < "${ETCDIR}${distoptions_file}"
    cat <<__EOF__
\$Opt["dbName"] = "$DBNAME";
\$Opt["dbUser"] = "$DBUSER";
\$Opt["dbPassword"] = "`php_dbpass`";
__EOF__
    test -n "$DBHOST" && echo '$Opt["dbHost"] = "'"$DBHOST"'";'
    test -z "$minimal_options" && awk 'BEGIN { p = 0 }
/^\$Opt\[.db/ { p = 1; next }
{ if (p) print }' < "${ETCDIR}${distoptions_file}"
}

expected_options="`findoptions expected`"
current_options="`findoptions`"
created_options=
if findoptions >/dev/null; then
    echo
    echo "* Your $current_options file already exists."
    echo "* Edit it to use the database name, username, and password you chose."
    if [ "$current_options" != "$expected_options" ]; then
        echo
        echo "* Also, the new location for the options file is $expected_options."
        echo "* You should move $current_options there."
    fi
    echo
elif [ -r "${ETCDIR}${distoptions_file}" -o -n "$minimal_options" ]; then
    $qecho
    $qecho "Creating $expected_options..."
    # The file contains a password: make it unreadable by others, and
    # refuse to overwrite an existing file (`set -C`), so we never write
    # through a planted symbolic link
    if ! (umask 027; set -C; create_options > "$expected_options"); then
        echo "* Cannot create $expected_options." 1>&2
        exit 1
    fi
    if [ -n "$SUDO_USER" ]; then
        $qecho + chown -h $SUDO_USER "$expected_options"
        chown -h $SUDO_USER "$expected_options"
    fi
    current_options="$expected_options"
    created_options=y
    $qecho
    $qecho "* Done!"
else
    $qecho
    $qecho "* Not creating $expected_options."
    current_options=
fi

## Warn if the web server cannot read the options file. This only advises;
## it never changes the file.

# Print the uid of the web server likely to run HotCRP: the uid of the
# non-root php-fpm processes, or failing that of the non-root Apache
# processes, if they all have the same uid. Print nothing otherwise. Since
# any user can give a process one of these names, the result is only used
# for advice that names the user.
find_web_server_uid () {
    ps axo uid=,comm= 2>/dev/null | awk '
function kind(c) {
    sub(/.*\//, "", c)
    if (c ~ /^php-fpm/) return 1
    if (c ~ /^(httpd|apache2?)$/) return 2
    return 0
}
{
    k = kind($2)
    if (k && $1 + 0 != 0 && !seen[k, $1 + 0]++) {
        n[k]++; u[k] = $1 + 0
    }
}
END {
    for (i = 1; i <= 2; ++i) {
        if (n[i]) {
            if (n[i] == 1) print u[i]
            exit
        }
    }
}'
}

# Usage: web_can_read UID FILE
web_can_read () {
    wuid="$1"
    set -- `ls -lnd "$2"`
    if [ "$3" = "$wuid" ]; then
        expr "x$1" : 'x.r' >/dev/null
    elif id -G "$wuid" 2>/dev/null | tr ' ' '\n' | grep -x "$4" >/dev/null; then
        expr "x$1" : 'x....r' >/dev/null
    else
        expr "x$1" : 'x.......r' >/dev/null
    fi
}

if test -n "$current_options" && test -f "$current_options"; then
    web_uid="`find_web_server_uid`"
    if test -z "$web_uid"; then
        if test -n "$created_options"; then
            $qecho
            $qecho "* $current_options contains the database password. Make sure the"
            $qecho "* web server can read it, but other users cannot."
        fi
    elif ! web_can_read "$web_uid" "$current_options"; then
        web_user="`id -nu "$web_uid" 2>/dev/null || echo "$web_uid"`"
        web_group="`id -gn "$web_uid" 2>/dev/null || echo GROUP`"
        echo 1>&2
        echo "* $current_options contains the database password, but the web server" 1>&2
        echo "* (user $web_user) cannot read it. Potential fix:" 1>&2
        echo "*     chgrp $web_group $current_options" 1>&2
        echo "*     chmod g+r $current_options" 1>&2
    fi
fi

if test -n "$PASSWORDFILE"; then rm -f "$PASSWORDFILE"; fi
