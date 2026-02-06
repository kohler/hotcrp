<?php
// getopt.php -- HotCRP helper function for extended getopt
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class Getopt {
    /** @var array<string,GetoptOption> */
    private $po = [];
    /** @var ?array<string,GetoptSubcommand> */
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
    /** @var ?bool */
    private $dupopt = true;
    /** @var bool */
    private $interleave = false;
    /** @var bool */
    private $require_subcommand = false;
    /** @var ?int */
    private $minarg;
    /** @var ?int */
    private $maxarg;

    // values matter
    const NOARG = 0;    // no argument
    const ARG = 1;      // mandatory argument
    const OPTARG = 2;   // optional argument
    const COUNTARG = 4; // no argument, but count number of times given
    const MARG = 9;     // multiple argument options (e.g., `-n a -n b -n c`)
    const MARG2 = 11;   // multiple arguments (e.g., `-n a -n b c`)
    const SOMEARGMASK = 3;
    const MARGMASK = 8;

    /** @param string $options
     * @return $this */
    function short($options) {
        $olen = strlen($options ?? "");
        for ($i = 0; $i !== $olen; ) {
            if (!ctype_alnum($options[$i])) {
                throw new ErrorException("Getopt::short: unexpected `{$options[$i]}`");
            }
            $opt = $options[$i];
            $t = self::NOARG;
            $d = 0;
            ++$i;
            if ($i >= $olen) {
                // end of string: no argument
            } else if ($options[$i] === ":") {
                $d = $i + 1 < $olen && $options[$i + 1] === ":" ? 2 : 1;
                $t = $d;
            } else if ($options[$i] === "#") {
                $d = 1;
                $t = self::COUNTARG;
            } else if ($i + 1 < $olen && $options[$i] === "[" && $options[$i + 1] === "]") {
                $d = $i + 2 < $olen && $options[$i + 2] === "+" ? 3 : 2;
                $t = $d === 3 ? self::MARG2 : self::MARG;
            }
            $i += $d;
            $this->define([$opt], new GetoptOption($opt, $t));
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
            // * `#` - no argument, but count number of times given
            // All `option`s must have the same argument description.
            // * `{ARGTYPE}` checks & transforms arguments. `{i}` means
            //    int, `{n}` means nonnegative int.
            // * `=ARGNAME` is used when generating help strings.
            // * `!SUBCOMMAND[,SUBCOMMAND]` limits subcommand applicability.

            $on = [];
            $ot = null;

            $len = strlen($s);
            if (($sp = strpos($s, " ")) === false) {
                $sp = $len;
            }
            $co = -1;
            $p = 0;
            while ($p < $sp) {
                if ($co < $p) {
                    if (($co = strpos($s, ",", $p)) === false || $co > $sp) {
                        $co = $sp;
                    }
                }
                if ($p === $co) {
                    $p = $co + 1;
                    continue;
                }
                $t = self::NOARG;
                $d = 0;
                $ech = $s[$co - 1];
                if ($p + 1 === $co) {
                    // single-character option
                } else if ($ech === ":") {
                    $d = $p + 2 < $co && $s[$co - 2] === ":" ? 2 : 1;
                    $t = $d;
                } else if ($ech === "#") {
                    $d = 1;
                    $t = self::COUNTARG;
                } else if ($p + 2 === $co) {
                    // no argument
                } else if ($ech === "]" && $s[$co - 2] === "[") {
                    $d = 2;
                    $t = self::MARG;
                } else if ($ech === "+" && $p + 3 < $co && $s[$co - 2] === "]" && $s[$co - 3] === "[") {
                    $d = 3;
                    $t = self::MARG2;
                }
                $n = substr($s, $p, $co - $p - $d);
                if ($ot !== null && $ot !== $t) {
                    throw new ErrorException("Getopt::long: option {$n} has conflicting argspec");
                }
                $on[] = $n;
                $ot = $t;
                $p = $co + 1;
            }

            if ($ot === null) {
                continue;
            }

            $po = new GetoptOption($on[0], $ot);
            $p = $sp + 1;
            while ($p < $len) {
                $ch = $s[$p];
                if (($sp = strpos($s, " ", $p)) === false) {
                    $sp = $len;
                }
                if ($ch === " ") {
                    $p = $sp + 1;
                } else if ($ch === "=") {
                    $po->argname = substr($s, $p + 1, $sp - $p - 1);
                } else if ($ch === "{"
                           && ($rbr = strpos($s, "}", $p + 1)) !== false
                           && $rbr + 1 === $sp) {
                    $po->argtype = substr($s, $p + 1, $rbr - $p - 1);
                } else if ($ch === "!") {
                    if ($p + 1 === $sp) {
                        $po->help = "!";
                    } else {
                        foreach (explode(",", substr($s, $p + 1, $sp - $p - 1)) as $subc) {
                            if ($subc !== "") {
                                $po->subcommands[] = $subc;
                            }
                        }
                    }
                } else {
                    break;
                }
                $p = $sp + 1;
            }
            if ($p < $len) {
                $po->help = substr($s, $p);
            }
            if (($ot & self::SOMEARGMASK) === 0
                && ($po->argtype !== null || $po->argname !== null)) {
                throw new ErrorException("Getopt::long: option {$po->name} should take argument");
            }

            $this->define($on, $po);
        }
        return $this;
    }

    /** @param list<string> $on
     * @param GetoptOption $po */
    private function define($on, $po) {
        $fresh = true;
        foreach ($on as $n) {
            if (isset($this->po[$n])) {
                $fresh = false;
                break;
            }
        }
        if ($fresh) {
            foreach ($on as $n) {
                $this->po[$n] = $po;
            }
        } else {
            foreach ($on as $n) {
                $pox = clone $po;
                $pox->next = $this->po[$n] ?? null;
                $this->po[$n] = $pox;
            }
        }
    }

    /** @param string $helpopt
     * @return $this */
    function helpopt($helpopt) {
        $this->helpopt = $helpopt;
        if (!isset($this->po[$helpopt])) {
            $this->long("{$helpopt} !");
        }
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

    /** @param ?bool $dupopt
     * @return $this */
    function dupopt($dupopt) {
        $this->dupopt = $dupopt;
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

    /** @param string|list<string>|true ...$subcommands
     * @return $this */
    function subcommand(...$subcommands) {
        foreach ($subcommands as $x) {
            if ($x === true) {
                $this->require_subcommand = true;
                continue;
            }
            $a = is_array($x) ? $x : [$x];
            foreach ($a as $s) {
                $this->subcommand_description($s, "");
            }
        }
        return $this;
    }

    /** @param string $name
     * @param string $description
     * @return $this */
    function subcommand_description($name, $description) {
        $namelen = strlen($name);
        if (($sp = strpos($name, " ")) === false) {
            $sp = $namelen;
        }
        $help = $sp + 1 < $namelen ? ltrim(substr($name, $sp + 1)) : "";
        if ($help === "" && ($nl = strpos($description, "\n")) > 0) {
            $help = substr($description, 0, $nl);
        }
        $sc = null;
        foreach (explode(",", substr($name, 0, $sp)) as $n) {
            if ($n === "") {
                continue;
            }
            if (!$sc) {
                $sc = $this->subcommand[$n] ?? null;
                if (!$sc || $sc->name !== $n) {
                    $sc = new GetoptSubcommand;
                    $sc->name = $n;
                }
                if ($help !== "") {
                    $sc->help = $help;
                }
                if ($description !== "") {
                    $sc->description = $description;
                }
            }
            $this->subcommand[$n] = $sc;
        }
        return $this;
    }

    /** @param string $opt
     * @param string $help
     * @param ?int $indent
     * @return string */
    static function format_help_line($opt, $help, $indent = null) {
        $indent = $indent ?? (24 + strspn($opt, " "));
        if (($help ?? "") === "") {
            $sep = "";
        } else if (strlen($opt) <= $indent) {
            $sep = str_repeat(" ", $indent - strlen($opt));
        } else {
            $sep = "\n" . str_repeat(" ", $indent);
        }
        return "{$opt}{$sep}{$help}\n";
    }

    /** @param string $t
     * @param GetoptOption $po
     * @param array<string,GetoptOptionHelp> &$od
     * @param string $subcommand */
    private function prepare_option_help($t, $po, &$od, $subcommand) {
        if ($po->help === "!") {
            return;
        }
        if (!$po->subcommands) {
            $prio = 1;
        } else if (in_array($subcommand, $po->subcommands, true)) {
            $prio = 0;
        } else {
            return;
        }
        $poh = $od[$po->name] ?? null;
        if ($poh === null) {
            $od[$po->name] = $poh = new GetoptOptionHelp;
            $argname = $po->argname ?? "ARG";
            if ($po->arg === self::ARG || $po->arg === self::MARG) {
                $poh->argspec = " {$argname}";
            } else if ($po->arg === self::MARG2) {
                $poh->argspec = " {$argname}...";
            } else if ($po->arg === self::OPTARG) {
                $poh->argspec = "[={$argname}]";
            }
            $poh->prio = $prio;
        }
        if ($po->help !== "" && $poh->help === "") {
            $poh->help = $po->help;
        }
        if (strlen($t) === 1 && $poh->short === null) {
            $poh->short = "-{$t}";
        } else if (strlen($t) !== 1 && $poh->long === null) {
            $poh->long = "--{$t}";
        }
    }

    /** @param null|string|array<string,mixed> $subarg
     * @return string */
    function help($subarg = null) {
        $subcommand = "";
        if (is_string($subarg)) {
            $subcommand = $subarg;
        } else if (is_array($subarg)) {
            if (!empty($subarg[$this->helpopt])) {
                $subcommand = $subarg[$this->helpopt];
            } else if (($subarg["_subcommand"] ?? "") === "{help}") {
                if (($x = $this->find_subcommand($subarg["_"][0] ?? null)) !== null) {
                    $subcommand = $x;
                }
            } else if (!empty($subarg["_subcommand"])) {
                $subcommand = $subarg["_subcommand"];
            }
        }
        $s = [];
        $description = $this->description;
        if ($subcommand !== ""
            && ($sc = $this->subcommand[$subcommand] ?? null)
            && $sc->description !== "") {
            $description = $sc->description;
        }
        if ($description !== "" && $description !== "!") {
            $s[] = $description;
            $s[] = str_ends_with($description, "\n") ? "\n" : "\n\n";
        }
        if ($subcommand === "") {
            $schelp = [];
            foreach ($this->subcommand ?? [] as $sc) {
                if ($sc->help !== "!") {
                    $schelp[] = self::format_help_line("  {$sc->name}", $sc->help);
                }
            }
            if (!empty($schelp)) {
                $schelp[] = "\n";
                array_push($s, "Subcommands:\n", ...$schelp);
            }
        }
        $od = [];
        '@phan-var array<string,GetoptOptionHelp> $od';
        foreach ($this->po as $t => $po) {
            while ($po) {
                $this->prepare_option_help($t, $po, $od, $subcommand);
                $po = $po->next;
            }
        }
        $sbp = [[], []];
        foreach ($od as $poh) {
            if ($poh->short !== null && $poh->long !== null) {
                $oax = "  {$poh->short}, {$poh->long}{$poh->argspec}";
            } else {
                $oa = $poh->short ?? $poh->long;
                $oax = "  {$oa}{$poh->argspec}";
            }
            $sbp[$poh->prio][] = self::format_help_line($oax, $poh->help);
        }
        foreach ($sbp as $prio => $sl) {
            if (empty($sl)) {
                continue;
            }
            $s[] = $prio ? ($subcommand ? "Global options:\n" : "Options:\n") : "{$subcommand} options:\n";
            array_push($s, ...$sl);
            $s[] = "\n";
        }
        if ($this->helpcallback
            && ($t = call_user_func($this->helpcallback, $subarg, $this, $subcommand) ?? "") !== "") {
            $s[] = rtrim($t) . "\n\n";
        }
        return join("", $s);
    }

    /** @param ?string $arg
     * @return ?string */
    function find_subcommand($arg) {
        if ($arg !== null && ($sc = $this->subcommand[$arg] ?? null)) {
            return $sc->name;
        } else if ($arg === "help" && $this->helpopt) {
            return "{help}";
        }
        return null;
    }

    /** @param ?string $s
     * @return bool */
    static function value_allowed($s) {
        return $s !== null;
    }

    /** @param GetoptOption $po
     * @param string $subcommand
     * @return bool */
    private function subcommand_match($po, $subcommand) {
        if ($po->subcommands
            && !in_array($subcommand, $po->subcommands, true)) {
            foreach ($po->subcommands as $sc) {
                if (isset($this->subcommand[$sc]))
                    return false;
            }
        }
        return true;
    }

    /** @param string $n
     * @param string $subcommand
     * @return ?GetoptOption */
    function find_option($n, $subcommand) {
        $po = $this->po[$n] ?? null;
        while ($po && !$this->subcommand_match($po, $subcommand)) {
            $po = $po->next;
        }
        return $po;
    }

    /** @param list<string> $argv
     * @param ?int $first_arg
     * @return array<string,string|int|float|list<string>> */
    function parse($argv, $first_arg = null) {
        $res = [];
        $rest = [];
        $pot = 0;
        $active_po = null;
        $oname = $name = "";
        $odone = false;
        $subcommand = "";
        $want_subcommand = $this->subcommand !== null;
        for ($i = $first_arg ?? 1; $i !== count($argv); ++$i) {
            $arg = $argv[$i];
            $po = null;
            $wantpo = $value = false;

            if ($odone) {
                // skip
            } else if ($arg === "--") {
                $odone = true;
                continue;
            } else if ($arg === "" || $arg === "-" || $arg[0] !== "-") { // non-option
                if ($want_subcommand) {
                    $want_subcommand = false;
                    if (($x = $this->find_subcommand($arg)) !== null) {
                        $res["_subcommand"] = $subcommand = $x;
                        continue;
                    }
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
                $po = $this->find_option($name, $subcommand);
                // `--help-SUBCOMMAND` translates to `--help=SUBCOMMAND`.
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
                    } else if (($pot & self::ARG) !== 0
                               && self::value_allowed($argv[$i + 1] ?? null)) {
                        $value = $argv[$i + 1];
                        ++$i;
                    }
                }
                $wantpo = true;
            } else if (ctype_alnum($arg[1])) { // short option
                $oname = "-{$arg[1]}";
                $po = $this->find_option($arg[1], $subcommand);
                if ($po) {
                    $name = $po->name;
                    $pot = $po->arg;
                    if (strlen($arg) > 2) {
                        if ($arg[2] === "=") {
                            $value = (string) substr($arg, 3);
                        } else if (($pot & self::SOMEARGMASK) !== 0) {
                            $value = substr($arg, 2);
                        } else {
                            $argv[$i] = "-" . substr($arg, 2);
                            --$i;
                        }
                    } else if (($pot & self::ARG) !== 0
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

            if ($value !== false) {
                if (($pot & self::SOMEARGMASK) === 0) {
                    throw new CommandLineException("`{$oname}` takes no argument", $this);
                }
            } else {
                if (($pot & self::ARG) !== 0) {
                    throw new CommandLineException("Missing argument for `{$oname}`", $this);
                }
                if ($pot === self::COUNTARG) {
                    $value = ($res[$name] ?? 0) + 1;
                }
            }

            $poty = $value !== false ? $po->argtype : null;
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

            if (!array_key_exists($name, $res) || $pot === self::COUNTARG) {
                $res[$name] = $pot & self::MARGMASK ? [$value] : $value;
            } else if ($pot < self::MARG && !$this->allmulti) {
                if (!$this->dupopt) {
                    throw new CommandLineException("`{$oname}` was given multiple times", $this);
                }
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
            && (isset($res[$this->helpopt]) || $subcommand === "{help}")) {
            fwrite(STDOUT, $this->help($res));
            exit(0);
        } else if ($this->require_subcommand && $subcommand === "") {
            $subcommands = [];
            foreach ($this->subcommand as $sc) {
                if ($sc->help !== "!")
                    $subcommands[] = $sc->name;
            }
            $cex = new CommandLineException("Subcommand required", $this);
            if (!empty($subcommands)) {
                $cex->add_context("Subcommands are " . join(", ", $subcommands));
            }
            throw $cex;
        } else if ($this->maxarg !== null && count($rest) > $this->maxarg) {
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
    /** @var 0|1|2|4|9|11 */
    public $arg;
    /** @var ?string */
    public $argtype;
    /** @var ?string */
    public $argname;
    /** @var ?list<string> */
    public $subcommands;
    /** @var ?string */
    public $help;
    /** @var ?GetoptOption */
    public $next;

    /** @param string $name
     * @param 0|1|2|4|9|11 $arg */
    function __construct($name, $arg) {
        $this->name = $name;
        $this->arg = $arg;
    }
}

class GetoptOptionHelp {
    /** @var ?string */
    public $short;
    /** @var ?string */
    public $long;
    /** @var string */
    public $argspec = "";
    /** @var string */
    public $help = "";
    /** @var int */
    public $prio = 1;
}

class GetoptSubcommand {
    /** @var string */
    public $name;
    /** @var string */
    public $help = "";
    /** @var string */
    public $description = "";
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
    /** @param string $filename
     * @return CommandLineException */
    static function make_file_error($filename) {
        $m = preg_replace('/\A.*:\s*(?=[^:]+\z)/', "", (error_get_last())["message"]);
        if (($filename ?? "") === "") {
            return new CommandLineException($m);
        }
        return new CommandLineException("{$filename}: {$m}");
    }
}
