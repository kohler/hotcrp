#! /bin/sh
##
## Back up the database, writing it to standard output
##

export PROG=$0
export FLAGS=""
export LC_ALL=C LC_CTYPE=C LC_COLLATE=C
structure=false
pc=false
while [ $# -gt 0 ]; do
    case "$1" in
    --structure|--schema) structure=true;;
    --pc) pc=true;;
    -*)	FLAGS="$FLAGS $1";;
    *)	echo "Usage: $PROG [--schema] [--pc] [MYSQL-OPTIONS]" 1>&2; exit 1;;
    esac
    shift
done

export PROGDIR=`echo "$0" | sed 's/[^\/]*$//'`

if [ ! -r "${PROGDIR}options.inc" ]; then
    echo "backupdb.sh: Can't read options.inc! Is this a CRP directory?" 1>&2
    exit 1
fi

getdbopt () {
    password='fixshell($Opt{"dbPassword"})'
    if test -n "$1"; then password='"<REDACTED>"'; fi
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
   print "-u'"'"'", fixshell($Opt{"dbUser"}), "'"'"' -p'"'"'", '"$password"', "'"'"' '"'"'", fixshell($Opt{"dbName"}), "'"'"'";
}' < ${PROGDIR}options.inc
}

dbopt=`getdbopt`
dbopt_print=`getdbopt n`
test -z "$dbopt" && { echo "backupdb.sh: Cannot extract database run options from options.inc!" 1>&2; exit 1; }

### Test mysqldump binary
if test -z "$MYSQL"; then
    MYSQL=mysql
    ! $MYSQL --version >/dev/null 2>&1 && mysql5 --version >/dev/null 2>&1 && MYSQL=mysql5
fi
if test -z "$MYSQLDUMP"; then
    MYSQLDUMP=mysqldump
    ! $MYSQLDUMP --version >/dev/null 2>&1 && mysqldump5 --version >/dev/null 2>&1 && MYSQLDUMP=mysqldump5
fi

if ! $MYSQL --version >/dev/null 2>&1; then
    echo "The $MYSQL binary doesn't appear to work." 1>&2
    echo "Set the MYSQL environment variable and try again." 1>&2
    exit 1
fi
if ! $MYSQLDUMP --version >/dev/null 2>&1; then
    echo "The $MYSQLDUMP binary doesn't appear to work." 1>&2
    echo "Set the MYSQLDUMP environment variable and try again." 1>&2
    exit 1
fi

echo + $MYSQLDUMP $FLAGS $dbopt_print 1>&2
if $structure; then
    eval "$MYSQLDUMP $FLAGS $dbopt" | sed '/^LOCK/d
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
	eval "$MYSQLDUMP $FLAGS $dbopt --where='(roles & 7) != 0' ContactInfo"
	pcs=`echo 'select group_concat(contactId) from ContactInfo where (roles & 7) != 0' | eval "$MYSQL $FLAGS -N $dbopt"`
	eval "$MYSQLDUMP $FLAGS --where='contactId in ($pcs)' $dbopt ContactAddress ContactTag"
	eval "$MYSQLDUMP $FLAGS $dbopt PCMember Chair ChairAssistant Settings OptionType ChairTag TopicArea ReviewFormField ReviewFormOptions"
    else
	eval "$MYSQLDUMP $FLAGS $dbopt"
    fi
    echo
    echo "--"
    echo "-- Force HotCRP to invalidate server caches"
    echo "--"
    echo "INSERT INTO "'`Settings` (`name`,`value`)'" VALUES ('frombackup',UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=greatest(value,values(value));"
fi
