<?php
// sitype.php -- HotCRP conference settings types
// Copyright (c) 2022-2023 Eddie Kohler; see LICENSE.

abstract class Sitype {
    /** @var associative-array<string,class-string> */
    static private $type_classname = [
        "checkbox" => "+Checkbox_Sitype",
        "cdate" => "+Cdate_Sitype",
        "date" => "+Date_Sitype",
        "email" => "+Email_Sitype",
        "emailheader" => "+EmailHeader_Sitype",
        "float" => "+Float_Sitype",
        "grace" => "+Grace_Sitype",
        "htmlstring" => "+Html_Sitype",
        "int" => "+Int_Sitype",
        "longstring" => "+String_Sitype",
        "nonnegint" => "+Nonnegint_Sitype",
        "radio" => "+Radio_Sitype",
        "simplestring" => "+String_Sitype",
        "string" => "+String_Sitype",
        "tag" => "+Tag_Sitype",
        "tagbase" => "+Tag_Sitype", /* XXX deprecated */
        "taglist" => "+TagList_Sitype",
        "tagselect" => "+Tag_Sitype",
        "url" => "+Url_Sitype"
    ];
    /** @var associative-array<string,?Sitype> */
    static private $type_class = [];

    /** @param string $name
     * @param ?string $subtype
     * @return ?Sitype */
    static function get(Conf $conf, $name, $subtype = null) {
        assert($name !== null);
        $key = $subtype === null ? $name : "{$name}:{$subtype}";
        if (!array_key_exists($key, self::$type_class)) {
            if (array_key_exists($name, self::$type_classname)) {
                $tstr = self::$type_classname[$name];
            } else {
                $tstr = $name;
            }
            if ($tstr[0] === "+") {
                $tname = substr($tstr, 1);
                self::$type_class[$key] = new $tname($name, $subtype);
            } else {
                self::$type_class[$key] = null;
            }
        }
        return self::$type_class[$key];
    }

    function initialize_si(Si $si) {
    }

    /** @return int */
    function storage_type() {
        return Si::SI_VALUE;
    }

    function parse_null_vstr(Si $si) {
        return null;
    }

    /** @param string $vstr */
    abstract function parse_reqv($vstr, Si $si, SettingValues $sv);

    /** @param null|int|string $v
     * @return string */
    function unparse_reqv($v, Si $si, SettingValues $sv) {
        return (string) $v;
    }

    /** @param mixed $jv
     * @return ?string */
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        return null;
    }

    /** @param null|int|string $v
     * @return mixed */
    function unparse_jsonv($v, Si $si, SettingValues $sv) {
        return $v;
    }

    /** @return bool */
    function nullable($v, Si $si, SettingValues $sv) {
        return false;
    }

    /** @return mixed */
    function json_examples(Si $si, SettingValues $sv) {
        return null;
    }
}

trait Positive_Sitype {
    function nullable($v, Si $si, SettingValues $sv) {
        return $v <= 0;
    }
}

trait Data_Sitype {
    function storage_type() {
        return Si::SI_DATA;
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_string($jv) || $jv === null) {
            return $jv ?? "";
        } else {
            $sv->error_at($si, "<0>String required");
            return null;
        }
    }
}

class Checkbox_Sitype extends Sitype {
    use Positive_Sitype;
    function parse_null_vstr(Si $si) {
        return 0;
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        return $vstr !== "" ? 1 : 0;
    }
    function unparse_reqv($v, Si $si, SettingValues $sv) {
        return $v ? "1" : "";
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_bool($jv)) {
            return $jv ? "1" : "";
        } else {
            $sv->error_at($si, "<0>Boolean required");
            return null;
        }
    }
    function unparse_jsonv($v, Si $si, SettingValues $sv) {
        return $v ? true : false;
    }
    function json_examples(Si $si, SettingValues $sv) {
        return [false, true];
    }
}

