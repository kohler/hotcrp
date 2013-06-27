#! /bin/sh
##
## Run MySQL on the database
##

usage () {
    echo "Usage: $PROG [MYSQL-OPTIONS]
       $PROG --show-password EMAIL" 1>&2
    exit 1
}

export PROG=$0
export FLAGS=
export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
show_password=
options_file=options.inc
while [ $# -gt 0 ]; do
    case "$1" in
    --show-password=*)
        test -z "$show_password" || usage
	show_password="`echo "+$1" | sed 's/^[^=]*=//'`";;
    --show-password)
	test "$#" -gt 1 -a -z "$show_password" || usage
	show_password="$2"; shift;;
    --help) usage;;
    -*)	FLAGS="$FLAGS $1";;
    *) usage;;
    esac
    shift
done

export PROGDIR=`echo "$0" | sed 's/[^\/]*$//'`

if [ ! -r "${PROGDIR}${options_file}" ]; then
    echo "runsql.sh: Can't read ${PROGDIR}${options_file}! Is this a CRP directory?" 1>&2
    exit 1
fi

getdbopt () {
    perl -e 'undef $/; $t = <STDIN>;
$t =~ s|/\*.*?\*/||gs;
$t =~ s|//.*$||gm;

sub unslash ($) {
   my($a) = @_;
   my($b) = "";
   while ($a ne "") {
      if ($a =~ m|\A\\|) {
         if ($a =~ m|\A\\([0-7]{1,3})(.*)\z|s) {
	    $b .= chr(oct($1));
	    $a = $2;
	 } elsif ($a =~ m |\A\\([nrftvb])(.*)\z|s) {
	    $b .= eval("\"\\$1\"");
	    $a = $2;
	 } else {
	    $b .= substr($a, 1, 1);
	    $a = substr($a, 2);
	 }
      } else {
	 $b .= substr($a, 0, 1);
         $a = substr($a, 1);
      }
   }
   $b;
}

while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*\"(([^\"\\]|\\.)*)\"&g) {
   $Opt{$1} = unslash($2);
}
while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*'"'"'([^'"'"']*)'"'"'&g) {
   $Opt{$1} = $2;
}
while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*([\d.]+)&g) {
   $Opt{$1} = $2;
}

sub fixshell ($) {
    my($a) = @_;
    $a =~ s|'"'"'|'"'"'"'"'"'"'"'"'|g;
    $a;
}

if ($Opt{"multiconference"}) {
   print STDERR "backupdb.sh: Not smart enough for multiconference yet\n";
   print "";
} elsif (exists($Opt{"dsn"}) || !exists($Opt{"dbName"})) {
   print "";
} else {
   $Opt{"dbUser"} = $Opt{"dbName"} if (!exists($Opt{"dbUser"}));
   $Opt{"dbPassword"} = $Opt{"dbName"} if (!exists($Opt{"dbPassword"}));
   print "'"'"'", fixshell($Opt{"'$1'"}), "'"'"'";
}' < ${PROGDIR}${options_file}
}

dbname="`getdbopt dbName 2>/dev/null`"
dbuser="`getdbopt dbUser 2>/dev/null`"
dbpass="`getdbopt dbPassword 2>/dev/null`"
test -z "$dbname" -o -z "$dbuser" -o -z "$dbpass" && { echo "runsql.sh: Cannot extract database run options from ${options_file}!" 1>&2; exit 1; }

dbopt="-u$dbuser"
dbopt_print="-u$dbuser -p<REDACTED>"
if test -n "$dbpass"; then
    PASSWORDFILE="`echo ".mysqlpwd.$$.$RANDOM" | sed 's/\.$//'`"
    if touch "$PASSWORDFILE"; then
        chmod 600 "$PASSWORDFILE"
        echo '[client]' >> "$PASSWORDFILE"
        echo 'password = '"$dbpass" >> "$PASSWORDFILE"
        dbopt="--defaults-extra-file=$PASSWORDFILE $dbopt"
        trap "rm -f $PASSWORDFILE" EXIT 2>/dev/null
    else
        PASSWORDFILE=""
        dbopt="$dbopt -p'$PASSWORD'"
    fi
fi

### Test mysql binary
if test -z "$MYSQL"; then
    MYSQL=mysql
    ! $MYSQL --version >/dev/null 2>&1 && mysql5 --version >/dev/null 2>&1 && MYSQL=mysql5
fi

if ! $MYSQL --version >/dev/null 2>&1; then
    echo "I can't find a working $MYSQL program." 1>&2
    echo "Set the MYSQL environment variable and try again." 1>&2
    exit 1
fi

sql_quote () {
    sed -e 's,\([\\"'"'"']\),\\\1,g' | sed -e 's,,\\Z,g'
}

if test -n "$show_password"; then
    show_password="`echo "+$show_password" | sed -e 's,^.,,' | sql_quote`"
    echo "select concat(email, ',', password) from ContactInfo where email like '$show_password' and disabled=0" | eval "$MYSQL $dbopt -N $FLAGS $dbname"
else
    eval "$MYSQL $dbopt $FLAGS $dbname"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
