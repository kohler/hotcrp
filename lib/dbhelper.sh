## dbhelper.sh -- shell program helpers for HotCRP database access
## Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
         } elsif ($a =~ m|\A\\([nrftvb])(.*)\z|s) {
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
        $f =~ s,\$\{conf(?:id|name)\}|\$conf(?:id|name)\b,$confname,g;
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
       $Opt{$i} =~ s,\*|\*\{conf(?:id|name)\}|\$conf(?:id|name)\b,$Confname,g if exists($Opt{$i});
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

json_quote () {
    echo_n '"'
    perl -pe 's{([\\\"])}{\\$1}g;s{([\000-\017])}{sprintf("\\%03o", ord($1))}eg'
    # sed -e 's,\([\\"]\),\\\1,g' | tr -d '\n'
    echo_n '"'
}

check_mysqlish () {
    local m="`eval echo '$'$1`"
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
    myargs=""
    if test -n "$1"; then myargs=" -u$1"; fi
    local password="$2"
    if expr "$2" : "'" >/dev/null 2>&1; then :; else password="'$password'"; fi
    if test "$password" = "''"; then
        myargs_redacted="$myargs"
    else
        myargs_redacted="$myargs -p<REDACTED>"
        if test "$no_password_file" = true; then
            PASSWORDFILE=
        else
            PASSWORDFILE="`mktemp -q /tmp/hotcrptmp.XXXXXX`"
        fi
        if test -n "$PASSWORDFILE"; then
            echo "[client]" > "$PASSWORDFILE"
            chmod 600 "$PASSWORDFILE" # should be redundant
            echo "password=$password" >> "$PASSWORDFILE"
            myargs=" --defaults-extra-file=$PASSWORDFILE$myargs"
            trap "rm -f $PASSWORDFILE" EXIT 2>/dev/null
        else
            PASSWORDFILE=""
            myargs="$myargs -p$password"
        fi
    fi
    if test -n "$dbhost" -a "$dbhost" != "''"; then
        if expr "$dbhost" : "'" >/dev/null 2>&1; then
            myargs="$myargs -h$dbhost"
        else
            myargs="$myargs -h'$dbhost'"
        fi
    fi
}

generate_random_ints () {
    random="`head -c 48 /dev/urandom 2>/dev/null | tr -d '\000'`"
    test -z "$random" && random="`head -c 48 /dev/random 2>/dev/null | tr -d '\000'`"
    test -z "$random" && random="`openssl rand 48 2>/dev/null | tr -d '\000'`"
    echo "$random" | awk '
BEGIN { for (i = 0; i < 256; ++i) { ord[sprintf("%c", i)] = i; } }
{ for (i = 1; i <= length($0); ++i) { printf("%d\n", ord[substr($0, i, 1)]); } }'
    # generate some very low-quality random bytes in case all the
    # higher-quality mechanisms fail
    awk 'BEGIN { srand(); for (i = 0; i < 256; ++i) { printf("%d\n", rand() * 256); } }' < /dev/null
}

generate_password () {
    awk 'BEGIN {
    npwchars = split("a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =", pwchars, " ");
    pw = ""; nvow = 0;
}
{   x = ($0 % npwchars); if (x < 30) ++nvow;
    pw = pw pwchars[x + 1];
    if (length(pw) >= '"$1"' + nvow / 3) exit;
}
END { printf("%s\n", pw); }'
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
    --no-password-f|--no-password-fi|--no-password-fil|--no-password-file)
        no_password_file=true; shift=1;;
    *)
        shift=0;;
    esac
}

get_dboptions () {
    dbname="`getdbopt dbName 2>/dev/null`"
    dbuser="`getdbopt dbUser 2>/dev/null`"
    dbpass="`getdbopt dbPassword 2>/dev/null`"
    dbhost="`getdbopt dbHost 2>/dev/null`"
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
ETCDIR="`echo "${MAINDIR}etc/" | sed 's,^\./\(.\),\1,'`"
export MAINDIR LIBDIR CONFDIR OLDCONFDIR SRCDIR ETCDIR
