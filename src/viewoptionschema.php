<?php
// viewoptionschema.php -- HotCRP helper class for view options
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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
        $enumlen = strlen($enum);
        if ($vlen === 0 || $vlen > $enumlen) {
            return null;
        }
        $first = $enum[0] === "=" ? 1 : 0;
        $p = $first;
        while (($pf = strpos($enum, $value, $p)) !== false) {
            $ch0 = $pf === $first ? " " : $enum[$pf - 1];
            $ch1 = $pf + $vlen === $enumlen ? " " : $enum[$pf + $vlen];
            if (($ch0 === " " || $ch0 === ",")
                && ($ch1 === " " || $ch1 === ",")) {
                if (strpos($value, " ") !== false || strpos($value, ",") !== false) {
                    return null;
                }
                if ($ch0 === " ") {
                    return $value;
                }
                if (($xch0 = strrpos($enum, " ", -($enumlen - $pf))) === false) {
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
        if (($schema->type ?? null) === "string") {
            if (is_bool($value)) {
                $value = $value ? "yes" : "no";
            }
            return [$name, SearchWord::unquote((string) $value)];
        }
        // else boolean
        if (is_string($value)) {
            $value = friendly_boolean($value);
        }
        return is_bool($value) ? [$name, $value] : null;
    }

    /** @param string|object $x
     * @return bool */
    function define_check($x) {
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
            } else if ($x !== "") {
                $name = $x;
                $schema = (object) [];
            } else {
                return false;
            }
        } else if (is_object($x) && is_string($x->name ?? null)) {
            $name = $x->name;
            $schema = $x;
        } else {
            return false;
        }
        $this->a[$name] = $schema;
        return true;
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
}
