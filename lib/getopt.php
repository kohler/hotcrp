<?php
// getopt.php -- HotCRP helper function for extended getopt
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Getopt {
    /** @var array<string,array{string,0|1|2,?string,?string}> */
    private $po = [];
    /** @var ?string */
    private $helpopt;
    /** @var ?string */
    private $description;
    /** @var bool */
    private $allmulti = false;
    /** @var ?bool */
    private $otheropt;
    /** @var ?int */
    private $minarg;
    /** @var ?int */
    private $maxarg;

    /** @param string $options
     * @return $this */
    function short($options) {
        $olen = strlen($options ?? "");
        for ($i = 0; $i !== $olen; ) {
            if (ctype_alnum($options[$i])) {
                $opt = $options[$i];
                $type = 0;
                ++$i;
                if ($i < $olen && $options[$i] === ":") {
                    ++$i;
                    $type = 1;
                    if ($i < $olen && $options[$i] === ":") {
                        ++$i;
                        $type = 2;
                    }
                } else if ($i + 1 < $olen && $options[$i] === "[" && $options[$i+1] === "]") {
                    $i += 2;
                    $type = 3;
                }
                $this->po[$opt] = [$opt, $type, null, null];
            } else {
                throw new ErrorException("Getopt \$options");
            }
        }
        return $this;
    }

    /** @return $this */
    function long(...$longopts) {
        foreach ($longopts as $s) {
            if (is_array($s)) {
                $this->long(...$s);
                continue;
            }
            $help = $type = null;
            if (($sp = strpos($s, " ")) !== false) {
                $help = substr($s, $sp + 1);
                $s = substr($s, 0, $sp);
                if ($help !== "" && $help[0] === "{" && ($rbr = strpos($help, "}")) !== false) {
                    $type = substr($help, 1, $rbr - 1);
                    $help = $rbr === strlen($help) - 1 ? "" : ltrim(substr($help, $rbr + 1));
                }
            }
            $po = null;
            $p = 0;
            $l = strlen($s);
            while ($p < $l) {
                if (($co = strpos($s, ",", $p)) === false) {
                    $co = $l;
                }
                $t = $d = 0;
                if ($p + 1 < $co && $s[$co-1] === ":") {
                    $d = $t = $p + 2 < $co && $s[$co-2] === ":" ? 2 : 1;
                } else if ($p + 2 < $co && $s[$co-2] === "[" && $s[$co-1] === "]") {
                    $d = 2;
                    $t = 3;
                }
                if ($p + $d >= $co) {
                    throw new ErrorException("Getopt \$longopts");
                }
                $n = substr($s, $p, $co - $p - $d);
                $po = $po ?? [$n, $t, $type, $help];
                if ($t !== $po[1]) {
                    throw new ErrorException("Getopt \$longopts");
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

    /** @param ?bool $otheropt
     * @return $this */
    function otheropt($otheropt) {
        $this->otheropt = $otheropt;
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

    /** @return string */
    function help() {
        $s = [];
        if ($this->description) {
            $s[] = $this->description;
            if (!str_ends_with($this->description, "\n")) {
                $s[] = "\n\n";
            } else {
                $s[] = "\n";
            }
        }
        $od = [];
        foreach ($this->po as $t => $po) {
            $maint = $po[0];
            if (!isset($od[$maint])) {
                $help = $po[3];
                if (($help ?? "") !== ""
                    && $help[0] === "="
                    && preg_match('/\A=([A-Z]\S*)\s*/', $help, $m)) {
                    $argname = $m[1];
                    $help = substr($help, strlen($m[0]));
                } else {
                    $argname = "ARG";
                }
                if ($po[1] === 1 || $po[1] === 3) {
                    $arg = " {$argname}";
                } else if ($po[1] === 2) {
                    $arg = "[={$argname}]";
                } else {
                    $arg = "";
                }
                if ($help === null
                    && $this->helpopt === $maint) {
                    $help = "Print this message";
                }
                $od[$maint] = [null, null, $arg, $help];
            }
            $offset = strlen($t) === 1 ? 0 : 1;
            $od[$maint][$offset] = $od[$maint][$offset] ?? ($offset === 0 ? "-{$t}" : "--{$t}");
        }
        if (!empty($od)) {
            $s[] = "Options:\n";
            foreach ($od as $tx) {
                $help = $tx[3] ?? "";
                if ($help !== "!") {
                    if ($tx[0] !== null && $tx[1] !== null) {
                        $s[] = $oax = "  {$tx[0]}, {$tx[1]}{$tx[2]}";
                    } else {
                        $oa = $tx[0] ?? $tx[1];
                        $s[] = $oax = "  {$oa}{$tx[2]}";
                    }
                    if ($help !== "") {
                        if (strlen($oax) <= 24) {
                            $s[] = str_repeat(" ", 26 - strlen($oax));
                        } else {
                            $s[] = "\n" . str_repeat(" ", 26);
                        }
                        $s[] = $help;
                    }
                    $s[] = "\n";
                }
            }
            $s[] = "\n";
        }
        return join("", $s);
    }

    /** @param list<string> $argv
     * @return array<string,string|int|float|list<string>> */
    function parse($argv) {
        $res = [];
        $pot = 0;
        for ($i = 1; $i < count($argv); ++$i) {
            $arg = $argv[$i];
            if ($arg === "--") {
                ++$i;
                break;
            } else if ($arg === "-" || $arg[0] !== "-") {
                break;
            } else if ($arg[1] === "-") {
                $eq = strpos($arg, "=");
                $name = substr($arg, 2, ($eq ? $eq : strlen($arg)) - 2);
                if (!($po = $this->po[$name] ?? null)) {
                    if ($this->otheropt === true) {
                        $res["-"][] = $arg;
                        continue;
                    } else if ($this->otheropt === false) {
                        throw new CommandLineException("Unknown option `{$arg}`", $this);
                    } else {
                        break;
                    }
                }
                $oname = "--{$name}";
                $name = $po[0];
                $pot = $po[1];
                if ($eq !== false && $pot === 0) {
                    throw new CommandLineException("`{$oname}` takes no arguments", $this);
                } else if ($eq === false && $i === count($argv) - 1 && ($pot & 1) === 1) {
                    throw new CommandLineException("Missing argument for `{$oname}`", $this);
                }
                if ($eq !== false) {
                    $value = substr($arg, $eq + 1);
                } else if ($pot === 1 || $pot === 3) {
                    $value = $argv[$i + 1];
                    ++$i;
                } else {
                    $value = false;
                }
            } else if (ctype_alnum($arg[1])) {
                $oname = "-{$arg[1]}";
                if (!($po = $this->po[$arg[1]] ?? null)) {
                    if ($this->otheropt) {
                        $res["-"][] = $arg;
                        continue;
                    } else {
                        break;
                    }
                }
                $name = $po[0];
                $pot = $po[1];
                if (strlen($arg) === 2 && ($pot & 1) === 1 && $i === count($argv) - 1) {
                    throw new CommandLineException("Missing argument for `{$oname}`", $this);
                } else if ($pot === 0 || ($pot === 2 && strlen($arg) === 2)) {
                    $value = false;
                } else if (strlen($arg) > 2 && $arg[2] === "=") {
                    $value = substr($arg, 3);
                } else if (strlen($arg) > 2) {
                    $value = substr($arg, 2);
                } else {
                    $value = $argv[$i + 1];
                    ++$i;
                }
                if ($pot === 0 && strlen($arg) > 2) {
                    $argv[$i] = "-" . substr($arg, 2);
                    --$i;
                }
            } else {
                break;
            }
            $poty = $po[2];
            if ($poty === "n" || $poty === "i") {
                if (!ctype_digit($value) && !preg_match('/\A[-+]\d+\z/', $value)) {
                    throw new CommandLineException("`{$oname}` requires integer", $this);
                }
                $v = intval($value);
                if (("{$v}" !== $value
                     && "{$v}" !== preg_replace('/\A(|-)\+?0*(?=\d)/', '$1', $value))
                    || ($poty === "n" && $v < 0)) {
                    throw new CommandLineException("`{$oname}` out of range", $this);
                } else {
                    $value = $v;
                }
            } else if ($poty === "f") {
                if (!is_numeric($value)) {
                    throw new CommandLineException("`{$oname}` requires decimal number", $this);
                } else {
                    $value = floatval($value);
                }
            } else if ($poty !== null && $poty !== "s") {
                throw new ErrorException("Bad Getopt type `{$poty}` for `{$oname}`");
            }
            if (!array_key_exists($name, $res)) {
                $res[$name] = $pot === 3 ? [$value] : $value;
            } else if ($pot === 1 && !$this->allmulti) {
                $res[$name] = $value;
            } else if (is_array($res[$name])) {
                $res[$name][] = $value;
            } else {
                $res[$name] = [$res[$name], $value];
            }
        }
        if ($this->helpopt !== null && isset($res[$this->helpopt])) {
            fwrite(STDOUT, $this->help());
            exit(0);
        }
        $res["_"] = array_slice($argv, $i);
        if ($this->maxarg !== null && count($res["_"]) > $this->maxarg) {
            throw new CommandLineException("Too many arguments", $this);
        } else if ($this->minarg !== null && count($res["_"]) < $this->minarg) {
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

class CommandLineException extends Exception {
    /** @var ?Getopt */
    public $getopt;
    /** @param string $message
     * @param ?Getopt $getopt */
    function __construct($message, $getopt = null) {
        parent::__construct($message);
        $this->getopt = $getopt;
    }
}
