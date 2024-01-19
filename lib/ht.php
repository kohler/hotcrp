<?php
// ht.php -- HotCRP HTML helper functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Ht {
    /** @var string */
    public static $img_base = "";
    /** @var string */
    private static $_script_open = "<script";
    /** @var int */
    private static $_controlid = 0;
    /** @var int */
    private static $_lastcontrolid = 0;
    /** @var string */
    private static $_stash = "";
    /** @var bool */
    private static $_stash_inscript = false;
    /** @var array<string,true> */
    private static $_stash_map = [];
    const ATTR_SKIP = 1;
    const ATTR_BOOL = 2;
    const ATTR_BOOLTEXT = 3;
    const ATTR_NOEMPTY = 4;
    const ATTR_TOKENLIST = 16;
    private static $_attr_type = [
        "accept-charset" => self::ATTR_SKIP,
        "action" => self::ATTR_SKIP,
        "async" => self::ATTR_BOOL,
        "autofocus" => self::ATTR_BOOL,
        "checked" => self::ATTR_BOOL,
        "class" => self::ATTR_NOEMPTY | self::ATTR_TOKENLIST,
        "data-default-checked" => self::ATTR_BOOLTEXT,
        "defer" => self::ATTR_BOOL,
        "disabled" => self::ATTR_BOOL,
        "enctype" => self::ATTR_SKIP,
        "formnovalidate" => self::ATTR_BOOL,
        "method" => self::ATTR_SKIP,
        "multiple" => self::ATTR_BOOL,
        "novalidate" => self::ATTR_BOOL,
        "optionstyles" => self::ATTR_SKIP,
        "readonly" => self::ATTR_BOOL,
        "required" => self::ATTR_BOOL,
        "selected" => self::ATTR_BOOL,
        "spellcheck" => self::ATTR_BOOLTEXT,
        "type" => self::ATTR_SKIP
    ];

    /** @param ?string ...$tokens
     * @return string */
    static function add_tokens(...$tokens) {
        $x = "";
        foreach ($tokens as $t) {
            if (($t ?? "") !== "")
                $x = $x === "" ? $t : "{$x} {$t}";
        }
        return $x;
    }

    /** @param ?array<string,mixed> $js
     * @return string */
    static function extra($js) {
        $x = "";
        if ($js) {
            foreach ($js as $k => $v) {
                $tf = self::$_attr_type[$k] ?? 0;
                $t = $tf & 15;
                if (is_array($v) && ($tf & self::ATTR_TOKENLIST) !== 0) {
                    $v = self::add_tokens(...$v);
                }
                if ($v === null
                    || $t === self::ATTR_SKIP
                    || ($v === false && $t !== self::ATTR_BOOLTEXT)
                    || ($v === "" && $t === self::ATTR_NOEMPTY)) {
                    // nothing
                } else if ($t === self::ATTR_BOOL) {
                    $x .= ($v ? " {$k}" : "");
                } else if ($t === self::ATTR_BOOLTEXT && is_bool($v)) {
                    $x .= " {$k}=\"" . ($v ? "true" : "false") . "\"";
                } else if ($v === "") {
                    $x .= " {$k}";
                } else {
                    $x .= " {$k}=\"" . str_replace("\"", "&quot;", $v) . "\"";
                }
            }
        }
        return $x;
    }

    /** @param ?string $nonce */
    static function set_script_nonce($nonce) {
        if ($nonce === null || $nonce === "") {
            self::$_script_open = '<script';
        } else {
            self::$_script_open = '<script nonce="' . htmlspecialchars($nonce) . '"';
        }
    }

    /** @return string */
    static function script_open() {
        return self::$_script_open . '>';
    }

    /** @param string $script
     * @return string */
    static function script($script) {
        return self::$_script_open . '>' . $script . '</script>';
    }

    /** @param string $src
     * @param ?array<string,mixed> $js
     * @return string */
    static function script_file($src, $js = null) {
        if ($js
            && ($js["crossorigin"] ?? false)
            && !preg_match('/\A([a-z]+:)?\/\//', $src)) {
            unset($js["crossorigin"]);
        }
        return self::$_script_open . ' src="' . htmlspecialchars($src) . '"' . self::extra($js) . '></script>';
    }

    /** @param string $src
     * @return string */
    static function stylesheet_file($src) {
        return "<link rel=\"stylesheet\" type=\"text/css\" href=\""
            . htmlspecialchars($src) . "\">";
    }

    /** @param string|array<string,mixed> $action
     * @param array<string,mixed> $extra
     * @return string */
    static function form($action, $extra = []) {
        if (is_array($action)) {
            $extra = $action;
            $action = $extra["action"] ?? "";
        } else {
            $action = $action ?? "";
        }

        // GET method requires special handling: extract params from URL
        // and render as hidden inputs
        $suffix = ">";
        $method = $extra["method"] ?? "post";
        if ($method === "get"
            && ($qpos = strpos($action, "?")) !== false) {
            $pos = $qpos + 1;
            while ($pos < strlen($action)
                   && preg_match('/\G([^#=&;]*)=([^#&;]*)([#&;]|\z)/', $action, $m, 0, $pos)) {
                $suffix .= self::hidden(urldecode($m[1]), urldecode($m[2]));
                $pos += strlen($m[0]);
                if ($m[3] === "#") {
                    --$pos;
                    break;
                }
            }
            $action = substr($action, 0, $qpos) . (string) substr($action, $pos);
        }

        $x = '<form';
        if ($action !== "" || isset($extra["method"])) {
            $x .= " method=\"{$method}\"";
        }
        if ($action !== "") {
            $x .= " action=\"{$action}\"";
        }
        $enctype = $extra["enctype"] ?? null;
        if (!$enctype && $method !== "get") {
            $enctype = "multipart/form-data";
        }
        if ($enctype) {
            $x .= " enctype=\"{$enctype}\"";
        }
        return $x . ' accept-charset="UTF-8"' . self::extra($extra) . $suffix;
    }

    /** @param string $name
     * @param string|int $value
     * @param ?array<string,mixed> $extra
     * @return string */
    static function hidden($name, $value = "", $extra = null) {
        return '<input type="hidden" name="' . htmlspecialchars($name)
            . '" value="' . htmlspecialchars($value) . '"'
            . self::extra($extra) . '>';
    }

    /** @param string $name
     * @param array $opt
     * @return string */
    static function select($name, $opt, $selected = null, $js = null) {
        if (is_array($selected) && $js === null) {
            $js = $selected;
            $selected = null;
        }
        $disabled = $js["disabled"] ?? null;
        if (is_array($disabled)) {
            unset($js["disabled"]);
        }

        $in_optgroup = $declared_optgroup = "";
        $opts = [];
        $first_value = null;
        $has_selected = false;
        foreach ($opt as $key => $info) {
            if (is_array($info) && isset($info[0]) && $info[0] === "optgroup") {
                $info = ["type" => "optgroup", "label" => $info[1] ?? null];
            } else if (is_object($info)) {
                $info = (array) $info;
            } else if (is_scalar($info)) {
                $info = ["label" => $info];
                if (is_array($disabled) && isset($disabled[$key])) {
                    $info["disabled"] = $disabled[$key];
                }
            }

            if ($info === null) {
                $opts[] = '<option label=" " disabled></option>';
                continue;
            }
            if (($info["exclude"] ?? false)
                && strcmp($info["value"] ?? $key, $selected) !== 0) {
                continue;
            }
            if (($info["type"] ?? null) === "optgroup") {
                $declared_optgroup = $info["label"] ?? "";
                continue;
            }
            $expected_optgroup = $declared_optgroup ? $in_optgroup : "";
            if (($info["optgroup"] ?? $declared_optgroup) !== $in_optgroup) {
                $opts[] = $in_optgroup === "" ? "" : "</optgroup>";
                $in_optgroup = $info["optgroup"] ?? $declared_optgroup;
                if ($in_optgroup !== "") {
                    $opts[] = '<optgroup label="' . htmlspecialchars($in_optgroup) . '">';
                }
            }

            $label = $info["label"];
            unset($info["label"], $info["type"], $info["optgroup"], $info["exclude"]);
            $info["value"] = $info["value"] ?? (string) $key;
            if (!isset($first_value)) {
                $first_value = $info["value"];
            }
            if ($selected !== null
                && strcmp($info["value"], $selected) === 0
                && !$has_selected) {
                $info["selected"] = true;
                $has_selected = true;
            }
            $opts[] = '<option' . self::extra($info) . ">{$label}</option>";
        }
        if ($in_optgroup !== "") {
            $opts[] = "</optgroup>";
        }

        $jsx = self::extra($js);
        if (!isset($js["data-default-value"])
            && ($has_selected || isset($first_value))) {
            $jsx .= ' data-default-value="' . htmlspecialchars($has_selected ? $selected : $first_value) . '"';
        }
        return "<span class=\"select\"><select name=\"{$name}\"{$jsx}>" . join("", $opts) . "</select></span>";
    }

    /** @param string $name
     * @param string|int $value
     * @param bool $checked
     * @param ?array<string,mixed> $js
     * @return string */
    static function checkbox($name, $value = 1, $checked = false, $js = null) {
        if (is_array($value)) {
            $js = $value;
            $value = 1;
        } else if (is_array($checked)) {
            $js = $checked;
            $checked = false;
        }
        $js = $js ? : [];
        if (!array_key_exists("id", $js) || $js["id"] === true) {
            $js["id"] = "k-" . ++self::$_controlid;
        }
        '@phan-var array{id:string|false|null} $js';
        if ($js["id"]) {
            self::$_lastcontrolid = $js["id"];
        }
        $t = '<input type="checkbox"'; /* NB see Ht::radio */
        if ($name) {
            $v = htmlspecialchars((string) $value);
            $t .= " name=\"{$name}\" value=\"{$v}\"";
        }
        if ($checked) {
            $t .= " checked";
        }
        return $t . self::extra($js) . ">";
    }

    /** @param string $name
     * @param string|int $value
     * @param bool $checked
     * @param ?array<string,mixed> $js
     * @return string */
    static function radio($name, $value = 1, $checked = false, $js = null) {
        $t = self::checkbox($name, $value, $checked, $js);
        return '<input type="radio"' . substr($t, 22);
    }

    /** @param string $html
     * @param ?string $id
     * @param ?array<string,mixed> $js
     * @return string */
    static function label($html, $id = null, $js = null) {
        if ($js && isset($js["for"])) {
            $id = $js["for"];
            unset($js["for"]);
        } else if ($id === null || $id === true) {
            $id = self::$_lastcontrolid;
        }
        return '<label' . ($id ? ' for="' . $id . '"' : '')
            . self::extra($js) . ">{$html}</label>";
    }

    /** @param string $html
     * @param ?array<string,mixed> $js
     * @return string */
    static function button($html, $js = null) {
        if ($js === null && is_array($html)) {
            $js = $html;
            $html = "";
        } else if ($js === null) {
            $js = [];
        }
        $type = isset($js["type"]) ? $js["type"] : "button";
        if (!isset($js["value"]) && isset($js["name"]) && $type !== "button") {
            $js["value"] = "1";
        }
        return "<button type=\"$type\"" . self::extra($js) . ">{$html}</button>";
    }

    /** @param string $name
     * @param null|string|array<string,mixed> $html
     * @param ?array<string,mixed> $js
     * @return string */
    static function submit($name, $html = null, $js = null) {
        if ($js === null && is_array($html)) {
            $js = $html;
            $html = null;
        } else if ($js === null) {
            $js = [];
        }
        $js["type"] = "submit";
        if ($html === null) {
            $html = $name;
        } else if ($name !== null && $name !== "") {
            $js["name"] = $name;
        }
        return self::button($html, $js);
    }

    /** @param string $name
     * @param null|string|int $value
     * @param ?array<string,mixed> $js
     * @return string */
    static function hidden_default_submit($name, $value = null, $js = null) {
        if ($js === null && is_array($value)) {
            $js = $value;
            $value = null;
        } else if ($js === null) {
            $js = [];
        }
        $js["class"] = trim(($js["class"] ?? "") . " pseudohidden");
        $js["value"] = $value;
        return self::submit($name, "", $js);
    }

    private static function apply_placeholder(&$value, &$js) {
        $value = (string) $value;
        if (isset($js["placeholder"]) && $value === (string) $js["placeholder"]) {
            $value = "";
        }
        if (isset($js["data-default-value"]) && $value === (string) $js["data-default-value"]) {
            unset($js["data-default-value"]);
        }
    }

    /** @param string $name
     * @param string|int $value
     * @param ?array<string,mixed> $js
     * @return string */
    static function entry($name, $value, $js = null) {
        $js = $js ?? [];
        self::apply_placeholder($value, $js);
        $type = $js["type"] ?? "text";
        $vt = htmlspecialchars($value);
        $jst = self::extra($js);
        return "<input type=\"{$type}\" name=\"{$name}\" value=\"{$vt}\"{$jst}>";
    }

    /** @param string $name
     * @param string $value
     * @param ?array<string,mixed> $js
     * @return string */
    static function password($name, $value, $js = null) {
        $js = $js ?? [];
        $js["type"] = "password";
        return self::entry($name, $value, $js);
    }

    /** @param string $name
     * @param string $value
     * @param ?array<string,mixed> $js
     * @return string */
    static function textarea($name, $value, $js = null) {
        $js = $js ?? [];
        self::apply_placeholder($value, $js);
        $vt = htmlspecialchars($value);
        $jst = self::extra($js);
        return "<textarea name=\"{$name}\"{$jst}>{$vt}</textarea>";
    }

    /** @param list<string|list<string>> $actions
     * @param ?array<string,mixed> $js
     * @return string */
    static function actions($actions, $js = null) {
        if (empty($actions)) {
            return "";
        }
        $actions = array_values($actions);
        $js = $js ?? [];
        if (!isset($js["class"])) {
            $js["class"] = "aab";
        }
        $t = "<div" . self::extra($js) . ">";
        foreach ($actions as $i => $action) {
            $a = is_array($action) ? $action : [$action];
            if ((string) $a[0] !== "") {
                $t .= '<div class="aabut';
                if ($i + 1 < count($actions) && $actions[$i + 1] === "") {
                    $t .= " aabutsp";
                }
                if (count($a) > 2 && (string) $a[2] !== "") {
                    $t .= " {$a[2]}";
                }
                $t .= "\">{$a[0]}";
                if (count($a) > 1 && (string) $a[1] !== "") {
                    $t .= "<div class=\"hint\">{$a[1]}</div>";
                }
                $t .= '</div>';
            }
        }
        return $t . "</div>";
    }

    /** @param string|list<string> $html
     * @return string */
    static function pre($html) {
        if (is_array($html)) {
            $html = join("\n", $html);
        }
        return "<pre>{$html}</pre>";
    }

    /** @param string|list<string> $text
     * @return string */
    static function pre_text($text) {
        if (is_array($text)
            && array_keys($text) === range(0, count($text) - 1)) {
            $text = join("\n", $text);
        } else if (is_array($text) || is_object($text)) {
            $text = var_export($text, true);
        }
        return "<pre>" . htmlspecialchars($text) . "</pre>";
    }

    /** @return string */
    static function pre_text_wrap($text) {
        if (is_array($text) && !is_associative_array($text)
            && array_reduce($text, function ($x, $s) { return $x && is_string($s); }, true)) {
            $text = join("\n", $text);
        } else if (is_array($text) || is_object($text)) {
            $text = var_export($text, true);
        }
        return "<pre style=\"white-space:pre-wrap\">" . htmlspecialchars($text) . "</pre>";
    }

    /** @return string */
    static function pre_export($x) {
        return "<pre style=\"white-space:pre-wrap\">" . htmlspecialchars(var_export($x, true)) . "</pre>";
    }

    /** @param string $src
     * @param string $alt
     * @param ?string|?array<string,mixed> $js
     * @return string */
    static function img($src, $alt, $js = null) {
        if (is_string($js)) {
            $js = ["class" => $js];
        }
        if (self::$img_base && !preg_match('/\A(?:https?:\/|\/)/i', $src)) {
            $src = self::$img_base . $src;
        }
        $altt = htmlspecialchars($alt);
        $jst = self::extra($js);
        return "<img src=\"{$src}\" alt=\"{$altt}\"{$jst}>";
    }

    /** @return string */
    static private function make_link($html, $href, $js) {
        if ($js === null) {
            $js = [];
        }
        if (!isset($js["href"])) {
            $js["href"] = isset($href) ? $href : "";
        }
        if (isset($js["onclick"]) && !preg_match('/(?:^return|;)/', $js["onclick"])) {
            $js["onclick"] = "return " . $js["onclick"];
        }
        if (isset($js["onclick"])
            && (!isset($js["class"]) || !preg_match('/(?:\A|\s)(?:ui|btn|lla|tla)(?=\s|\z)/', $js["class"]))) {
            error_log(caller_landmark(2) . ": JS Ht::link lacks class");
        }
        return "<a" . self::extra($js) . ">{$html}</a>";
    }

    /** @return string */
    static function link($html, $href, $js = null) {
        if ($js === null && is_array($href)) {
            return self::make_link($html, null, $href);
        } else {
            return self::make_link($html, $href, $js);
        }
    }

    /** @param string $html
     * @return string */
    static function link_urls($html) {
        return preg_replace('/((?:https?|ftp):\/\/(?:[^\s<>"&]|&amp;)*[^\s<>"().,:;?!&])(["().,:;?!]*)(?=[\s<>&]|\z)/s', '<a href="$1" rel="noreferrer">$1</a>$2', $html);
    }

    /** @param string $text
     * @return string */
    static function format0($text) {
        return self::format0_html(htmlspecialchars($text));
    }

    /** @param string $html
     * @return string */
    static function format0_html($html) {
        $html = self::link_urls(Text::single_line_paragraphs($html));
        return preg_replace('/(?:\r\n?){2,}|\n{2,}/', "</p><p>", "<p>$html</p>");
    }

    /** @param string $uniqueid
     * @return bool */
    static function check_stash($uniqueid) {
        return self::$_stash_map[$uniqueid] ?? false;
    }

    /** @param string $uniqueid
     * @return bool */
    static function mark_stash($uniqueid) {
        $marked = self::$_stash_map[$uniqueid] ?? false;
        self::$_stash_map[$uniqueid] = true;
        return !$marked;
    }

    /** @param string $html
     * @param ?string $uniqueid */
    static function stash_html($html, $uniqueid = null) {
        if ($html !== null && $html !== false && $html !== ""
            && (!$uniqueid || self::mark_stash($uniqueid))) {
            if (self::$_stash_inscript) {
                self::$_stash .= "</script>";
            }
            self::$_stash .= $html;
            self::$_stash_inscript = false;
        }
    }

    /** @param string $js
     * @param ?string $uniqueid */
    static function stash_script($js, $uniqueid = null) {
        if ($js !== null && $js !== false && $js !== ""
            && (!$uniqueid || self::mark_stash($uniqueid))) {
            if (!self::$_stash_inscript) {
                self::$_stash .= self::$_script_open . ">";
            } else if (($c = self::$_stash[strlen(self::$_stash) - 1]) !== "}"
                       && $c !== "{"
                       && $c !== ";") {
                self::$_stash .= ";";
            }
            self::$_stash .= $js;
            self::$_stash_inscript = true;
        }
    }

    /** @return string */
    static function unstash() {
        $stash = self::$_stash;
        if (self::$_stash_inscript) {
            $stash .= "</script>";
        }
        self::$_stash = "";
        self::$_stash_inscript = false;
        return $stash;
    }

    /** @param string $js
     * @return string */
    static function unstash_script($js) {
        self::stash_script($js);
        return self::unstash();
    }


    /** @param string $s
     * @param int $pos1
     * @param int $pos2
     * @return array{string,int,int} */
    static function make_mark_substring($s, $pos1, $pos2) {
        if ($pos1 > strlen($s) || $pos2 > strlen($s)) {
            error_log("bad arguments [{$pos1}, {$pos2}, " . strlen($s) . "]: " . debug_string_backtrace());
            return [$s, 0, 0];
        }
        $pos2 = max($pos1, $pos2);
        if ($pos1 > 0
            && ($nl = strrpos($s, "\n", $pos1 - strlen($s))) !== false) {
            $s = substr($s, $nl + 1);
            $pos1 -= $nl + 1;
            $pos2 -= $nl + 1;
        }
        if (($nl = strpos($s, "\n", $pos2)) !== false) {
            $s = substr($s, 0, $nl);
        }
        $pos1x = max(0, min($pos1 - 17, strlen($s) - 64));
        if ($pos1x > 0) {
            $pfxlen = $pos1 - $pos1x;
            while ($pos1x > 0
                   && UnicodeHelper::utf8_glyphlen(substr($s, $pos1x, $pos1 - $pos1x)) < $pfxlen) {
                --$pos1x;
            }
            $s = "…" . substr($s, $pos1x);
            $pos1 -= $pos1x - 3; /* ellipsis character UTF-8 encoding is 3 bytes long */
            $pos2 -= $pos1x - 3;
        }
        if ($pos2 - $pos1 > 12) {
            $lpos = $pos2;
            $llen = max(64 - $lpos, 12);
        } else {
            $lpos = $pos1;
            $llen = max(64 - $lpos, 24);
        }
        if (strlen($s) > $lpos + $llen) {
            $ml = $llen - 1;
            while ($lpos + $ml < strlen($s)
                   && UnicodeHelper::utf8_glyphlen(substr($s, $lpos, $ml)) < $llen - 1) {
                ++$ml;
            }
            $s = substr($s, 0, $lpos + $ml) . "…";
        }
        return [$s, $pos1, $pos2];
    }

    /** @param string $s
     * @param int $pos1
     * @param int $pos2
     * @param ?int $status
     * @return string */
    static function mark_substring($s, $pos1, $pos2, $status = 2) {
        list($s, $pos1, $pos2) = self::make_mark_substring($s, $pos1, $pos2);
        $h0 = htmlspecialchars(substr($s, 0, $pos1));
        $h1 = htmlspecialchars(substr($s, $pos1, $pos2 - $pos1));
        $h2 = htmlspecialchars(substr($s, $pos2));
        $k = $status > 1 ? "is-error" : "is-warning";
        if ($pos2 > $pos1 + 2) {
            return "{$h0}<span class=\"context-mark {$k}\">{$h1}</span>{$h2}";
        } else {
            return "{$h0}<span class=\"context-caret-mark {$k}\">{$h1}</span>{$h2}";
        }
    }

    /** @param string $s
     * @param int $pos1
     * @param int $pos2
     * @param string $indent
     * @return string */
    static function mark_substring_text($s, $pos1, $pos2, $indent = "") {
        list($s, $pos1, $pos2) = self::make_mark_substring($s, $pos1, $pos2);
        $i0 = str_repeat(" ", UnicodeHelper::utf8_glyphlen(substr($s, 0, $pos1)));
        $gl1 = UnicodeHelper::utf8_glyphlen(substr($s, $pos1, $pos2 - $pos1));
        $x = strtr($s, "\n", " ");
        return "{$indent}{$x}\n{$indent}{$i0}^"
            . str_repeat("~", max(0, $gl1 - 1)) . "\n";
    }


    /** @param string $s
     * @return bool */
    static function is_block($s) {
        return $s[0] === "<"
            && preg_match('/\A<(?:p|div|form|ul|ol|dl|blockquote|hr)\b/i', $s);
    }

    /** @param int $status
     * @return string */
    static function msg_class($status) {
        if ($status >= 2 || $status === -1 /* MessageSet::URGENT_NOTE */) {
            return "msg msg-error";
        } else if ($status > 0 || $status === -2 /* MessageSet::WARNING_NOTE */) {
            return "msg msg-warning";
        } else if ($status === -3 /* MessageSet::SUCCESS */) {
            return "msg msg-confirm";
        } else {
            return "msg msg-info";
        }
    }

    /** @param string $msg
     * @param int $status */
    static function msg($msg, $status) {
        assert(is_int($status));
        $mx = "";
        foreach (is_array($msg) ? $msg : [$msg] as $x) {
            if ($x !== "") {
                if ($x[0] === "<" && Ht::is_block($x)) {
                    $mx .= $x;
                } else {
                    $mx .= "<p>{$x}</p>";
                }
            }
        }
        if ($mx !== "") {
            return "<div class=\"" . self::msg_class($status) . "\">{$mx}</div>";
        } else {
            return "";
        }
    }

    /** @param MessageItem|iterable<MessageItem>|MessageSet ...$mls
     * @return array{string,int} */
    static function feedback_msg_content(...$mls) {
        $mlx = [];
        foreach ($mls as $ml) {
            if ($ml instanceof MessageItem) {
                $mlx[] = $ml;
            } else if ($ml instanceof MessageSet) {
                if ($ml->has_message()) { // old PHPs require at least 2 args
                    array_push($mlx, ...$ml->message_list());
                }
            } else {
                foreach ($ml as $mi) {
                    $mlx[] = $mi;
                }
            }
        }
        if (($h = MessageSet::feedback_html($mlx)) !== "") {
            return [$h, MessageSet::list_status($mlx)];
        } else {
            return ["", 0];
        }
    }

    /** @param MessageItem|iterable<MessageItem>|MessageSet ...$mls
     * @return string */
    static function feedback_msg(...$mls) {
        $ms = self::feedback_msg_content(...$mls);
        return $ms[0] === "" ? "" : self::msg($ms[0], $ms[1]);
    }
}
