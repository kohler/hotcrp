## dbhelper.sh -- shell program helpers for HotCRP database access
## HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
## Distributed under an MIT-like license; see LICENSE

echo_n () {
	# suns can't echo -n, and Mac OS X can't echo "x\c"
	echo "$@" | tr -d '
'
}

findoptions () {
    if test -n "$options_file" -a \( -r "$options_file" -o -n "$1" \); then echo "$options_file"
    elif test -n "$options_file"; then echo /dev/null; return 1
    elif test -r "${CONFDIR}options.php" -o -n "$1"; then echo "${CONFDIR}options.php"
    elif test -r "${CONFDIR}options.inc"; then echo "${CONFDIR}options.inc"
    elif test -r "${OLDCONFDIR}options.inc"; then echo "${OLDCONFDIR}options.inc"
    else echo /dev/null; return 1; fi
}

getdbopt () {
    (cd $MAINDIR && perl -e 'my(%Opt);
my($Confname) = "'"$CONFNAME"'";

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

sub process ($) {
   my($t) = @_;

   $t =~ s|/\*.*?\*/||gs;
   $t =~ s|//.*$||gm;

   while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*\"((?:[^\"\\]|\\.)*)\"&g) {
       $Opt{$1} = unslash($2);
   }
   while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*'"'"'([^'"'"']*)'"'"'&g) {
       $Opt{$1} = $2;
   }
   while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*([\d.]+|true)&g) {
       $Opt{$1} = $2;
   }
   while ($t =~ m&\$Opt\[['"'"'"](.*?)['"'"'"]\]\s*=\s*(?:array\(|\[)(.*)[\)\]]\s*;\s*$&gm) {
       my($n, $x, $a) = ($1, $2, []);
       while (1) {
           if ($x =~ m&\A[\s,]*\"((?:[^\"\\]|\\.)*)\"(.*)\z&) {
               push @$a, unslash($1);
               $x = $2;
           } elsif ($x =~ m&\A[\s,]*'"'"'([^'"'"']*)'"'"'(.*)\z&) {
               push @$a, $1;
               $x = $2;
           } else {
               last;
           }
       }
       $Opt{$n} = $a;
   }
}

undef $/;
process(<STDIN>);
if (exists($Opt{"include"})) {
    $Opt{"include"} = [$Opt{"include"}] if !ref $Opt{"include"};
    my($confname, @flist) = $Confname ? $Confname : $Opt{"dbName"};
    foreach my $f (@{$Opt{"include"}}) {
        $f =~ s,\$\{confname\}|\$confname\b,$confname,g;
        @flist = ($f =~ m,[\[\]\*\?], ? glob($f) : $f);
        foreach my $ff (@flist) {
            if (open(F, "<", $ff)) {
                process(<F>);
                close(F);
            } else {
                print STDERR "$ff: $!\n";
            }
        }
    }
}

sub fixshell ($) {
    my($a) = @_;
    $a =~ s|'"'"'|'"'"'"'"'"'"'"'"'|g;
    $a;
}

if ($Opt{"multiconference"} && $Confname ne "") {
   foreach my $i ("dbName", "dbUser", "dbPassword",
                  "sessionName", "downloadPrefix", "conferenceSite") {
       $Opt{$i} =~ s,\*|\*\{confname\}|\$confname\b,$Confname,g if exists($Opt{$i});
   }
}

if ("'$1'" =~ /^db/
    && (($Opt{"multiconference"} && $Confname eq "")
        || exists($Opt{"dsn"})
        || !exists($Opt{"dbName"}))) {
    print "";
} else {
    $Opt{"dbUser"} = $Opt{"dbName"} if !exists($Opt{"dbUser"}) && exists($Opt{"dbName"});
    $Opt{"dbPassword"} = $Opt{"dbName"} if !exists($Opt{"dbPassword"}) && exists($Opt{"dbName"});
    print "'"'"'", fixshell($Opt{"'$1'"}), "'"'"'";
}') < "`findoptions`"
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

parse_common_argument () {
    case "$1" in
    -c|--co|--con|--conf|--confi|--config)
        test "$#" -gt 1 -a -z "$options_file" || usage
        options_file="$2"; shift=2;;
    -c*)
        test -z "$options_file" || usage
        options_file="`echo "$1" | sed 's/^-c//'`"; shift=1;;
    --co=*|--con=*|--conf=*|--confi=*|--config=*)
        test -z "$options_file" || usage
        options_file="`echo "$1" | sed 's/^[^=]*=//'`"; shift=1;;
    -n|--n|--na|--nam|--name)
        test "$#" -gt 1 -a -z "$CONFNAME" || usage
        CONFNAME="$2"; shift=2;;
    -n*)
        test -z "$CONFNAME" || usage
        CONFNAME="`echo "$1" | sed 's/^-n//'`"; shift=1;;
    --n=*|--na=*|--nam=*|--name=*)
        test -z "$CONFNAME" || usage
        CONFNAME="`echo "$1" | sed 's/^-n//'`"; shift=1;;
    *)
        shift=0;;
    esac
}

get_dboptions () {
    dbname="`getdbopt dbName 2>/dev/null`"
    dbuser="`getdbopt dbUser 2>/dev/null`"
    dbpass="`getdbopt dbPassword 2>/dev/null`"
    if test -z "$dbname" -o -z "$dbuser" -o -z "$dbpass"; then
        echo "$1: Can't extract database options from `findoptions`!" 1>&2
        if test "`getdbopt multiconference 2>/dev/null`" '!=' "''"; then
            echo "This is a multiconference installation; check your '-n CONFNAME' option." 1>&2
        fi
        exit 1
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
