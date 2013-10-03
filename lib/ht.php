<?php
// ht.php -- HotCRP HTML helper functions
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Ht {

    private static $_controlid = 0;
    private static $_lastcontrolid = 0;

    static function extra($js) {
        $x = "";
        if ($js) {
            foreach (array("id", "tabindex", "onchange", "onclick", "onfocus",
                           "onblur", "onsubmit", "class", "style", "size") as $k)
                if (isset($js[$k]))
                    $x .= " $k=\"" . str_replace("\"", "'", $js[$k]) . "\"";
            if (isset($js["disabled"]) && $js["disabled"])
                $x .= " disabled=\"disabled\"";
        }
        return $x;
    }

    static function form($action, $extra = null) {
        return '<form method="post" action="'
            . $action . '" enctype="multipart/form-data" accept-charset="UTF-8"'
            . self::extra($extra) . '>';
    }

    static function hidden($name, $value = "", $extra = null) {
        return '<input type="hidden" name="' . htmlspecialchars($name)
            . '" value="' . htmlspecialchars($value) . '"'
            . self::extra($extra) . ' />';
    }

    static function select($name, $opt, $selected = null, $extra = null) {
        $x = '<select name="' . $name . '"' . self::extra($extra) . ">";
        if ($selected === null || !isset($opt[$selected]))
            $selected = key($opt);
        $optgroup = "";
        foreach ($opt as $value => $text)
            if ($text === null)
                $x .= '<option disabled="disabled"></option>';
            else if (!is_array($text)) {
                $x .= '<option value="' . $value . '"';
                if (strcmp($value, $selected) == 0)
                    $x .= ' selected="selected"';
                if ($extra && isset($extra["disabled"])
                    && defval($extra["disabled"], $value))
                    $x .= ' disabled="disabled"';
                if ($extra && isset($extra["optionstyles"])
                    && ($s = defval($extra["optionstyles"], $value)))
                    $x .= ' style="' . $s . '"';
                $x .= ">" . $text . "</option>";
            } else if ($text[0] == "optgroup") {
                $x .= $optgroup . "<optgroup label='" . $text[1] . "'>";
                $optgroup = "</optgroup>";
            }
        return $x . $optgroup . "</select>";
    }

    static function cbox($type, $bottom, $classextra = "") {
        if ($bottom)
            return "	<tr><td class='${type}cll'></td><td></td><td class='${type}clr'></td></tr>\n</table>";
        else
            return "<table class='${type}c" . ($classextra ? " $classextra" : "")
                . "'>\n";
    }

    static function checkbox($name, $value = 1, $checked = false, $js = null) {
        $js = $js ? $js : array();
        if (!defval($js, "id"))
            $js["id"] = "taggctl" . ++self::$_controlid;
        self::$_lastcontrolid = $js["id"];
        if (!isset($js["class"]))
            $js["class"] = "cb";
        $t = '<input type="checkbox"'; /* NB see Ht::radio */
        if ($name)
            $t .= " name=\"$name\" value=\"" . htmlspecialchars($value) . "\"";
        if ($checked === null)
            $checked = isset($_REQUEST[$name]) && $_REQUEST[$name] == $value;
        if ($checked)
            $t .= " checked=\"checked\"";
        return $t . self::extra($js) . " />";
    }

    static function radio($name, $value = 1, $checked = false, $js = null) {
        $t = self::checkbox($name, $value, $checked, $js);
        return '<input type="radio"' . substr($t, 22);
    }

    static function checkbox_h($name, $value = 1, $checked = false, $js = null) {
        $js = $js ? $js : array();
        if (!isset($js["onchange"]))
            $js["onchange"] = "hiliter(this)";
        return self::checkbox($name, $value, $checked, $js);
    }

    static function radio_h($name, $value = 1, $checked = false, $js = null) {
        $t = self::checkbox_h($name, $value, $checked, $js);
        return '<input type="radio"' . substr($t, 22);
    }

    static function label($text, $id = null) {
        if (!$id)
            $id = self::$_lastcontrolid;
        return '<label for="' . $id . '">' . $text . "</label>";
    }

    static function button($name, $text, $js = null) {
        $js = $js ? $js : array();
        if (!isset($js["class"]))
            $js["class"] = "b";
        $type = isset($js["type"]) ? $js["type"] : "button";
        if (isset($js["value"]))
            return "<button type=\"type\" name=\"$name\" value=\"" . $js["value"]
                . "\"" . self::extra($js) . ">" . $text . "</button>";
        else
            return "<input type=\"$type\" name=\"$name\" value=\"$text\""
                . self::extra($js) . " />";
    }

    static function submit($name, $text, $js = null) {
        $js = ($js ? $js : array());
        $js["type"] = "submit";
        return self::button($name, $text, $js);
    }

    static function actions($actions, $js = null, $extra = "") {
        $t = "<div class=\"aa\"" . self::extra($js) . ">";
        if (count($actions) > 1 || is_array($actions[0])) {
            $t .= "<table class=\"pt_buttons\"><tr>";
            $explains = 0;
            foreach ($actions as $a) {
                $t .= "<td class=\"ptb_button\">";
                if (is_array($a)) {
                    $t .= $a[0];
                    $explains += count($a) > 1;
                } else
                    $t .= $a;
                $t .= "</td>";
            }
            $t .= "</tr>";
            if ($explains) {
                $t .= "<tr>";
                foreach ($actions as $a) {
                    $t .= "<td class=\"ptb_explain\">";
                    if (is_array($a) && count($a) > 1)
                        $t .= $a[1];
                    $t .= "</td>";
                }
                $t .= "</tr>";
            }
            $t .= "</table>";
        } else
            $t .= $actions[0];
        return $t . $extra . "</div>\n";
    }

}
