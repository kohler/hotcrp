#! /bin/sh
##
## Restore the database from a backup
##

export PROG=$0
export FLAGS=""
input=
while [ $# -gt 0 ]; do
    case "$1" in
    -*)	FLAGS="$FLAGS $1";;
    *)	if [ -z "$input" ]; then input="$1"; else
	    echo "Usage: $PROG [MYSQL-OPTIONS] [FILE]" 1>&2; exit 1; fi;;
    esac
    shift
done

export PROGDIR=`echo "$0" | sed 's/[^\/]*$//'`

if [ ! -r "${PROGDIR}options.inc" ]; then
    echo "restoredb.sh: Can't read options.inc! Is this a CRP directory?" 1>&2
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
   print STDERR "restoredb.sh: Not smart enough for multiconference yet\n";
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
test -z "$dbopt" && { echo "restoredb.sh: Cannot extract database run options from options.inc!" 1>&2; exit 1; }

### Test mysql binary
if test -z "$MYSQL"; then
    MYSQL=mysql
    ! $MYSQL --version >/dev/null 2>&1 && mysql5 --version >/dev/null 2>&1 && MYSQL=mysql5
fi

if ! $MYSQL --version >/dev/null 2>&1; then
    echo "The $MYSQL binary doesn't appear to work."
    echo "Set the MYSQL environment variable and try again."
    exit 1
fi

if test -z "$input"; then
    echo + $MYSQL $FLAGS $dbopt 1>&2
    eval "$MYSQL $FLAGS $dbopt"
else
    echo + $MYSQL $FLAGS $dbopt "<" "$input" 1>&2
    eval "$MYSQL $FLAGS $dbopt" < "$input"
fi
