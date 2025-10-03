<?php
// viewoptionschema.php -- HotCRP helper class for view options
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ViewOptionSchema implements IteratorAggregate {
    /** @var array<string,ViewOptionType> */
    private $a = [];

    /** @param string|object ...$arg */
    function __construct(...$arg) {
        foreach ($arg as $x) {
            $this->define($x);
        }
    }

    /** @param mixed $value
     * @param string $enum
     * @return ?string
     * @deprecated */
    static function validate_enum($value, $enum) {
        return ViewOptionType::parse_enum($value, $enum);
    }

    /** @param string|object $x
     * @return bool */
    function define_check($x) {
        $exp = ViewOptionType::make($x);
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

    /** @param string $name
     * @return ?ViewOptionType */
    function get($name) {
        return $this->a[$name] ?? null;
    }

    #[\ReturnTypeWillChange]
    /** @return Iterator<string,mixed> */
    function getIterator() {
        return new ArrayIterator($this->a);
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
            $pvalue = $schema->parse($value);
            return $pvalue !== null ? [$name, $pvalue] : null;
        } else if ($value !== true) {
            return null;
        }

        // search for a lifted enum or a default string
        $lifted = $default = null;
        $nlifted = $ndefault = 0;
        foreach ($this->a as $aname => $schema) {
            if ($schema->enum
                && $schema->lifted
                && ($xvalue = $schema->parse($name))) {
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
        foreach ($this->a as $name => $vot) {
            if (!isset($vot->alias))
                $a[] = $name;
        }
        return $a;
    }

    /** @return list<ViewOptionType> */
    function help_order() {
        $ts = [];
        foreach ($this->a as $vot) {
            if (!isset($vot->alias))
                $ts[] = $vot;
        }
        return $ts;
    }
}
