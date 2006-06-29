#!/bin/sh
##
## Create the database. The assumption is that database
## name and user name and password are all the same
##

export PROG=$0
export FLAGS="$@"

echo "This will create the database for your conference."
echo "The assumption is that the database name and name used to"
echo "attach to the database AND the password are all the same"
echo "The database is created with access only from the local host."
echo ""
echo "Note: this will delete any existing database and user "
echo "with the specified name -- make certain this is what you want"


echo -n "Enter database name: "
read DBNAME
echo "DBNAME is $DBNAME"

echo "Creating database. "
echo "This attmpts to connect to mysql without a password"
echo "This should work if you haven't changed the default management"
echo "password for mysql. If you have, you will need to exit"
echo "and run ``$PROG -p'' to specify a password, which will then"
echo "need to be the MySQL administrator password"
mysqladmin $FLAGS create $DBNAME

echo "Creating $DBNAME user and password"
echo "You will be asked for a password -- this is the MySQL admin password"
mysql $FLAGS mysql <<__EOF__
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
    alter_priv='Y'
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
    alter_priv='Y'
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
    alter_priv='Y'
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
    alter_priv='Y'
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
    alter_priv='Y'
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
    alter_priv='Y'
    ;
__EOF__
##

echo "Now reloading the grant tables.."
mysqladmin $FLAGS reload

##
## Populate the database schema
##
echo "Now, we will populate the database with the schema."
echo "If the preceeding steps worked, you won't need to"
echo "enter a password"

echo "Hit ^C if you don't want to populate the DB, <RETURN> otherwise"
echo "(if you need to restore from a backup you don't want to populate)"
read foo

echo mysql -u $DBNAME -p"$DBNAME" -h 127.0.0.1 $DBNAME 
mysql -u $DBNAME -p"$DBNAME" -h 127.0.0.1 $DBNAME  < ./conference.sql
