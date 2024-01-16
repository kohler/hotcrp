<?php
// getopt.php -- HotCRP helper function for extended getopt
// Copyright (c) 2009-2023 Eddie Kohler; see LICENSE.

class Getopt {
    /** @var array<string,GetoptOption> */
    private $po = [];
    /** @var ?list<string> */
    private $subcommand;
    /** @var ?string */
    private $helpopt;
    /** @var ?callable(?array<string,mixed>,Getopt):(?string) */
    private $helpcallback;
    /** @var ?string */
    private $description;
    /** @var bool */
    private $allmulti = false;
    /** @var ?bool */
    private $otheropt = false;
    /** @var bool */
    private $interleave = false;
    /** @var ?int */
    private $minarg;
    /** @var ?int */
    private $maxarg;

    // values matter
    const NOARG = 0;   // no argument
    const ARG = 1;     // mandatory argument
    const OPTARG = 2;  // optional argument
    const MARG = 3;    // multiple argument options (e.g., `-n a -n b -n c`)
    const MARG2 = 5;   // multiple arguments (e.g., `-n a -n b c`)

    /** @param string $options
     * @return $this */
    function short($options) {
        $olen = strlen($options ?? "");
        for ($i = 0; $i !== $olen; ) {
            if (ctype_alnum($options[$i])) {
                $opt = $options[$i];
                $arg = self::NOARG;
                ++$i;
                if ($i < $olen && $options[$i] === ":") {
                    ++$i;
                    $arg = self::ARG;
                    if ($i < $olen && $options[$i] === ":") {
                        ++$i;
                        $arg = self::OPTARG;
                    }
                } else if ($i + 2 < $olen && $options[$i] === "[" && $options[$i+1] === "]" && $options[$i+2] === "+") {
                    $i += 3;
                    $arg = self::MARG2;
                } else if ($i + 2 < $olen && $options[$i] === "[" && $options[$i+1] === "]") {
                    $i += 2;
                    $arg = self::MARG;
                }
                $this->po[$opt] = new GetoptOption($opt, $arg, null, null);
            } else {
                throw new ErrorException("Getopt \$options");
            }
        }
        return $this;
    }

    /** @param string|list<string> ...$longopts
     * @return $this */
    function long(...$longopts) {
        foreach ($longopts as $s) {
            if (is_array($s)) {
                $this->long(...$s);
                continue;
            }
            // Format of a `long` string:
            // "option[,option,option] ['{'ARGTYPE'}'] ['='ARGNAME] [!SUBCOMMAND] HELPSTRING"
            // Each `option` can be followed by:
            // * `:` - single mandatory argument
            // * `::` - single optional argument
            // * `[]` - mandatory argument, option can be given multiple times
            // * `[]+` - mandatory argument, multiple times, can take multiple
            //   command line arguments (e.g., `-a a1 a2 a3 a4`)
            // All `option`s must have the same argument description.
            // * `{ARGTYPE}` checks & transforms arguments. `{i}` means
            //    int, `{n}` means nonnegative int.
            // * `=ARGNAME` is used when generating help strings.
            $help = $type = null;
            if (($sp = strpos($s, " ")) !== false) {
                $help = substr($s, $sp + 1);
                $s = substr($s, 0, $sp);
                $p = 0;
                while ($p < strlen($help)
                       && ($help[$p] === "=" || $help[$p] === "!")
                       && ($sp = strpos($help, " ", $p + 1)) !== false) {
                    $p = $sp + 1;
                }
                if ($p < strlen($help)
                    && $help[$p] === "{"
                    && ($rbr = strpos($help, "}", $p + 1)) !== false) {
                    $type = substr($help, $p + 1, $rbr - $p - 1);
                    $help = substr($help, 0, $p) . ltrim(substr($help, $rbr + 1));
                }
            }
            $po = null;
            $p = 0;
            $l = strlen($s);
            while ($p < $l) {
                if (($co = strpos($s, ",", $p)) === false) {
                    $co = $l;
                }
                $t = self::NOARG;
                $d = 0;
                if ($p + 1 < $co && $s[$co-1] === ":") {
                    $d = $t = $p + 2 < $co && $s[$co-2] === ":" ? 2 : 1;
                } else if ($p + 2 < $co && $s[$co-2] === "[" && $s[$co-1] === "]") {
                    $d = 2;
                    $t = self::MARG;
                } else if ($p + 3 < $co && $s[$co-3] === "[" && $s[$co-2] === "]" && $s[$co-1] === "+") {
                    $d = 3;
                    $t = self::MARG2;
                }
                $n = substr($s, $p, $co - $p - $d);
                $po = $po ?? new GetoptOption($n, $t, $type, $help);
                if ($t !== $po->arg) {
                    throw new ErrorException("Getopt::long: option {$n} has conflicting argspec");
                } else if ($t === 0 && ($type !== null || ($help !== null && str_starts_with($help, "=")))) {
                    throw new ErrorException("Getopt::long: option {$n} should take argument");
                }
                $this->po[$n] = $po;
                $p = $co + 1;
            }
        }
        return $this;
    }

