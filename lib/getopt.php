<?php
// getopt.php -- HotCRP helper function for extended getopt
// HotCRP is Copyright (c) 2009-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function getopt_rest($argv, $options, $longopts = array()) {
    $res = array();
    for ($i = 1; $i < count($argv); ++$i) {
        $arg = $argv[$i];
        if ($arg === "--") {
            ++$i;
            break;
        } else if ($arg === "-" || $arg[0] !== "-")
            break;
        else if ($arg[1] === "-") {
            $eq = strpos($arg, "=");
            $name = substr($arg, 2, ($eq ? $eq : strlen($arg)) - 2);
            if (array_search($name, $longopts, true) !== false)
                $type = 0;
            else if (array_search($name . ":", $longopts, true) !== false)
                $type = 1;
            else if (array_search($name . "::", $longopts, true) !== false)
                $type = 2;
            else
                break;
            if (($eq !== false && $type === 0)
                || ($eq === false && $i === count($argv) - 1 && $type === 1))
                break;
            if ($eq !== false)
                $value = substr($arg, $eq + 1);
            else if ($type === 1) {
                $value = $argv[$i + 1];
                ++$i;
            } else
                $value = false;
        } else if (ctype_alnum($arg[1])) {
            $opos = strpos($options, $arg[1]);
            if ($opos === false)
                break;
            else if (substr($options, $opos + 1, 2) === "::")
                $type = 2;
            else if (substr($options, $opos + 1, 1) === ":")
                $type = 1;
            else
                $type = 0;
            if (strlen($arg) == 2 && $type === 1 && $i === count($argv) - 1)
                break;
            $name = $arg[1];
            if ($type === 0 || ($type === 2 && strlen($arg) == 2))
                $value = false;
            else if (strlen($arg) > 2 && $arg[2] === "=")
                $value = substr($arg, 3);
            else if (strlen($arg) > 2)
                $value = substr($arg, 2);
            else {
                $value = $argv[$i + 1];
                ++$i;
            }
            if ($type === 0 && strlen($arg) > 2) {
                $argv[$i] = "-" . substr($arg, 2);
                --$i;
            }
        } else
            break;
        if (!array_key_exists($name, $res))
            $res[$name] = $value;
        else if (is_array($res[$name]))
            $res[$name][] = $value;
        else
            $res[$name] = array($res[$name], $value);
    }
    $res["_"] = array_slice($argv, $i);
    return $res;
}