class Radio_Sitype extends Sitype {
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        foreach ($si->values($sv) as $allowedv) {
            if ((string) $allowedv === $vstr) {
                return $allowedv;
            }
        }
        $sv->error_at($si, "<0>‘" . ($vstr === "" ? "(empty)" : $vstr) . "’ is not a valid choice");
        return null;
    }
    function unparse_reqv($v, Si $si, SettingValues $sv) {
        if (is_bool($v) && $si->values($sv) === [0, 1]) {
            return $v ? "1" : "0";
        } else {
            return (string) $v;
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        $values = $si->values($sv) ?? [];
        foreach ($values as $allowedv) {
            if ($allowedv === $jv
                || ($allowedv === 0 && $jv === false)
                || ($allowedv === 1 && $jv === true))
                return (string) $allowedv;
        }
        foreach ($si->json_values($sv) ?? [] as $i => $allowedv) {
            if ($allowedv === $jv)
                return (string) $values[$i];
        }
        $nonbool = $this->json_examples($si, $sv) !== [false, true];
        $sv->error_at(null, $nonbool ? "<0>Invalid choice" : "<0>Boolean required");
        return null;
    }
    function unparse_jsonv($v, Si $si, SettingValues $sv) {
        $values = $si->values($sv);
        $json_values = $si->json_values($sv);
        if ($json_values !== null
            && ($i = array_search($v, $values, true)) !== false
            && $i < count($json_values)) {
            return $json_values[$i];
        }
        if ($values === [0, 1]
            && (is_bool($v) || $v === 0 || $v === 1)) {
            return $v === true || $v === 1;
        }
        return $v;
    }
    function json_examples(Si $si, SettingValues $sv) {
        $values = $si->json_values($sv);
        if ($values === null) {
            $values = $si->values($sv);
            if ($values === [0, 1]) {
                $values = [false, true];
            }
        }
        return $values;
    }
}

class Cdate_Sitype extends Sitype {
    use Positive_Sitype;
    function parse_null_vstr(Si $si) {
        return 0;
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if ($vstr !== "") {
            $curv = $sv->oldv($si);
            return $curv > 0 ? $curv : Conf::$now;
        } else {
            return 0;
        }
    }
    function unparse_reqv($v, Si $si, SettingValues $sv) {
        return $v ? "1" : "";
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_bool($jv)) {
            return $jv ? "1" : "";
        } else {
            $sv->error_at($si, "<0>Boolean required");
            return null;
        }
    }
    function unparse_jsonv($v, Si $si, SettingValues $sv) {
        return $v ? true : false;
    }
    function json_examples(Si $si, SettingValues $sv) {
        return [false, true];
    }
}

class Date_Sitype extends Sitype {
    /** @var bool */
    private $explicit_none;
    function __construct($type, $subtype) {
        $this->explicit_none = $subtype === "explicit_none";
    }
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 32;
        $si->placeholder = $si->placeholder ?? "N/A";
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if ($vstr === ""
            || $vstr === "0"
            || strcasecmp($vstr, "N/A") === 0) {
            return $this->explicit_none ? -1 : 0;
        } else if (strcasecmp($vstr, "none") === 0) {
            return 0;
        } else if (($t = $sv->conf->parse_time($vstr)) !== false) {
            return $t;
        } else {
            $sv->error_at($si, "<0>Please enter a valid date");
            return null;
        }
    }
    function unparse_reqv($v, Si $si, SettingValues $sv) {
        if ($v === null || ($v <= 0 && !$this->explicit_none)) {
            return "";
        } else if ($v <= 0) {
            return "none";
        } else if ($v === 1) {
            return "now";
        } else {
            return $si->conf->parseableTime($v, true);
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_string($jv)) {
            return $jv;
        } else if (is_int($jv)) {
            return $jv > 0 ? "@{$jv}" : "";
        } else {
            $sv->error_at($si, "<0>Date required");
            return null;
        }
    }
    function unparse_jsonv($jv, Si $si, SettingValues $sv) {
        if ($jv === null || $jv <= 1) {
            return $this->unparse_reqv($jv, $si, $sv);
        } else {
            return $si->conf->unparse_time_log($jv);
        }
    }
    function nullable($v, Si $si, SettingValues $sv) {
        return $v < 0 || ($v === 0 && !$this->explicit_none);
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "date";
    }
}