    /** @param string $helpopt
     * @return $this */
    function helpopt($helpopt) {
        $this->helpopt = $helpopt;
        return $this;
    }

    /** @param ?callable(?array<string,mixed>,Getopt):(?string) $helpcallback
     * @return $this */
    function helpcallback($helpcallback) {
        $this->helpcallback = $helpcallback;
        return $this;
    }

    /** @param ?bool $otheropt
     * @return $this */
    function otheropt($otheropt) {
        $this->otheropt = $otheropt;
        return $this;
    }

    /** @param bool $interleave
     * @return $this */
    function interleave($interleave) {
        $this->interleave = $interleave;
        return $this;
    }

    /** @param ?int $n
     * @return $this */
    function minarg($n) {
        $this->minarg = $n;
        return $this;
    }

    /** @param ?int $n
     * @return $this */
    function maxarg($n) {
        $this->maxarg = $n;
        return $this;
    }

    /** @param string $d
     * @return $this */
    function description($d) {
        $this->description = $d;
        return $this;
    }

    /** @param bool $allmulti
     * @return $this */
    function allmulti($allmulti) {
        $this->allmulti = $allmulti;
        return $this;
    }

    /** @param string|list<string> ...$subcommands
     * @return $this */
    function subcommand(...$subcommands) {
        $this->subcommand = [];
        foreach ($subcommands as $s) {
            if (is_array($s)) {
                array_push($this->subcommand, ...$s);
            } else {
                $this->subcommand[] = $s;
            }
        }
        return $this;
    }

    /** @param string $opt
     * @param string $help
     * @return string */
    static function format_help_line($opt, $help) {
        if ($help === "") {
            $sep = "";
        } else if (strlen($opt) <= 24) {
            $sep = str_repeat(" ", 26 - strlen($opt));
        } else {
            $sep = "\n                          ";
        }
        return "{$opt}{$sep}{$help}\n";
    }

    /** @param string $matcher
     * @param string $subtype
     * @return bool */
    static function subtype_matches($matcher, $subtype) {
        $p = 0;
        $len = strlen($matcher);
        while ($p < $len) {
            if (($comma = strpos($matcher, ",", $p)) === false) {
                $comma = $len;
            }
            if (($negated = $p < $comma && $matcher[$p] === "!")) {
                ++$p;
            }
            $word = substr($matcher, $p, $comma - $p);
            if (fnmatch($word, $subtype) === !$negated) {
                return true;
            }
            $p = $comma + 1;
        }
        return false;
    }

