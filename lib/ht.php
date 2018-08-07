<?php
// ht.php -- HotCRP HTML helper functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Ht {

    public static $img_base = "";
    public static $default_button_class = "";
    private static $_script_open = "<script";
    private static $_controlid = 0;
    private static $_lastcontrolid = 0;
    private static $_stash = "";
    private static $_stash_inscript = false;
    private static $_stash_map = [];
    private static $_control_classes = [];
    const ATTR_SKIP = 1;
    const ATTR_BOOL = 2;
    const ATTR_BOOLTEXT = 3;
    const ATTR_NOEMPTY = 4;
    private static $_attr_type = array("accept-charset" => self::ATTR_SKIP,
                                       "action" => self::ATTR_SKIP,
                                       "checked" => self::ATTR_BOOL,
                                       "class" => self::ATTR_NOEMPTY,
                                       "disabled" => self::ATTR_BOOL,
                                       "enctype" => self::ATTR_SKIP,
                                       "formnovalidate" => self::ATTR_BOOL,
                                       "method" => self::ATTR_SKIP,
                                       "multiple" => self::ATTR_BOOL,
                                       "novalidate" => self::ATTR_BOOL,
                                       "optionstyles" => self::ATTR_SKIP,
                                       "spellcheck" => self::ATTR_BOOLTEXT,
                                       "readonly" => self::ATTR_BOOL,
                                       "type" => self::ATTR_SKIP);

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
                    $x .= ($v ? " $k" : "");
                else if ($t === self::ATTR_BOOLTEXT && is_bool($v))
                    $x .= " $k=\"" . ($v ? "true" : "false") . "\"";
                else
                    $x .= " $k=\"" . str_replace("\"", "&quot;", $v) . "\"";
            }
        return $x;
    }

    static function set_script_nonce($nonce) {
        if ((string) $nonce === "")
            self::$_script_open = '<script';
        else
            self::$_script_open = '<script nonce="' . htmlspecialchars($nonce) . '"';
    }

    static function script($script) {
        return self::$_script_open . '>' . $script . '</script>';
    }

    static function script_file($src, $js = null) {
        if ($js && get($js, "crossorigin") && !preg_match(',\A([a-z]+:)?//,', $src))
            unset($js["crossorigin"]);
        return self::$_script_open . ' src="' . htmlspecialchars($src) . '"' . self::extra($js) . '></script>';
    }

    static function stylesheet_file($src) {
        return "<link rel=\"stylesheet\" type=\"text/css\" href=\""
            . htmlspecialchars($src) . "\" />";
    }

    static function form($action, $extra = null) {
        if (is_array($action)) {
            $extra = $action;
            $action = get($extra, "action", "");
        }

        // GET method requires special handling: extract params from URL
        // and render as hidden inputs
        $suffix = ">";
        $method = get($extra, "method") ? : "post";
        if ($method === "get"
            && ($qpos = strpos($action, "?")) !== false) {
            $pos = $qpos + 1;
            while ($pos < strlen($action)
                   && preg_match('{\G([^#=&;]*)=([^#&;]*)([#&;]|\z)}', $action, $m, 0, $pos)) {
                $suffix .= self::hidden(urldecode($m[1]), urldecode($m[2]));
                $pos += strlen($m[0]);
                if ($m[3] === "#") {
                    --$pos;
                    break;
                }
            }
            $action = substr($action, 0, $qpos) . (string) substr($action, $pos);
        }

        $x = '<form method="' . $method . '" action="' . $action . '"';
        $enctype = get($extra, "enctype");
        if (!$enctype && $method !== "get")
            $enctype = "multipart/form-data";
        if ($enctype)
            $x .= ' enctype="' . $enctype . '"';
        return $x . ' accept-charset="UTF-8"' . self::extra($extra) . $suffix;
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
        if ($selected === null || !isset($opt[$selected]))
            $selected = key($opt);
        $x = '<select name="' . $name . '"' . self::extra($js);
        if (!isset($js["data-default-value"]))
            $x .= ' data-default-value="' . htmlspecialchars($selected) . '"';
        $x .= '>';
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
                $x .= '<option';
                if (get($info, "id"))
                    $x .= ' id="' . $info->id . '"';
                $x .= ' value="' . htmlspecialchars($value) . '"';
                if (strcmp($value, $selected) == 0)
                    $x .= ' selected="selected"';
                if (get($info, "disabled"))
                    $x .= ' disabled="disabled"';
                if (get($info, "class"))
                    $x .= ' class="' . $info->class . '"';
                if (get($info, "style"))
                    $x .= ' style="' . htmlspecialchars($info->style) . '"';
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
        $js = $js ? : array();
        if (!get($js, "id"))
            $js["id"] = "htctl" . ++self::$_controlid;
        self::$_lastcontrolid = $js["id"];
        if (isset($js["data-default-checked"]) || isset($js["data-default-value"])) {
            $dc = get($js, "data-default-checked");
            if ($dc === null)
                $dc = get($js, "data-default-value");
            $dc = $dc ? "1" : "";
            if (!!$checked === !!$dc)
                $dc = null;
            $js["data-default-checked"] = $dc;
        }
        $t = '<input type="checkbox"'; /* NB see Ht::radio */
        if ($name)
            $t .= " name=\"$name\" value=\"" . htmlspecialchars($value) . "\"";
        if ($checked)
            $t .= " checked=\"checked\"";
        return $t . self::extra($js) . " />";
    }

    static function radio($name, $value = 1, $checked = false, $js = null) {
        $t = self::checkbox($name, $value, $checked, $js);
        return '<input type="radio"' . substr($t, 22);
    }

    static function label($html, $id = null, $js = null) {
        if ($js && isset($js["for"])) {
            $id = $js["for"];
            unset($js["for"]);
        } else if ($id === null || $id === true)
            $id = self::$_lastcontrolid;
        return '<label' . ($id ? ' for="' . $id . '"' : '')
            . self::extra($js) . '>' . $html . "</label>";
    }

    static function button($html, $js = null) {
        if ($js === null && is_array($html)) {
            $js = $html;
            $html = null;
        } else if ($js === null)
            $js = array();
        if (!isset($js["class"]) && self::$default_button_class)
            $js["class"] = self::$default_button_class;
        $type = isset($js["type"]) ? $js["type"] : "button";
        if ($type === "button" || preg_match("_[<>]_", $html) || isset($js["value"])) {
            if (!isset($js["value"]))
                $js["value"] = 1;
            return "<button type=\"$type\"" . self::extra($js)
                . ">" . $html . "</button>";
        } else {
            $js["value"] = $html;
            return "<input type=\"$type\"" . self::extra($js) . " />";
        }
    }

    static function submit($name, $html = null, $js = null) {
        if ($js === null && is_array($html)) {
            $js = $html;
            $html = null;
        } else if ($js === null)
            $js = array();
        $js["type"] = "submit";
        if ($html === null)
            $html = $name;
        else if ((string) $name !== "")
            $js["name"] = $name;
        return self::button($html, $js);
    }

    static function hidden_default_submit($name, $value = null, $js = null) {
        if ($js === null && is_array($value)) {
            $js = $value;
            $value = null;
        } else if ($js === null)
            $js = array();
        $js["class"] = trim(get_s($js, "class") . " hidden");
        return self::submit($name, $value, $js);
    }

    private static function apply_placeholder(&$value, &$js) {
        if ($value === null || $value === get($js, "placeholder"))
            $value = "";
        if (($default = get($js, "data-default-value")) !== null
            && $value === $default)
            unset($js["data-default-value"]);
    }

    static function entry($name, $value, $js = null) {
        $js = $js ? $js : array();
        self::apply_placeholder($value, $js);
        $type = get($js, "type") ? : "text";
        return '<input type="' . $type . '" name="' . $name . '" value="'
            . htmlspecialchars($value) . '"' . self::extra($js) . ' />';
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
            . '>' . htmlspecialchars($value) . '</textarea>';
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
                    $t .= '<div class="hint">' . $a[1] . '</div>';
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

    static private function make_link($html, $href, $js) {
        if ($js === null)
            $js = [];
        if (!isset($js["href"]))
            $js["href"] = isset($href) ? $href : "";
        if (isset($js["onclick"]) && !preg_match('/(?:^return|;)/', $js["onclick"]))
            $js["onclick"] = "return " . $js["onclick"];
        if (isset($js["onclick"])
            && (!isset($js["class"]) || !preg_match('/(?:\A|\s)(?:ui|btn|tla)(?=\s|\z)/', $js["class"])))
            error_log(caller_landmark(2) . ": JS Ht::link lacks class");
        return "<a" . self::extra($js) . ">" . $html . "</a>";
    }

    static function link($html, $href, $js = null) {
        if ($js === null && is_array($href))
            return self::make_link($html, null, $href);
        else
            return self::make_link($html, $href, $js);
    }

    static function link_urls($html) {
        return preg_replace('@((?:https?|ftp)://(?:[^\s<>"&]|&amp;)*[^\s<>"().,:;&])(["().,:;]*)(?=[\s<>&]|\z)@s',
                            '<a href="$1" rel="noreferrer">$1</a>$2', $html);
    }

    static function format0($html_text) {
        $html_text = self::link_urls(Text::single_line_paragraphs($html_text));
        return preg_replace('/(?:\r\n?){2,}|\n{2,}/', "</p><p>", "<p>$html_text</p>");
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
                self::$_stash .= self::$_script_open . ">";
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
        if (is_int($type))
            $type = $type >= 2 ? "error" : ($type > 0 ? "warning" : "info");
        if (substr($type, 0, 1) === "x")
            $type = substr($type, 1);
        if ($type === "merror")
            $type = "error";
        if (is_array($content)) {
            $content = join("", array_map(function ($x) {
                if (str_starts_with($x, "<p") || str_starts_with($x, "<div"))
                    return $x;
                else
                    return "<p>{$x}</p>";
            }, $content));
        }
        if ($content === "")
            return "";
        return '<div class="msg msg-' . $type . '">' . $content . '</div>';
    }


    static function control_class($name, $rest = false) {
        if (isset(self::$_control_classes[$name])) {
            $c = self::$_control_classes[$name];
            if ($rest && $c && $c[0] !== " ")
                $rest .= " ";
            return $rest ? $rest . $c : $c;
        } else {
            return $rest;
        }
    }
    static function set_control_class($name, $class) {
        self::$_control_classes[$name] = $class ? " " . $class : "";
    }
    static function error_at($name) {
        self::$_control_classes[$name] = " has-error";
    }
}