class Grace_Sitype extends Sitype {
    use Positive_Sitype;
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if (($v = SettingParser::parse_interval($vstr)) !== false) {
            return intval($v);
        } else {
            $sv->error_at($si, "<0>Please enter a valid grace period");
            return null;
        }
    }
    function unparse_reqv($v, Si $si, SettingValues $sv) {
        if ($v === null || $v <= 0 || !is_numeric($v)) {
            return "none";
        } else if ($v % 3600 === 0) {
            return ($v / 3600) . " hr";
        } else if ($v % 60 === 0) {
            return ($v / 60) . " min";
        } else {
            return sprintf("%d:%02d", intval($v / 60), $v % 60);
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_string($jv)) {
            return $jv;
        } else if (is_int($jv)) {
            return $this->unparse_reqv($jv, $si, $sv);
        } else {
            $sv->error_at($si, "<0>Grace period string required");
            return null;
        }
    }
    function unparse_jsonv($v, Si $si, SettingValues $sv) {
        return $v;
    }
}

class Int_Sitype extends Sitype {
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if (preg_match('/\A[+-]?[0-9]+\z/', $vstr)) {
            return intval($vstr);
        } else if ($vstr === "" && ($defv = $si->default_value($sv)) !== null) {
            return $defv;
        } else {
            $sv->error_at($si, "<0>Please enter a whole number");
            return null;
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_int($jv)) {
            return (string) $jv;
        } else if ($jv === null) {
            return "";
        } else {
            $sv->error_at($si, "<0>Whole number required");
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "whole number";
    }
}

class Nonnegint_Sitype extends Sitype {
    use Positive_Sitype;
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if (preg_match('/\A\+?[0-9]+\z/', $vstr)) {
            return intval($vstr);
        } else if ($vstr === "" && ($defv = $si->default_value($sv)) !== null) {
            return $defv;
        } else {
            $sv->error_at($si, "<0>Please enter a nonnegative whole number");
            return null;
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_int($jv) && $jv >= 0) {
            return (string) $jv;
        } else if ($jv === null) {
            return "";
        } else {
            $sv->error_at($si, "<0>Nonnegative whole number required");
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "nonnegative whole number";
    }
}

class Float_Sitype extends Sitype {
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if (is_numeric($vstr)) {
            return floatval($vstr);
        } else if ($vstr === "" && ($defv = $si->default_value($sv)) !== null) {
            return $defv;
        } else {
            $sv->error_at($si, "<0>Please enter a number");
            return null;
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_int($jv) || is_float($jv)) {
            return (string) $jv;
        } else if ($jv === null) {
            return "";
        } else {
            $sv->error_at($si, "<0>Number required");
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "number";
    }
}

class String_Sitype extends Sitype {
    use Data_Sitype;
    /** @var bool */
    private $simple = false;
    /** @var bool */
    private $long = false;
    /** @var bool */
    private $allow_int = false;
    /** @var bool */
    private $condition = false;
    /** @var ?string */
    private $example;
    function __construct($name, $subtype = null) {
        if ($name === "simplestring" || $subtype === "simple") {
            $this->simple = true;
        } else if ($name === "longstring" || $subtype === "long") {
            $this->long = true;
        } else if ($subtype === "allow_int") {
            $this->simple = $this->allow_int = true;
        } else if ($subtype === "search") {
            $this->simple = true;
            $this->example = "search expression";
        } else if ($subtype === "condition") {
            $this->simple = $this->condition = true;
            $this->example = "search expression";
        } else if ($subtype === "formula") {
            $this->simple = true;
            $this->example = "formula expression";
        }
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if ($this->simple) {
            $s = simplify_whitespace($vstr);
        } else if ($this->long) {
            $s = cleannl($vstr);
        } else {
            $s = trim($vstr);
        }
        if ($s !== "" || $si->required !== true) {
            return $s;
        } else {
            $sv->error_at($si, "<0>Entry required");
            return null;
        }
    }
    function jsonv_reqstr($jv, Si $si, SettingValues $sv) {
        if (is_string($jv) || $jv === null) {
            return $jv ?? "";
        } else if (is_int($jv) && $this->allow_int) {
            return "{$jv}";
        } else if (is_bool($jv) && $this->condition) {
            return $jv ? "ALL" : "NONE";
        } else {
            $sv->error_at($si, $this->allow_int ? "<0>String or number required" : "<0>String required");
            return null;
        }
    }
    function unparse_jsonv($v, Si $si, SettingValues $sv) {
        if ($this->condition) {
            if ($v === "ALL") {
                return true;
            } else if ($v === "NONE") {
                return false;
            }
        }
        return $v;
    }
    function nullable($v, Si $si, SettingValues $sv) {
        return $v === ""
            || (substr($si->name, 0, 9) === "mailbody_"
                && ($sv->expand_mail_template(substr($si->name, 9), true))["body"] === $v);
    }
    function json_examples(Si $si, SettingValues $sv) {
        return $this->example ?? ($this->simple ? "short string" : "text");
    }
}

