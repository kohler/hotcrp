## dbhelper.sh -- shell program helpers for HotCRP database access
## HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

echo_n () {
	# suns can't echo -n, and Mac OS X can't echo "x\c"
	echo "$@" | tr -d '
'
}

findoptions () {
    if test -r "${CONFDIR}options.php"; then echo "${CONFDIR}options.php"
    elif test -r "${CONFDIR}options.inc"; then echo "${CONFDIR}options.inc"
    elif test -r "${OLDCONFDIR}options.inc"; then echo "${OLDCONFDIR}options.inc"; fi
}

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

if ($Opt{"multiconference"} || exists($Opt{"dsn"}) || !exists($Opt{"dbName"})) {
    print "";
} else {
    $Opt{"dbUser"} = $Opt{"dbName"} if (!exists($Opt{"dbUser"}));
    $Opt{"dbPassword"} = $Opt{"dbName"} if (!exists($Opt{"dbPassword"}));
    print "'"'"'", fixshell($Opt{"'$1'"}), "'"'"'";
}' < "`findoptions`"
}

sql_quote () {
    sed -e 's,\([\\"'"'"']\),\\\1,g' | sed -e 's,,\\Z,g'
}

check_mysqlish () {
    m="`eval echo '$'$1`"
    if test -n "$m"; then :;
    elif $2 --version >/dev/null 2>&1; then m=$2;
    elif ${2}5 --version >/dev/null 2>&1; then m=${2}5;
    else m=$2;
    fi

    if $m --version >/dev/null 2>&1; then :; else
        echo "I can't find a working $m program." 1>&2
        echo "Install MySQL, or set the $1 environment variable and try again." 1>&2
        exit 1
    fi
    eval ${1}="$m"
}

set_myargs () {
    if test -n "$1"; then myargs="-u$1"; else myargs=""; fi
    myargs_redacted="$myargs -p<REDACTED>"
    if test -n "$2"; then
        PASSWORDFILE="`mktemp -q /tmp/hotcrptmp.XXXXXX`"
        if test -n "$PASSWORDFILE"; then
            echo "[client]" >> "$PASSWORDFILE"
            echo "password = $2" >> "$PASSWORDFILE"
            chmod 600 "$PASSWORDFILE" # should be redundant
            myargs="--defaults-extra-file=$PASSWORDFILE $myargs"
            trap "rm -f $PASSWORDFILE" EXIT 2>/dev/null
        else
            PASSWORDFILE=""
            myargs="$myargs -p'$2'"
        fi
    fi
}

# slash-terminate LIBDIR
expr "$LIBDIR" : '.*[^/]$' >/dev/null && LIBDIR="$LIBDIR/"
# remove '/./' components
LIBDIR="`echo "$LIBDIR" | sed ':a
s,/\./,/,g
ta'`"
# set MAINDIR from LIBDIR
if test "$LIBDIR" = ./; then
    MAINDIR=../
elif expr "$LIBDIR" : '[^/]*[/]$' >/dev/null; then
    MAINDIR=./
elif ! expr "$LIBDIR" : '.*\.\.' >/dev/null; then
    MAINDIR="`echo "$LIBDIR" | sed 's,\(.*/\)[^/]*/$,\1,'`"
else
    MAINDIR="${LIBDIR}../"
fi
# set CONFDIR and SRCDIR from MAINDIR
CONFDIR="`echo "${MAINDIR}conf/" | sed 's,^\./\(.\),\1,'`"
OLDCONFDIR="`echo "${MAINDIR}Code/" | sed 's,^\./\(.\),\1,'`"
SRCDIR="`echo "${MAINDIR}src/" | sed 's,^\./\(.\),\1,'`"
export MAINDIR LIBDIR CONFDIR OLDCONFDIR SRCDIR
