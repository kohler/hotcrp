<?php
// getopt.php -- HotCRP helper function for extended getopt
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Getopt {
    /** @var array<string,array{string,0|1|2,?string}> */
    private $po = [];
    /** @var ?string */
    private $helpopt;
    /** @var ?string */
    private $description;
    /** @var bool */
    private $allmulti = false;

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
                $this->po[$opt] = [$opt, $type, null];
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
            $help = null;
            if (($sp = strpos($s, " ")) !== false) {
                $help = substr($s, $sp + 1);
                $s = substr($s, 0, $sp);
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
                $po = $po ?? [$n, $t, $help];
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
            $s[] = "\n";
        }
        $od = [];
        foreach ($this->po as $t => $po) {
            $n = strlen($t) === 1 ? "-{$t}" : "--{$t}";
            if ($po[1] === 1 || $po[1] === 3) {
                $n .= " ARG";
            } else if ($po[1] === 2) {
                $n .= "[=ARG]";
            }
            if (!isset($od[$po[0]])) {
                $help = $po[2];
                if ($help === null && $this->helpopt === $po[0]) {
                    $help = "Print this message";
                }
                $od[$po[0]] = ["  " . $n, $help];
            } else {
                $od[$po[0]][0] .= ", $n";
            }
        }
        $s[] = "Options:\n";
        foreach ($od as $tx) {
            if (($tx[1] ?? "") !== "") {
                $s[] = $tx[0];
                if (strlen($tx[0]) <= 22) {
                    $s[] = str_repeat(" ", 24 - strlen($tx[0]));
                } else {
                    $s[] = "\n" . str_repeat(" ", 24);
                }
                $s[] = $tx[1];
            } else {
                $s[] = $tx[0];
            }
            $s[] = "\n";
        }
        return join("", $s);
    }

    /** @param list<string> $argv
     * @return array<string,string|list<string>> */
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
                    break;
                }
                $name = $po[0];
                $pot = $po[1];
                if (($eq !== false && $pot === 0)
                    || ($eq === false && $i === count($argv) - 1 && ($pot === 1 || $pot === 3))) {
                    break;
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
                if (!($po = $this->po[$arg[1]] ?? null)) {
                    break;
                }
                $name = $po[0];
                $pot = $po[1];
                if (strlen($arg) == 2 && $pot === 1 && $i === count($argv) - 1) {
                    break;
                } else if ($pot === 0 || ($pot === 2 && strlen($arg) == 2)) {
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
        $res["_"] = array_slice($argv, $i);
        if ($this->helpopt !== null && isset($res[$this->helpopt])) {
            fwrite(STDOUT, $this->help());
            exit(0);
        }
        return $res;
    }

    /** @param ?int $exit_status */
    function usage($exit_status = null) {
        fwrite($exit_status ? STDERR : STDOUT, $this->help());
        if ($exit_status !== null) {
            exit($exit_status);
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