    /** @param null|string|array<string,mixed> $arg
     * @return string */
    function help($arg = null) {
        if (is_string($arg)) {
            $subtype = $arg;
            $arg = null;
        } else if (is_array($arg)) {
            $subtype = $arg[$this->helpopt] ?? false;
        } else {
            $subtype = false;
        }
        if (($subtype === false || $subtype === "")
            && !empty($this->subcommand)
            && isset($arg["_subcommand"])) {
            $subtype = $arg["_subcommand"];
        }
        $s = [];
        if ($this->description) {
            $s[] = $this->description;
            if (!str_ends_with($this->description, "\n")) {
                $s[] = "\n\n";
            } else {
                $s[] = "\n";
            }
        }
        if (!empty($this->subcommand)
            && !isset($arg["_subcommand"])) {
            $s[] = "Subcommands:\n";
            foreach ($this->subcommand as $sc) {
                if (($space = strpos($sc, " ")) !== false) {
                    ++$space;
                } else {
                    $space = strlen($sc);
                }
                if (($comma = strpos($sc, ",")) === false
                    || $comma >= $space) {
                    $comma = $space;
                }
                $on = substr($sc, 0, $comma);
                $desc = ltrim(substr($sc, $space));
                if ($desc !== "!") {
                    $s[] = self::format_help_line("  {$on}", $desc);
                }
            }
            $s[] = "\n";
        }
        $od = [];
        '@phan-var array<string,array{?string,?string,string,string}> $od';
        foreach ($this->po as $t => $po) {
            $maint = $po->name;
            if ($po->help === null
                && $maint === $this->helpopt) {
                $help = "Print this message";
            } else {
                $help = $po->help ?? "";
            }
            if ($help !== ""
                && $help[0] === "="
                && preg_match('/\A=([A-Z]\S*)\s*/', $help, $m)) {
                $argname = $m[1];
                $help = substr($help, strlen($m[0]));
            } else {
                $argname = "ARG";
            }
            if ($help === "!") {
                continue;
            } else if (str_starts_with($help, "!")) {
                if (!$subtype
                    || ($space = strpos($help, " ")) === false
                    || !self::subtype_matches(substr($help, 1, $space - 1), $subtype)) {
                    continue;
                }
                $help = ltrim(substr($help, $space + 1));
                $prio = 0;
            } else {
                $prio = 1;
            }
            if (!isset($od[$maint])) {
                if ($po->arg === self::ARG || $po->arg === self::MARG) {
                    $arg = " {$argname}";
                } else if ($po->arg === self::MARG2) {
                    $arg = " {$argname}...";
                } else if ($po->arg === self::OPTARG) {
                    $arg = "[={$argname}]";
                } else {
                    $arg = "";
                }
                $od[$maint] = [null, null, $arg, $help, $prio];
            }
            if ($help !== "" && $od[$maint][3] === "") {
                $od[$maint][3] = $help;
            }
            if (strlen($t) === 1 && $od[$maint][0] === null) {
                $od[$maint][0] = "-{$t}";
            } else if (strlen($t) !== 1 && $od[$maint][1] === null) {
                $od[$maint][1] = "--{$t}";
            }
        }
        $sbp = [[], []];
        foreach ($od as $tx) {
            '@phan-var array{?string,?string,string,string,0|1} $tx';
            if ($tx[0] !== null && $tx[1] !== null) {
                $oax = "  {$tx[0]}, {$tx[1]}{$tx[2]}";
            } else {
                $oa = $tx[0] ?? $tx[1];
                $oax = "  {$oa}{$tx[2]}";
            }
            $sbp[$tx[4]][] = self::format_help_line($oax, $tx[3]);
        }
        foreach ($sbp as $prio => $sl) {
            if (empty($sl)) {
                continue;
            }
            $s[] = $prio ? ($subtype ? "Global options:\n" : "Options:\n") : "{$subtype} options:\n";
            array_push($s, ...$sl);
            $s[] = "\n";
        }
        if ($this->helpcallback
            && ($t = call_user_func($this->helpcallback, $arg, $this) ?? "") !== "") {
            $s[] = rtrim($t) . "\n\n";
        }
        return join("", $s);
    }

