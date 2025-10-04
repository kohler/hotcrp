<?php
// viewoptiontype.php -- HotCRP helper class for view options
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ViewOptionType {
    /** @var string */
    public $name;
    /** @var string */
    public $type = "bool";
    /** @var ?string */
    public $argname;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $enum;
    /** @var ?string */
    public $alias;
    /** @var ?int */
    public $min;
    /** @var bool */
    public $lifted = false;
    /** @var bool */
    public $negated = false;
    /** @var bool */
    public $required = false;
    /** @var mixed */
    public $default;

    /** @param string|object $x
     * @return ?ViewOptionType */
    static function make($x) {
        $vot = new ViewOptionType;
        if (is_string($x)) {
            $pos = 0;
            $end = strpos($x, " ");
            if ($end === false) {
                $end = strlen($x);
            } else {
                $dpos = $end + 1;
                if ($dpos < strlen($x) && $x[$dpos] === "=") {
                    $sp = strlpos($x, " ", $dpos);
                    $vot->argname = substr($x, $dpos + 1, $sp - $dpos - 1);
                    $dpos = min(strlen($x), $sp + 1);
                }
                if ($dpos < strlen($x) && $x[$dpos] === "["
                    && ($rb = strpos($x, "]", $dpos + 1)) !== false) {
                    $vot->default = substr($x, $dpos + 1, $rb - $dpos - 1);
                    $dpos = $rb + ($rb + 1 < strlen($x) && $x[$rb + 1] === " " ? 2 : 1);
                }
                if ($dpos < strlen($x)) {
                    $vot->description = substr($x, $dpos);
                }
            }
            if ($pos < $end) {
                if ($x[$pos] === "!") {
                    $vot->required = true;
                    ++$pos;
                } else if ($x[$pos] === "?") {
                    $vot->required = false;
                    ++$pos;
                }
            }
            if ($pos < $end && $x[$end - 1] === "^") {
                $vot->lifted = true;
                --$end;
            }
            if (($sl = strpos($x, "/", $pos)) !== false
                && $sl < $end) {
                $vot->type = "alias";
                if ($x[$sl + 1] === "!") {
                    $vot->alias = substr($x, $sl + 2, $end - $sl - 2);
                    $vot->negated = true;
                } else {
                    $vot->alias = substr($x, $sl + 1, $end - $sl - 1);
                }
                $end = $sl;
            } else if (($eq = strpos($x, "=", $pos)) !== false
                       && $eq < $end) {
                $vot->type = "enum";
                $vot->enum = substr($x, $eq + 1, $end - $eq - 1);
                $end = $eq;
            } else {
                $ch = $pos < $end - 1 ? $x[$end - 1] : "";
                if ($ch === "\$") {
                    $vot->type = "string";
                    --$end;
                } else if ($ch === "#") {
                    $vot->type = "int";
                    --$end;
                } else if ($ch === "+" && $pos < $end - 2 && $x[$end - 2] === "#") {
                    $vot->type = "int";
                    $vot->min = 1;
                    $end -= 2;
                } else if (($colon = strpos($x, ":", $pos)) !== false
                           && $colon < $end) {
                    $vot->type = substr($x, $colon + 1, $end - $colon - 1);
                    if ($vot->type === "n") {
                        $vot->type = "int";
                        $vot->min = 0;
                    }
                    $end = $colon;
                }
            }
            if ($pos === $end) {
                return null;
            }
            $vot->name = substr($x, $pos, $end - $pos);
        } else if (is_object($x) && is_string($x->name ?? null)) {
            $vot->load($x);
        } else {
            return null;
        }
        return $vot;
    }

    /** @param object $x */
    private function load($x) {
        foreach ((array) $x as $k => $v) {
            if (property_exists($this, $k))
                $this->$k = $v;
        }
        if (isset($this->alias)) {
            $this->type = "alias";
        } else if (isset($this->enum)) {
            $this->type = "enum";
            if (is_array($this->enum)) {
                $this->enum = join("|", $this->enum);
            }
        }
    }


    /** @param mixed $value
     * @param string $enum
     * @return ?string */
    static function parse_enum($value, $enum) {
        if (is_bool($value)) {
            $value = $value ? "yes" : "no";
        } else {
            $value = (string) $value;
        }
        $vlen = strlen($value);
        $enumlen = strlpos($enum, " ");
        if ($vlen === 0 || $vlen > $enumlen) {
            return null;
        }
        $first = $enum[0] === "=" ? 1 : 0;
        $p = $first;
        while (($pf = strpos($enum, $value, $p)) !== false) {
            $ch0 = $pf === $first ? 124 /* '|' */ : ord($enum[$pf - 1]);
            $ch1 = $pf + $vlen === $enumlen ? 124 : ord($enum[$pf + $vlen]);
            if (($ch0 === 44 /* ',' */ || $ch0 === 124)
                && ($ch1 === 44 || $ch1 === 124)) {
                if (strpos($value, ",") !== false
                    || strpos($value, "|") !== false) {
                    return null;
                }
                if ($ch0 === 124) {
                    return $value;
                }
                if (($xch0 = strrpos($enum, "|", -($enumlen - $pf))) === false) {
                    $xch0 = -1;
                }
                $xch1 = strpos($enum, ",", $xch0 + 1);
                return substr($enum, $xch0 + 1, $xch1 - $xch0 - 1);
            }
            $p = $pf + $vlen;
        }
        return null;
    }

    /** @param mixed $value
     * @return ?mixed */
    function parse($value) {
        if (isset($this->enum)) {
            return self::parse_enum($value, $this->enum);
        } else if ($this->type === "string") {
            if (is_bool($value)) {
                $value = $value ? "yes" : "no";
            }
            return SearchWord::unquote((string) $value);
        } else if ($this->type === "int") {
            if (($iv = stoi($value)) !== null
                && (!isset($this->min) || $iv >= $this->min)) {
                return $iv;
            }
        } else if ($this->type === "tag") {
            if (is_string($value)
                && Tagger::basic_check($value)) {
                return $value;
            }
        } else { // boolean
            $bv = is_string($value) ? friendly_boolean($value) : $value;
            if (is_bool($bv)) {
                return $bv;
            }
        }
        return null;
    }


    /** @param string $enum
     * @return list<string> */
    static function split_enum($enum) {
        $result = [];
        $comma = -1;
        $pos = 0;
        $len = strlen($enum);
        while ($pos < $len) {
            $bar = strlpos($enum, "|", $pos);
            if ($comma < $pos) {
                $comma = strlpos($enum, ",", $pos);
            }
            $result[] = substr($enum, $pos, min($comma, $bar) - $pos);
            $pos = $bar + 1;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    function unparse_export() {
        $v = ["name" => $this->name, "type" => $this->type];
        if ($this->type === "alias") {
            $v["alias"] = $this->alias;
            if ($this->negated) {
                $v["negated"] = true;
            }
        } else if ($this->type === "enum") {
            $v["enum"] = self::split_enum($this->enum);
        } else if ($this->type === "int" && isset($this->min)) {
            $v["min"] = $this->min;
        }
        if ($this->required) {
            $v["required"] = true;
        }
        if ($this->lifted) {
            $v["lifted"] = true;
        }
        if ($this->description !== null) {
            $v["description"] = $this->description;
        }
        if ($this->default !== null) {
            if ($this->type === "boolean"
                && ($x = friendly_boolean($this->default)) !== null) {
                $v["default"] = $x;
            } else if ($this->type === "int"
                       && ($x = stoi($this->default)) !== null) {
                $v["default"] = $x;
            } else {
                $v["default"] = $this->default;
            }
        }
        return $v;
    }

    /** @param int $indent
     * @return string */
    function unparse_help_line($indent = 2) {
        if ($this->alias) {
            return "";
        }
        $s = str_repeat(" ", $indent) . $this->name . "=";
        if ($this->type === "bool") {
            $s .= "yes|no";
        } else if ($this->type === "enum") {
            $s .= join("|", ViewOptionType::split_enum($this->enum));
        } else if ($this->type === "int") {
            $s .= $this->argname ?? ($this->min >= 0 ? "N" : "NUM");
        } else if ($this->type === "string") {
            $s .= $this->argname ?? "ARG";
        } else {
            $s .= $this->argname ?? strtoupper($this->type);
        }
        $r = $this->required ? ($this->description ? "* " : "*") : "";
        return Getopt::format_help_line($s, $r . $this->description);
    }
}
