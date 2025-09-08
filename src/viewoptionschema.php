<?php
// viewoptionschema.php -- HotCRP helper class for view options
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ViewOptionSchema {
    /** @var array<string,mixed> */
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
     * @return ?array{string,mixed} */
    static function validate_schema($name, $value, $schema) {
        if (isset($schema->enum)) {
            $xvalue = self::validate_enum($value, $schema->enum);
            return $xvalue ? [$name, $xvalue] : null;
        }
        $type = $schema->type ?? null;
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
     * @return ?array{string,object} */
    static function expand($x) {
        if (is_string($x)) {
            if (($sl = strpos($x, "/")) !== false) {
                $name = substr($x, 0, $sl);
                if ($x[$sl + 1] === "!") {
                    $schema = (object) ["alias" => substr($x, $sl + 2), "negated" => true];
                } else {
                    $schema = (object) ["alias" => substr($x, $sl + 1)];
                }
            } else if (($eq = strpos($x, "=")) !== false) {
                $name = substr($x, 0, $eq);
                $schema = (object) ["enum" => substr($x, $eq + 1), "lifted" => true];
            } else if (str_ends_with($x, "!")) {
                $name = substr($x, 0, -1);
                $schema = (object) ["type" => "string", "lifted" => true];
            } else if (str_ends_with($x, "\$")) {
                $name = substr($x, 0, -1);
                $schema = (object) ["type" => "string"];
            } else if (str_ends_with($x, "#")) {
                $name = substr($x, 0, -1);
                $schema = (object) ["type" => "int"];
            } else if (str_ends_with($x, "#+")) {
                $name = substr($x, 0, -2);
                $schema = (object) ["type" => "int", "min" => 1];
            } else if (($colon = strpos($x, ":")) !== false) {
                $name = substr($x, 0, $colon);
                $schema = (object) ["type" => substr($name, $colon + 1)];
            } else if ($x !== "") {
                $name = $x;
                $schema = (object) [];
            } else {
                return null;
            }
        } else if (is_object($x) && is_string($x->name ?? null)) {
            $name = $x->name;
            $schema = $x;
        } else {
            return null;
        }
        return [$name, $schema];
    }

    /** @param string|object $x
     * @return bool */
    function define_check($x) {
        $exp = self::expand($x);
        if (!$exp) {
            return false;
        }
        $this->a[$exp[0]] = $exp[1];
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
            if ($schema->negated ?? null) {
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
            if (isset($schema->enum)
                && ($schema->lifted ?? null)
                && ($xvalue = self::validate_enum($name, $schema->enum))) {
                $lifted = $lifted ?? [$aname, $xvalue];
                ++$nlifted;
            } else if (($schema->type ?? null) === "string"
                       && ($schema->lifted ?? null)) {
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