    /** @param string $arg
     * @return ?string */
    function find_subcommand($arg) {
        $len = strlen($arg);
        foreach ($this->subcommand as $sc) {
            $sclen = strpos($sc, " ");
            if ($sclen === false) {
                $sclen = strlen($sc);
            }
            $pos = 0;
            $epos1 = null;
            while ($pos !== $sclen) {
                $epos = strpos($sc, ",", $pos);
                if ($epos === false || $epos > $sclen) {
                    $epos = $sclen;
                }
                $epos1 = $epos1 ?? $epos;
                if ($epos - $pos === $len
                    && substr_compare($sc, $arg, $pos, $len) === 0) {
                    return $pos === 0 ? $arg : substr($sc, 0, $epos1);
                }
                $pos = $epos;
                if ($epos !== $sclen && $sc[$epos] === ",") {
                    ++$pos;
                }
            }
        }
        if ($this->helpopt && $arg === "help") {
            return "{help}";
        }
        return null;
    }

    /** @param ?string $s
     * @return bool */
    static function value_allowed($s) {
        return $s !== null;
    }

    /** @param list<string> $argv
     * @return array<string,string|int|float|list<string>> */
    function parse($argv) {
        $res = [];
        $rest = [];
        $pot = 0;
        $active_po = null;
        $oname = $name = "";
        $odone = false;
        for ($i = 1; $i !== count($argv); ++$i) {
            $arg = $argv[$i];
            $po = null;
            $wantpo = $value = false;

            if ($odone) {
                // skip
            } else if ($arg === "--") {
                $odone = true;
                continue;
            } else if ($arg[0] !== "-" || $arg === "-") { // non-option
                if ($this->subcommand !== null
                    && !array_key_exists("_subcommand", $res)
                    && ($x = $this->find_subcommand($arg)) !== null) {
                    $res["_subcommand"] = $x;
                    continue;
                }
                if ($active_po) {
                    $po = $active_po;
                    $name = $po->name;
                    $pot = $po->arg;
                    $value = $arg;
                }
            } else if ($arg[1] === "-") { // long option
                $eq = strpos($arg, "=");
                $name = substr($arg, 2, ($eq ? $eq : strlen($arg)) - 2);
                $oname = "--{$name}";
                $po = $this->po[$name] ?? null;
                // `--help-subtype` translates to `--help=subtype`.
                if (!$po
                    && $eq === false
                    && $this->helpopt
                    && str_starts_with($name, $this->helpopt . "-")
                    && isset($this->po[$this->helpopt])
                    && $this->po[$this->helpopt]->arg > 0) {
                    $po = $this->po[$this->helpopt];
                    $eq = 2 + strlen($this->helpopt);
                }
                if ($po) {
                    $name = $po->name;
                    $pot = $po->arg;
                    if ($eq !== false) {
                        $value = substr($arg, $eq + 1);
                    } else if (($pot === self::ARG || $pot >= self::MARG)
                               && self::value_allowed($argv[$i + 1] ?? null)) {
                        $value = $argv[$i + 1];
                        ++$i;
                    }
                }
                $wantpo = true;
            } else if (ctype_alnum($arg[1])) { // short option
                $oname = "-{$arg[1]}";
                $po = $this->po[$arg[1]] ?? null;
                if ($po) {
                    $name = $po->name;
                    $pot = $po->arg;
                    if (strlen($arg) > 2) {
                        if ($arg[2] === "=") {
                            $value = (string) substr($arg, 3);
                        } else if ($pot !== self::NOARG) {
                            $value = substr($arg, 2);
                        } else {
                            $argv[$i] = "-" . substr($arg, 2);
                            --$i;
                        }
                    } else if (($pot === self::ARG || $pot >= self::MARG)
                               && self::value_allowed($argv[$i + 1] ?? null)) {
                        $value = $argv[$i + 1];
                        ++$i;
                    }
                }
                $wantpo = true;
            } else {
                // skip
            }

            if (!$po) {
                if (!$wantpo || $this->otheropt === null) {
                    $rest[] = $arg;
                    $odone = $odone || !$this->interleave;
                } else if ($this->otheropt === false) {
                    throw new CommandLineException("Unknown option `{$oname}`", $this);
                } else {
                    $res["-"][] = $arg;
                }
                continue;
            }

            if ($value !== false && $pot === self::NOARG) {
                throw new CommandLineException("`{$oname}` takes no argument", $this);
            } else if ($value === false && ($pot & 1) === 1) {
                throw new CommandLineException("Missing argument for `{$oname}`", $this);
            }

            $poty = $po->argtype;
            if ($poty === "n" || $poty === "i") {
                if (!ctype_digit($value) && !preg_match('/\A[-+]\d+\z/', $value)) {
                    throw new CommandLineException("`{$oname}` requires integer", $this);
                }
                $v = intval($value);
                if (("{$v}" !== $value
                     && "{$v}" !== preg_replace('/\A(|-)\+?0*(?=\d)/', '$1', $value))
                    || ($poty === "n" && $v < 0)) {
                    throw new CommandLineException("`{$oname}` out of range", $this);
                }
                $value = $v;
            } else if ($poty === "f") {
                if (!is_numeric($value)) {
                    throw new CommandLineException("`{$oname}` requires decimal number", $this);
                }
                $value = floatval($value);
            } else if ($poty !== null && $poty !== "s") {
                throw new ErrorException("Bad Getopt type `{$poty}` for `{$oname}`");
            }

            if (!array_key_exists($name, $res)) {
                $res[$name] = $pot >= self::MARG ? [$value] : $value;
            } else if ($pot < self::MARG && !$this->allmulti) {
                $res[$name] = $value;
            } else if (is_array($res[$name])) {
                $res[$name][] = $value;
            } else {
                $res[$name] = [$res[$name], $value];
            }

            $active_po = $pot === self::MARG2 ? $po : null;
        }
        $res["_"] = $rest;
        if ($this->helpopt !== null
            && (isset($res[$this->helpopt]) || ($res["_subcommand"] ?? null) === "{help}")) {
            fwrite(STDOUT, $this->help($res));
            exit(0);
        }
        if ($this->maxarg !== null && count($rest) > $this->maxarg) {
            throw new CommandLineException("Too many arguments", $this);
        } else if ($this->minarg !== null && count($rest) < $this->minarg) {
            throw new CommandLineException("Too few arguments", $this);
        }
        return $res;
    }

