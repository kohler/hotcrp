<?php

function session_textarray_split($name) {
    $a = explode(" ", $_SESSION[$name]);
    if ($a[0] === "")
	array_shift($a);
    return $a;
}

function session_textarray_set($name, $value, $on) {
    $a = session_textarray_split($name);
    $pos = array_search($value, $a);
    if ($on && $pos === false)
	$a[] = $value;
    else if (!$on && $pos !== false)
	array_splice($a, $pos, 1);
    if (count($a) != 0)
	$_SESSION[$name] = join(" ", $a);
    else
	unset($_SESSION[$name]);
}