class Url_Sitype extends Sitype {
    use Data_Sitype;
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if (($vstr === "" && $si->required === false)
            || preg_match('/\A(?:https?|ftp):\/\/\S+\z/', $vstr)) {
            return $vstr;
        } else if ($vstr === "") {
            $sv->error_at($si, "<0>Entry required");
            return null;
        } else {
            $sv->error_at($si, "<0>Valid web address required");
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "URL";
    }
}

class Email_Sitype extends Sitype {
    use Data_Sitype;
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if (($vstr === "" && $si->required === false)
            || validate_email($vstr)
            || $vstr === $sv->oldv($si->name)) {
            return $vstr;
        } else if ($vstr === "") {
            $sv->error_at($si, "<0>Entry required");
            return null;
        } else {
            $sv->error_at($si, "<0>Valid email address required");
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "email address";
    }
}

class EmailHeader_Sitype extends Sitype {
    use Data_Sitype;
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        $mt = new MimeText;
        $t = $mt->encode_email_header("", $vstr);
        if ($t !== false) {
            return $t != "" ? MimeText::decode_header($t) : "";
        } else {
            $sv->append_item_at($si, $mt->mi);
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "email header";
    }
}

class Html_Sitype extends Sitype {
    use Data_Sitype;
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        $ch = CleanHTML::basic();
        if (($t = $ch->clean($vstr)) !== false) {
            return $t;
        } else {
            $sv->error_at($si, "<5>{$ch->last_error}");
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "HTML text";
    }
}

class Tag_Sitype extends Sitype {
    use Data_Sitype;
    /** @var int */
    private $flags = Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE;
    function __construct($name, $subtype = null) {
        if ($subtype === "allow_reserved" || $subtype === "allow_reserved_chair") {
            $this->flags |= Tagger::ALLOWRESERVED;
        }
        if ($subtype === "allow_chair" || $subtype === "allow_reserved_chair") {
            $this->flags &= ~Tagger::NOCHAIR;
        }
    }
    function initialize_si(Si $si) {
        $si->required = $si->required ?? true;
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        if ($vstr === "" && $si->required === false) {
            return "";
        } else if (($t = $sv->tagger()->check($vstr, $this->flags))) {
            return $t;
        } else {
            $sv->error_at($si, $sv->tagger()->error_ftext());
            return null;
        }
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "tag";
    }
}

class TagList_Sitype extends Sitype {
    use Data_Sitype;
    /** @var int
     * @readonly */
    public $flags = Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE;
    /** @var ?float
     * @readonly */
    public $min_idx;
    /** @param string $type
     * @param string $subtype */
    function __construct($type, $subtype) {
        if ($subtype === "allow_wildcard" || $subtype === "allow_wildcard_chair") {
            $this->flags |= Tagger::ALLOWSTAR;
        }
        if ($subtype === "allow_chair" || $subtype === "allow_wildcard_chair") {
            $this->flags &= ~Tagger::NOCHAIR;
        }
        if ($subtype === "allotment") {
            $this->flags &= ~Tagger::NOVALUE;
            $this->min_idx = 1.0;
        }
    }
    function parse_reqv($vstr, Si $si, SettingValues $sv) {
        $ts = [];
        foreach (preg_split('/[\s,;]+/', $vstr) as $t) {
            if ($t !== "" && ($tx = $sv->tagger()->check($t, $this->flags))) {
                list($tag, $idx) = Tagger::unpack($tx);
                if ($this->min_idx !== null) {
                    $tx = $tag . "#" . max($this->min_idx, (float) $idx);
                }
                $ts[strtolower($tag)] = $tx;
            } else if ($t !== "") {
                $sv->error_at($si, $sv->tagger()->error_ftext(true));
            }
        }
        $collator = $sv->conf->collator();
        uksort($ts, function ($a, $b) use ($collator) {
            return $collator->compare($a, $b);
        });
        return join(" ", array_values($ts));
    }
    function json_examples(Si $si, SettingValues $sv) {
        return "space-separated tag list" . ($this->flags & Tagger::ALLOWSTAR ? " (wildcards allowed)" : "");
    }
}