    /** @return string */
    function short_usage() {
        $s = $this->description ?? "";
        if (($pos = strpos($s, "Usage: ")) === false) {
            return "";
        }
        $s = substr($s, $pos);
        if (($pos = strpos($s, "\n\n")) !== false) {
            return substr($s, 0, $pos + 1);
        } else {
            return rtrim($s) . "\n";
        }
    }

    /** @param list<string> $argv
     * @param string $options
     * @param list<string> $longopts
     * @return array<string,string|list<string>> */
    static function rest($argv, $options, $longopts) {
        return (new Getopt)->short($options)->long($longopts)->parse($argv);
    }
}

class GetoptOption {
    /** @var string */
    public $name;
    /** @var 0|1|2|3|5 */
    public $arg;
    /** @var ?string */
    public $argtype;
    /** @var ?string */
    public $help;

    /** @param string $name
     * @param 0|1|2|3|5 $arg
     * @param ?string $argtype
     * @param ?string $help */
    function __construct($name, $arg, $argtype, $help) {
        $this->name = $name;
        $this->arg = $arg;
        $this->argtype = $argtype;
        $this->help = $help;
    }
}

class CommandLineException extends Exception {
    /** @var ?Getopt */
    public $getopt;
    /** @var int */
    public $exitStatus;
    /** @var ?list<string> */
    public $context;
    /** @var int */
    static public $default_exit_status = 1;
    /** @param string $message
     * @param ?Getopt $getopt
     * @param ?int $exit_status */
    function __construct($message = "", $getopt = null, $exit_status = null) {
        parent::__construct($message);
        $this->getopt = $getopt;
        $this->exitStatus = $exit_status ?? self::$default_exit_status;
    }
    /** @param int $exit_status
     * @return $this
     * @deprecated */
    function exit_status($exit_status) {
        $this->exitStatus = $exit_status;
        return $this;
    }
    /** @param int $exit_status
     * @return $this */
    function set_exit_status($exit_status) {
        $this->exitStatus = $exit_status;
        return $this;
    }
    /** @param string ...$context
     * @return $this */
    function add_context(...$context) {
        $this->context = array_merge($this->context ?? [], $context);
        return $this;
    }
}
