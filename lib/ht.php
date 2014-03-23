<?php
// ht.php -- HotCRP HTML helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Ht {

    private static $_controlid = 0;
    private static $_lastcontrolid = 0;

    static function extra($js) {
        $x = "";
        if ($js) {
            foreach (array("id", "tabindex", "onchange", "onclick", "onfocus",
                           "onblur", "onsubmit", "class", "style", "size",
                           "rows", "cols") as $k)
                if (isset($js[$k]))
                    $x .= " $k=\"" . str_replace("\"", "'", $js[$k]) . "\"";
            if (isset($js["disabled"]) && $js["disabled"])
                $x .= " disabled=\"disabled\"";
        }
        return $x;
    }

    static function form($action, $extra = null) {
        $method = $extra && isset($extra["method"]) ? $extra["method"] : "post";
        return '<form method="' . $method . '" action="'
            . $action . '" enctype="multipart/form-data" accept-charset="UTF-8"'
            . self::extra($extra) . '>';
    }

    static function hidden($name, $value = "", $extra = null) {
        return '<input type="hidden" name="' . htmlspecialchars($name)
            . '" value="' . htmlspecialchars($value) . '"'
            . self::extra($extra) . ' />';
    }

    static function select($name, $opt, $selected = null, $extra = null) {
        if (is_array($selected) && $extra === null)
            list($extra, $selected) = array($selected, null);
        $x = '<select name="' . $name . '"' . self::extra($extra) . ">";
        if ($selected === null || !isset($opt[$selected]))
            $selected = key($opt);
        $disabled = defval($extra, "disabled", null);
        $optionstyles = defval($extra, "optionstyles", null);
        $optgroup = "";
        foreach ($opt as $value => $info) {
            if (is_array($info) && $info[0] == "optgroup")
                $info = (object) array("type" => "optgroup", "label" => $info[1]);
            else if (is_string($info)) {
                $info = (object) array("label" => $info);
                if ($disabled && isset($disabled[$value]))
                    $info->disabled = $disabled[$value];
                if ($optionstyles && isset($optionstyles[$value]))
                    $info->style = $optionstyles[$value];
            }

            if ($info === null)
                $x .= '<option disabled="disabled"></option>';
            else if (isset($info->type) && $info->type == "optgroup") {
                $x .= $optgroup . '<optgroup label="' . htmlspecialchars($info->label) . '">';
                $optgroup = "</optgroup>";
            } else {
                $x .= '<option value="' . $value . '"';
                if (strcmp($value, $selected) == 0)
                    $x .= ' selected="selected"';
                if (isset($info->disabled) && $info->disabled)
                    $x .= ' disabled="disabled"';
                if (isset($info->style) && $info->style)
                    $x .= ' style="' . $info->style . '"';
                $x .= '>' . $info->label . '</option>';
            }
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
        if (is_array($value)) {
            $js = $value;
            $value = 1;
        } else if (is_array($checked)) {
            $js = $checked;
            $checked = false;
        }
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
        if (!$id || $id === true)
            $id = self::$_lastcontrolid;
        return '<label for="' . $id . '">' . $text . "</label>";
    }

    static function button($name, $text, $js = null) {
        if (!$js && is_array($text)) {
            $js = $text;
            $text = null;
        } else if (!$js)
            $js = array();
        if (!isset($js["class"]))
            $js["class"] = "b";
        $type = isset($js["type"]) ? $js["type"] : "button";
        if ($name && !$text) {
            $text = $name;
            $name = "";
        } else
            $name = $name ? " name=\"$name\"" : "";
        if (preg_match("_[<>]_", $text) || isset($js["value"]))
            return "<button type=\"$type\"$name value=\""
                . defval($js, "value", 1) . "\"" . self::extra($js)
                . ">" . $text . "</button>";
        else
            return "<input type=\"$type\"$name value=\"$text\""
                . self::extra($js) . " />";
    }

    static function submit($name, $text = null, $js = null) {
        if (!$js && is_array($text)) {
            $js = $text;
            $text = null;
        } else if (!$js)
            $js = array();
        $js["type"] = "submit";
        return self::button($text ? $name : "", $text ? $text : $name, $js);
    }

    static function entry($name, $value, $js = null) {
        $js = $js ? $js : array();
        if (($temp = @$js["hottemptext"])) {
            global $Conf;
            if ($value === null || $value === "" || $value === $temp)
                $js["class"] = trim(defval($js, "class", "") . " temptext");
            if ($value === null || $value === "")
                $value = $temp;
            $temp = ' hottemptext="' . htmlspecialchars($temp) . '"';
            $Conf->footerScript("hotcrp_load(hotcrp_load.temptext)", "temptext");
        } else
            $temp = "";
        return '<input type="text" name="' . $name . '" value="'
            . htmlspecialchars($value === null ? "" : $value) . '"'
            . self::extra($js) . $temp . ' />';
    }

    static function entry_h($name, $value, $js = null) {
        $js = $js ? $js : array();
        if (!isset($js["onchange"]))
            $js["onchange"] = "hiliter(this)";
        return self::entry($name, $value, $js);
    }

    static function textarea($name, $value, $js = null) {
        $js = $js ? $js : array();
        return '<textarea name="' . $name . '"' . self::extra($js)
            . '>' . htmlspecialchars($value === null ? "" : $value)
            . '</textarea>';
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

    static function pre($text) {
        if (is_array($text))
            $text = join("\n", $text);
        return "<pre>" . $text . "</pre>";
    }

    static function pre_h($text) {
        if (is_array($text))
            $text = join("\n", $text);
        else if (is_object($text))
            $text = var_export($text, true);
        return "<pre>" . htmlspecialchars($text) . "</pre>";
    }

}
