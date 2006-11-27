#! /bin/sh
##
## Create the database. The assumption is that database
## name and user name and password are all the same
##

export PROG=$0
export FLAGS=""
while [ $# -gt 0 ]; do
    case "$1" in
    -*)	FLAGS="$FLAGS $1";;
    *)	echo "Usage: $PROG [MYSQL-OPTIONS]" 1>&2; exit 1;;
    esac
    shift
done

PROGDIR=`echo "$0" | sed 's/[^\/]*$//'`


echo "This will create the database for your conference."
echo "The database name, database user, and database password are all set to"
echo "the same thing.  Access is allowed only from the local host."

echo -n "Enter database name (NO SPACES): "
read DBNAME
echo "DBNAME is $DBNAME"

echo
echo "Creating database."
if [ -z "$FLAGS" ]; then
    echo "This should work if you are root and haven't changed the default mysql"
    echo "administrative password.  If you have changed the password, you will need to"
    echo "run '$PROG -p' or '$PROG -pPASSWD' (no space)."
fi
echo "+ echo 'show databases;' | mysql | grep $DBNAME"
echo 'show databases;' | mysql $FLAGS | grep $DBNAME >/dev/null 2>&1
if [ $? = 0 ]; then
    echo
    echo "A database named '$DBNAME' already exists!  Make sure you want to"
    echo "delete this database and user."
    echo "+ mysqladmin $FLAGS drop $DBNAME"
    mysqladmin $FLAGS drop $DBNAME
fi
echo "+ mysqladmin $FLAGS create $DBNAME"
mysqladmin $FLAGS create $DBNAME || exit 1

echo
echo "Creating $DBNAME user and password."
mysql $FLAGS mysql <<__EOF__ || exit 1
DELETE FROM user WHERE user="$DBNAME";
INSERT INTO user SET
    Host='127.0.0.1',
    User="$DBNAME",
    Password=PASSWORD("$DBNAME"),
    select_priv='Y',
    insert_priv='Y',
    update_priv='Y',
    delete_priv='Y',
    create_priv='Y',
    drop_priv='Y',
    index_priv='Y',
    alter_priv='Y',
    lock_tables_priv='Y',
    Create_tmp_table_priv='Y'
    ;
INSERT INTO user SET
    Host='localhost.localdomain',
    User="$DBNAME",
    Password=PASSWORD("$DBNAME"),
    select_priv='Y',
    insert_priv='Y',
    update_priv='Y',
    delete_priv='Y',
    create_priv='Y',
    drop_priv='Y',
    index_priv='Y',
    alter_priv='Y',
    lock_tables_priv='Y',
    Create_tmp_table_priv='Y'
    ;
INSERT INTO user SET
    Host='localhost',
    User="$DBNAME",
    Password=PASSWORD("$DBNAME"),
    select_priv='Y',
    insert_priv='Y',
    update_priv='Y',
    delete_priv='Y',
    create_priv='Y',
    drop_priv='Y',
    index_priv='Y',
    alter_priv='Y',
    lock_tables_priv='Y',
    Create_tmp_table_priv='Y'
    ;
DELETE FROM db WHERE db="$DBNAME";
INSERT INTO db SET
    host='127.0.0.1',
    db="$DBNAME",
    user="$DBNAME",
    select_priv='Y',
    insert_priv='Y',
    update_priv='Y',
    delete_priv='Y',
    create_priv='Y',
    drop_priv='Y',
    index_priv='Y',
    references_priv='Y',
    alter_priv='Y',
    lock_tables_priv='Y',
    Create_tmp_table_priv='Y'
    ;
INSERT INTO db SET
    host='localhost.localdomain',
    Db="$DBNAME",
    user="$DBNAME",
    select_priv='Y',
    insert_priv='Y',
    update_priv='Y',
    delete_priv='Y',
    create_priv='Y',
    drop_priv='Y',
    index_priv='Y',
    references_priv='Y',
    alter_priv='Y',
    lock_tables_priv='Y',
    Create_tmp_table_priv='Y'
    ;
INSERT INTO db SET
    host='localhost',
    Db="$DBNAME",
    user="$DBNAME",
    select_priv='Y',
    insert_priv='Y',
    update_priv='Y',
    delete_priv='Y',
    create_priv='Y',
    drop_priv='Y',
    index_priv='Y',
    references_priv='Y',
    alter_priv='Y',
    lock_tables_priv='Y',
    Create_tmp_table_priv='Y'
    ;
__EOF__
##

echo
echo "Reloading grant tables."
mysqladmin $FLAGS reload || exit 1

if [ ! -r "${PROGDIR}schema.sql" ]; then
    echo "Can't read schema.sql!  You'll have to populate the database yourself."
    exit
fi

##
## Populate the database schema
##
echo
echo "Now, we will populate the database with the schema."
echo "If the preceeding steps worked, you won't need to enter a password."

echo "Hit <RETURN> to populate the database, Control-C to cancel."
echo "(If you need to restore from a backup you don't want to populate.)"
read foo

echo mysql -u $DBNAME -p"$DBNAME" $DBNAME "<" ${PROGDIR}schema.sql
mysql -u"$DBNAME" -p"$DBNAME" "$DBNAME" < ${PROGDIR}schema.sql || exit 1
