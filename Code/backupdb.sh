#! /bin/sh
##
## Back up the database, writing it to standard output
##

export PROG=$0
export FLAGS=""
structure=no
while [ $# -gt 0 ]; do
    case "$1" in
    --structure) structure=yes;;
    -*)	FLAGS="$FLAGS $1";;
    *)	echo "Usage: $PROG [--structure] [MYSQL-OPTIONS]" 1>&2; exit 1;;
    esac
    shift
done

export PROGDIR=`echo "$0" | sed 's/[^\/]*$//'`

if [ ! -r "${PROGDIR}options.inc" ]; then
    echo "backupdb.sh: Can't read options.inc!  Is this a CRP directory?" 1>&2
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
   print "-u'"'"'", fixshell($Opt{"dbUser"}), "'"'"' -p'"'"'", fixshell($Opt{"dbPassword"}), "'"'"' '"'"'", fixshell($Opt{"dbName"}), "'"'"'";
}' < ${PROGDIR}options.inc
}

dbopt=`getdbopt`
test -z "$dbopt" && { echo "backupdb.sh: Cannot extract database run options from options.inc!" 1>&2; exit 1; }

echo + mysqldump $FLAGS $dbopt 1>&2
if [ "$structure" = yes ]; then
    eval "mysqldump $FLAGS $dbopt | sed '/^LOCK\|^INSERT\|^UNLOCK\|^\/\*/d
/^)/s/AUTO_INCREMENT=[0-9]* //
/^--$/N
/^--.*-- Dumping data/N
/^--.*-- Dumping data.*--/d
/^-- Dump/d' "
else
    eval "mysqldump $FLAGS $dbopt"
fi
