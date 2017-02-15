<?php
// ht.php -- HotCRP HTML helper functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Ht {

    public static $img_base = "";
    public static $default_button_class = "";
    private static $_controlid = 0;
    private static $_lastcontrolid = 0;
    private static $_stash = "";
    private static $_stash_inscript = false;
    private static $_stash_map = array();
    const ATTR_SKIP = 1;
    const ATTR_BOOL = 2;
    const ATTR_BOOLTEXT = 3;
    const ATTR_NOEMPTY = 4;
    private static $_attr_type = array("accept-charset" => self::ATTR_SKIP,
                                       "action" => self::ATTR_SKIP,
                                       "class" => self::ATTR_NOEMPTY,
                                       "disabled" => self::ATTR_BOOL,
                                       "enctype" => self::ATTR_SKIP,
                                       "method" => self::ATTR_SKIP,
                                       "name" => self::ATTR_SKIP,
                                       "optionstyles" => self::ATTR_SKIP,
                                       "spellcheck" => self::ATTR_BOOLTEXT,
                                       "type" => self::ATTR_SKIP,
                                       "value" => self::ATTR_SKIP);

    static function extra($js) {
        $x = "";
        if ($js)
            foreach ($js as $k => $v) {
                $t = get(self::$_attr_type, $k);
                if ($v === null
                    || $t === self::ATTR_SKIP
                    || ($v === false && $t !== self::ATTR_BOOLTEXT)
                    || ($v === "" && $t === self::ATTR_NOEMPTY))
                    /* nothing */;
                else if ($t === self::ATTR_BOOL)
                    $x .= ($v ? " $k=\"$k\"" : "");
                else if ($t === self::ATTR_BOOLTEXT && is_bool($v))
                    $x .= " $k=\"" . ($v ? "true" : "false") . "\"";
                else
                    $x .= " $k=\"" . str_replace("\"", "&quot;", $v) . "\"";
            }
        return $x;
    }

    static function script_file($src, $js = null) {
        if ($js && get($js, "crossorigin") && !preg_match(',\A([a-z]+:)?//,', $src))
            unset($js["crossorigin"]);
        return '<script src="' . htmlspecialchars($src) . '"' . self::extra($js) . '></script>';
    }

    static function stylesheet_file($src) {
        return "<link rel=\"stylesheet\" type=\"text/css\" href=\""
            . htmlspecialchars($src) . "\" />";
    }

    static function form($action, $extra = null) {
        $method = get($extra, "method") ? : "post";
        if ($method === "get" && strpos($action, "?") !== false)
            error_log(caller_landmark() . ": GET form action $action params will be ignored");
        $enctype = get($extra, "enctype");
        if (!$enctype && $method !== "get")
            $enctype = "multipart/form-data";
        $x = '<form method="' . $method . '" action="' . $action . '"';
        if ($enctype)
            $x .= ' enctype="' . $enctype . '"';
        return $x . ' accept-charset="UTF-8"' . self::extra($extra) . '>';
    }

    static function form_div($action, $extra = null) {
        $div = "<div";
        if (($x = get($extra, "divclass"))) {
            $div .= ' class="' . $x . '"';
            unset($extra["divclass"]);
        }
        if (($x = get($extra, "divstyle"))) {
            $div .= ' style="' . $x . '"';
            unset($extra["divstyle"]);
        }
        $div .= '>';
        if (strcasecmp(get_s($extra, "method"), "get") == 0
            && ($qpos = strpos($action, "?")) !== false) {
            if (($hpos = strpos($action, "#", $qpos + 1)) === false)
                $hpos = strlen($action);
            foreach (preg_split('/(?:&amp;|&)/', substr($action, $qpos + 1, $hpos - $qpos - 1)) as $m)
                if (($eqpos = strpos($m, "=")) !== false)
                    $div .= '<input type="hidden" name="' . substr($m, 0, $eqpos) . '" value="' . urldecode(substr($m, $eqpos + 1)) . '" />';
            $action = substr($action, 0, $qpos) . substr($action, $hpos);
        }
        return self::form($action, $extra) . $div;
    }

    static function hidden($name, $value = "", $extra = null) {
        return '<input type="hidden" name="' . htmlspecialchars($name)
            . '" value="' . htmlspecialchars($value) . '"'
            . self::extra($extra) . ' />';
    }

    static function select($name, $opt, $selected = null, $js = null) {
        if (is_array($selected) && $js === null)
            list($js, $selected) = array($selected, null);
        $disabled = get($js, "disabled");
        if (is_array($disabled))
            unset($js["disabled"]);
        $x = '<select name="' . $name . '"' . self::extra($js) . ">";
        if ($selected === null || !isset($opt[$selected]))
            $selected = key($opt);
        $optionstyles = get($js, "optionstyles", null);
        $optgroup = "";
        foreach ($opt as $value => $info) {
            if (is_array($info) && isset($info[0]) && $info[0] === "optgroup")
                $info = (object) array("type" => "optgroup", "label" => get($info, 1));
            else if (is_array($info))
                $info = (object) $info;
            else if (is_scalar($info)) {
                $info = (object) array("label" => $info);
                if (is_array($disabled) && isset($disabled[$value]))
                    $info->disabled = $disabled[$value];
                if ($optionstyles && isset($optionstyles[$value]))
                    $info->style = $optionstyles[$value];
            }
            if (isset($info->value))
                $value = $info->value;

            if ($info === null)
                $x .= '<option label=" " disabled="disabled"></option>';
            else if (isset($info->type) && $info->type === "optgroup") {
                $x .= $optgroup;
                if ($info->label) {
                    $x .= '<optgroup label="' . htmlspecialchars($info->label) . '">';
                    $optgroup = "</optgroup>";
                } else
                    $optgroup = "";
            } else {
                $x .= '<option value="' . $value . '"';
                if (strcmp($value, $selected) == 0)
                    $x .= ' selected="selected"';
                if (get($info, "disabled"))
                    $x .= ' disabled="disabled"';
                if (get($info, "style"))
                    $x .= ' style="' . $info->style . '"';
                if (get($info, "id"))
                    $x .= ' id="' . $info->id . '"';
                $x .= '>' . $info->label . '</option>';
            }
        }
        return $x . $optgroup . "</select>";
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
        if (!get($js, "id"))
            $js["id"] = "htctl" . ++self::$_controlid;
        self::$_lastcontrolid = $js["id"];
        if (!isset($js["class"]))
            $js["class"] = "cb";
        $t = '<input type="checkbox"'; /* NB see Ht::radio */
        if ($name)
            $t .= " name=\"$name\" value=\"" . htmlspecialchars($value) . "\"";
        if ($checked === null)
            $checked = isset($_REQUEST[$name]) && $_REQUEST[$name] === $value;
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

    static function label($html, $id = null, $js = null) {
        if (!$id || $id === true)
            $id = self::$_lastcontrolid;
        return '<label for="' . $id . '"' . self::extra($js) . '>' . $html . "</label>";
    }

    static function button($name, $html, $js = null) {
        if (!$js && is_array($html)) {
            $js = $html;
            $html = null;
        } else if (!$js)
            $js = array();
        if (!isset($js["class"]) && self::$default_button_class)
            $js["class"] = self::$default_button_class;
        $type = isset($js["type"]) ? $js["type"] : "button";
        if ($name && !$html) {
            $html = $name;
            $name = "";
        } else
            $name = $name ? " name=\"$name\"" : "";
        if ($type === "button" || preg_match("_[<>]_", $html) || isset($js["value"]))
            return "<button type=\"$type\"$name value=\""
                . get($js, "value", 1) . "\"" . self::extra($js)
                . ">" . $html . "</button>";
        else
            return "<input type=\"$type\"$name value=\"$html\""
                . self::extra($js) . " />";
    }

    static function submit($name, $html = null, $js = null) {
        if (!$js && is_array($html)) {
            $js = $html;
            $html = null;
        } else if (!$js)
            $js = array();
        $js["type"] = "submit";
        return self::button($html ? $name : "", $html ? : $name, $js);
    }

    static function js_button($html, $onclick, $js = null) {
        if (!$js && is_array($onclick)) {
            $js = $onclick;
            $onclick = null;
        } else if (!$js)
            $js = array();
        if ($onclick)
            $js["onclick"] = $onclick;
        return self::button("", $html, $js);
    }

    static function hidden_default_submit($name, $text = null, $js = null) {
        if (!$js && is_array($text)) {
            $js = $text;
            $text = null;
        } else if (!$js)
            $js = array();
        $js["class"] = trim(get_s($js, "class") . " hidden");
        return self::submit($name, $text, $js);
    }

    private static function apply_placeholder(&$value, &$js) {
        if (($temp = get($js, "placeholder"))) {
            if ($value === null || $value === "" || $value === $temp)
                $js["class"] = trim(get_s($js, "class") . " temptext");
            self::stash_script("jQuery(hotcrp_load.temptext)", "temptext");
        }
    }

    static function entry($name, $value, $js = null) {
        $js = $js ? $js : array();
        self::apply_placeholder($value, $js);
        $type = get($js, "type") ? : "text";
        return '<input type="' . $type . '" name="' . $name . '" value="'
            . htmlspecialchars($value === null ? "" : $value) . '"'
            . self::extra($js) . ' />';
    }

    static function entry_h($name, $value, $js = null) {
        $js = $js ? $js : array();
        if (!isset($js["onchange"]))
            $js["onchange"] = "hiliter(this)";
        return self::entry($name, $value, $js);
    }

    static function password($name, $value, $js = null) {
        $js = $js ? $js : array();
        $js["type"] = "password";
        return self::entry($name, $value, $js);
    }

    static function textarea($name, $value, $js = null) {
        $js = $js ? $js : array();
        self::apply_placeholder($value, $js);
        return '<textarea name="' . $name . '"' . self::extra($js)
            . '>' . htmlspecialchars($value === null ? "" : $value)
            . '</textarea>';
    }

    static function actions($actions, $js = array(), $extra_text = "") {
        if (empty($actions))
            return "";
        $actions = array_values($actions);
        $js = $js ? : array();
        if (!isset($js["class"]))
            $js["class"] = "aab";
        $t = "<div" . self::extra($js) . ">";
        foreach ($actions as $i => $a) {
            if ($a === "")
                continue;
            $t .= '<div class="aabut';
            if ($i + 1 < count($actions) && $actions[$i + 1] === "")
                $t .= ' aabutsp';
            $t .= '">';
            if (is_array($a)) {
                $t .= $a[0];
                if (count($a) > 1)
                    $t .= '<br /><span class="hint">' . $a[1] . '</span>';
            } else
                $t .= $a;
            $t .= '</div>';
        }
        return $t . $extra_text . "</div>\n";
    }

    static function pre($html) {
        if (is_array($html))
            $text = join("\n", $html);
        return "<pre>" . $html . "</pre>";
    }

    static function pre_text($text) {
        if (is_array($text)
            && array_keys($text) === range(0, count($text) - 1))
            $text = join("\n", $text);
        else if (is_array($text) || is_object($text))
            $text = var_export($text, true);
        return "<pre>" . htmlspecialchars($text) . "</pre>";
    }

    static function pre_text_wrap($text) {
        if (is_array($text) && !is_associative_array($text)
            && array_reduce($text, function ($x, $s) { return $x && is_string($s); }, true))
            $text = join("\n", $text);
        else if (is_array($text) || is_object($text))
            $text = var_export($text, true);
        return "<pre style=\"white-space:pre-wrap\">" . htmlspecialchars($text) . "</pre>";
    }

    static function pre_export($x) {
        return "<pre style=\"white-space:pre-wrap\">" . htmlspecialchars(var_export($x, true)) . "</pre>";
    }

    static function img($src, $alt, $js = null) {
        if (is_string($js))
            $js = array("class" => $js);
        if (self::$img_base && !preg_match(',\A(?:https?:/|/),i', $src))
            $src = self::$img_base . $src;
        return "<img src=\"" . $src . "\" alt=\"" . htmlspecialchars($alt) . "\""
            . self::extra($js) . " />";
    }

    static private function make_link($html, $href, $onclick, $js) {
        if (!$js)
            $js = [];
        if ($href && !isset($js["href"]))
            $js["href"] = $href;
        if ($onclick && !isset($js["onclick"]))
            $js["onclick"] = $onclick;
        if (isset($js["onclick"]) && !preg_match('/(?:^return|;)/', $js["onclick"]))
            $js["onclick"] = "return " . $js["onclick"];
        if (!get($js, "href"))
            $js["href"] = "#";
        return "<a" . self::extra($js) . ">" . $html . "</a>";
    }

    static function link($html, $href, $js = null) {
        if (!$js && is_array($href))
            return self::make_link($html, null, null, $href);
        else
            return self::make_link($html, $href, null, $js);
    }

    static function js_link($html, $onclick, $js = null) {
        if (!$js && is_array($onclick))
            return self::make_link($html, null, null, $onclick);
        else
            return self::make_link($html, null, $onclick, $js);
    }

    static function auto_link($html, $dest, $js = null) {
        if (!$js && is_array($dest))
            return self::make_link($html, null, null, $dest);
        else if ($dest && strcspn($dest, " ({") < strlen($dest))
            return self::make_link($html, null, $dest, $js);
        else
            return self::make_link($html, $dest, null, $js);
    }

    static function link_urls($html) {
        return preg_replace('@((?:https?|ftp)://(?:[^\s<>"&]|&amp;)*[^\s<>"().,:;&])(["().,:;]*)(?=[\s<>&]|\z)@s',
                            '<a href="$1" rel="noreferrer">$1</a>$2', $html);
    }

    static function check_stash($uniqueid) {
        return get(self::$_stash_map, $uniqueid, false);
    }

    static function mark_stash($uniqueid) {
        $marked = get(self::$_stash_map, $uniqueid);
        self::$_stash_map[$uniqueid] = true;
        return !$marked;
    }

    static function stash_html($html, $uniqueid = null) {
        if ($html !== null && $html !== false && $html !== ""
            && (!$uniqueid || self::mark_stash($uniqueid))) {
            if (self::$_stash_inscript)
                self::$_stash .= "</script>";
            self::$_stash .= $html;
            self::$_stash_inscript = false;
        }
    }

    static function stash_script($js, $uniqueid = null) {
        if ($js !== null && $js !== false && $js !== ""
            && (!$uniqueid || self::mark_stash($uniqueid))) {
            if (!self::$_stash_inscript)
                self::$_stash .= "<script>";
            else if (($c = self::$_stash[strlen(self::$_stash) - 1]) !== "}"
                     && $c !== "{" && $c !== ";")
                self::$_stash .= ";";
            self::$_stash .= $js;
            self::$_stash_inscript = true;
        }
    }

    static function unstash() {
        $stash = self::$_stash;
        if (self::$_stash_inscript)
            $stash .= "</script>";
        self::$_stash = "";
        self::$_stash_inscript = false;
        return $stash;
    }

    static function unstash_script($js) {
        self::stash_script($js);
        return self::unstash();
    }

    static function take_stash() {
        return self::unstash();
    }


    static function xmsg($type, $content) {
        if (substr($type, 0, 1) === "x")
            $type = substr($type, 1);
        if ($type === "error")
            $type = "merror";
        return '<div class="xmsg x' . $type . '"><div class="xmsg0"></div>'
            . '<div class="xmsgc">' . $content . '</div>'
            . '<div class="xmsg1"></div></div>';
    }

    static function ymsg($type, $content) {
        if (substr($type, 0, 1) === "x")
            $type = substr($type, 1);
        if ($type === "error")
            $type = "merror";
        return '<div class="ymsg x' . $type . '"><div class="xmsg0"></div>'
            . '<div class="ymsgc">' . $content . '</div>'
            . '<div class="xmsg1"></div></div>';
    }
}
