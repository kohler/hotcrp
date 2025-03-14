#!/bin/bash

# backups/ has special 'new' and 'old' symbolic links to the last
# backup and the one-before-last backup.  We use them to check whether
# the new backup is identical to the previous backup. If it isn't, we
# send an email with the backup.

# If a backups/passphrase file exists, its content is used as a
# passphrase to encrypt the backup file before sending it by email.

# we assume the script is in the 'lib/' directory of a HotCRP install,
# move to the root of the project before running the code
cd "$( dirname "${BASH_SOURCE[0]}" )"/..

BACKUP=backup-$(date +%F-%T).txt
PASSPHRASE_FILE=passphrase

if [ -z "$BACKUP_EMAIL" ]
then
   echo "You must define a BACKUP_EMAIL environment variable"
   echo "with the email address to send backups to."
   exit 2
fi

dump () {
  # --skip-dump-date is necessary, otherwise a timestamp
  # is included in the backup and the files always differ
  ./lib/backupdb.sh --skip-dump-date > backups/$BACKUP
}

# assumes that 'dump ()' ran and that we are in 'backups'
init () {
  ln -s old $BACKUP
  ln -s new $BACKUP
}

# assumes that 'dump ()' ran and that we are in 'backups'
age () {
  rm $(readlink old)
  mv new old
  ln -s $BACKUP new
}

email () {
  echo "New backup $(readlink new) differs from $(readlink old), so it is attached" \
    | mailx -s "[hotcrp-mlocaml2017-postproceedings] new backup" -a $BACKUP_FILE $BACKUP_EMAIL
}

# assumes that 'dump ()' ran and that we are in 'backups'
email_if_changed () {
  echo "comparing the new backup $BACKUP and the previous one $(readlink old)"
  cmp new old && exit 0
  
  if [[ -f $PASSPHRASE_FILE ]]
  then
      echo "encrypting the backup according to backups/$PASSPHRASE_FILE"
      gpg --batch --passphrase-file $PASSPHRASE_FILE --symmetric $BACKUP
      BACKUP_FILE=$BACKUP.gpg
      email
      rm $BACKUP_FILE
  else
      BACKUP_FILE=$BACKUP
      email
  fi
}

mkdir_if_missing () {
 if [[ ! -d backups ]]
 then
     mkdir -p backups
     init
 fi
}

dump
cd backups
age
email_if_changed

