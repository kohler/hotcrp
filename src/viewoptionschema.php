<?php
// viewoptionschema.php -- HotCRP helper class for view options
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ViewOptionSchema {
    /** @var array<string,ViewOptionType> */
    private $a = [];

    /** @param mixed $value
     * @param string $enum
     * @return ?string */
    static function validate_enum($value, $enum) {
        if (is_bool($value)) {
            $value = $value ? "yes" : "no";
        } else {
            $value = (string) $value;
        }
        $vlen = strlen($value);
        $enumlen = strpos($enum, " ");
        if ($enumlen === false) {
            $enumlen = strlen($enum);
        }
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

    /** @param string $name
     * @param mixed $value
     * @param ViewOptionType $schema
     * @return ?array{string,mixed} */
    static function validate_schema($name, $value, $schema) {
        if (isset($schema->enum)) {
            $xvalue = self::validate_enum($value, $schema->enum);
            return $xvalue ? [$name, $xvalue] : null;
        }
        $type = $schema->type;
        if ($type === "string") {
            if (is_bool($value)) {
                $value = $value ? "yes" : "no";
            }
            return [$name, SearchWord::unquote((string) $value)];
        } else if ($type === "int") {
            if (($iv = stoi($value)) !== null
                && (!isset($schema->min) || $iv >= $schema->min)) {
                return [$name, $iv];
            }
        } else if ($type === "tag") {
            if (is_string($value)
                && Tagger::basic_check($value)) {
                return [$name, $value];
            }
        } else { // boolean
            $bv = is_string($value) ? friendly_boolean($value) : $value;
            if (is_bool($bv)) {
                return [$name, $bv];
            }
        }
        return null;
    }

    /** @param string|object $x
     * @return bool */
    function define_check($x) {
        $exp = ViewOptionType::parse($x);
        if (!$exp) {
            return false;
        }
        $this->a[$exp->name] = $exp;
        return true;
    }

    /** @param string $name
     * @return bool */
    function has($name) {
        return isset($this->a[$name]);
    }

    /** @param string|object $x
     * @return $this */
    function define($x) {
        $this->define_check($x);
        return $this;
    }

    /** @param string $name
     * @param mixed $value
     * @return ?array{string,mixed} */
    function validate($name, $value) {
        $schema = $this->a[$name] ?? null;
        while ($schema !== null && isset($schema->alias)) {
            $name = $schema->alias;
            if ($schema->negated) {
                if (($value = friendly_boolean($value)) === null) {
                    return null;
                }
                $value = !$value;
            }
            $schema = $this->a[$name] ?? null;
        }
        if ($schema) {
            return self::validate_schema($name, $value, $schema);
        } else if ($value !== true) {
            return null;
        }

        // search for a lifted enum or a default string
        $lifted = $default = null;
        $nlifted = $ndefault = 0;
        foreach ($this->a as $aname => $schema) {
            if ($schema->enum
                && $schema->lifted
                && ($xvalue = self::validate_enum($name, $schema->enum))) {
                $lifted = $lifted ?? [$aname, $xvalue];
                ++$nlifted;
            } else if ($schema->type === "string"
                       && $schema->lifted) {
                $default = $default ?? [$aname, $name];
                ++$ndefault;
            }
        }
        if ($nlifted > 1 || ($nlifted === 0 && $ndefault > 1)) {
            return null;
        }
        return $lifted ?? $default;
    }

    /** @return list<string> */
    function keys() {
        $a = [];
        foreach ($this->a as $name => $schema) {
            if (!isset($schema->alias))
                $a[] = $name;
        }
        return $a;
    }
}

class ViewOptionType {
    /** @var string */
    public $name;
    /** @var string */
    public $type = "bool";
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

    /** @param string|object $x
     * @return ?ViewOptionType */
    static function parse($x) {
        $vot = new ViewOptionType;
        if (is_string($x)) {
            if (($sl = strpos($x, "/")) !== false) {
                $vot->name = substr($x, 0, $sl);
                $vot->type = "alias";
                if ($x[$sl + 1] === "!") {
                    $vot->alias = substr($x, $sl + 2);
                    $vot->negated = true;
                } else {
                    $vot->alias = substr($x, $sl + 1);
                }
            } else if (($eq = strpos($x, "=")) !== false) {
                $vot->name = substr($x, 0, $eq);
                $vot->type = "enum";
                $vot->enum = substr($x, $eq + 1);
                $vot->lifted = true;
            } else if (str_ends_with($x, "!")) {
                $vot->name = substr($x, 0, -1);
                $vot->type = "string";
                $vot->lifted = true;
            } else if (str_ends_with($x, "\$")) {
                $vot->name = substr($x, 0, -1);
                $vot->type = "string";
            } else if (str_ends_with($x, "#")) {
                $vot->name = substr($x, 0, -1);
                $vot->type = "int";
            } else if (str_ends_with($x, "#+")) {
                $vot->name = substr($x, 0, -2);
                $vot->type = "int";
                $vot->min = 1;
            } else if (($colon = strpos($x, ":")) !== false) {
                $vot->name = substr($x, 0, $colon);
                $vot->type = substr($x, $colon + 1);
            } else if ($x !== "") {
                $vot->name = $x;
            } else {
                return null;
            }
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

    /** @return array<string,mixed> */
    function unparse_export() {
        $v = ["name" => $this->name, "type" => $this->type];
        if ($this->type === "alias") {
            $v["alias"] = $this->alias;
            if ($this->negated) {
                $v["negated"] = true;
            }
        } else if ($this->type === "enum") {
            $v["enum"] = [];
            $comma = -1;
            $pos = 0;
            $len = strlen($this->enum);
            while ($pos < $len) {
                $bar = strlpos($this->enum, "|", $pos);
                if ($comma < $pos) {
                    $comma = strlpos($this->enum, ",", $pos);
                }
                $v["enum"][] = substr($this->enum, $pos, min($comma, $bar) - $pos);
                $pos = $bar + 1;
            }
        } else if ($this->type === "int" && isset($this->min)) {
            $v["min"] = $this->min;
        }
        if ($this->lifted) {
            $v["lifted"] = true;
        }
        return $v;
    }
}
