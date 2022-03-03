<?php
// sitype.php -- HotCRP conference settings types
// Copyright (c) 2022 Eddie Kohler; see LICENSE.

class Sitype {
    /** @var associative-array<string,class-string> */
    static private $type_classname = [
        "checkbox" => "Checkbox_Sitype",
        "cdate" => "Cdate_Sitype",
        "date" => "Date_Sitype",
        "email" => "Email_Sitype",
        "emailheader" => "EmailHeader_Sitype",
        "float" => "Float_Sitype",
        "grace" => "Grace_Sitype",
        "htmlstring" => "Html_Sitype",
        "int" => "Nonnegint_Sitype", /* XXX */
        "longstring" => "String_Sitype",
        "nonnegint" => "Nonnegint_Sitype",
        "radio" => "Radio_Sitype",
        "simplestring" => "String_Sitype",
        "string" => "String_Sitype",
        "tag" => "Tag_Sitype",
        "tagbase" => "Tag_Sitype",
        "taglist" => "TagList_Sitype",
        "tagselect" => "Tag_Sitype",
        "url" => "Url_Sitype"
    ];
    /** @var associative-array<string,?Sitype> */
    static private $type_class = [];

    /** @param string $name
     * @param ?string $subtype
     * @return ?Sitype */
    static function get(Conf $conf, $name, $subtype = null) {
        $key = $subtype === null ? $name : "{$name}:{$subtype}";
        if (!array_key_exists($key, self::$type_class)) {
            $tname = self::$type_classname[$name] ?? null;
            /** @phan-suppress-next-line PhanParamTooMany */
            self::$type_class[$key] = $tname ? new $tname($name, $subtype) : null;
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
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        throw new ErrorException("Don't know how to parse {$si->name}");
    }

    /** @return string */
    function unparse_vstr($v, Si $si) {
        return (string) $v;
    }

    /** @return bool */
    function nullable($v, Si $si, SettingValues $sv) {
        return false;
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
}

class Checkbox_Sitype extends Sitype {
    use Positive_Sitype;
    function parse_null_vstr(Si $si) {
        return 0;
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        return $vstr !== "" ? 1 : 0;
    }
    function unparse_vstr($v, Si $si) {
        return $v ? "1" : "";
    }
}

class Radio_Sitype extends Sitype {
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        foreach ($si->values as $allowedv) {
            if ((string) $allowedv === $vstr)
                return $allowedv;
        }
        $sv->error_at($si, "<0>‘" . ($vstr === "" ? "(empty)" : $vstr) . "’ is not a valid choice");
        return null;
    }
    function unparse_vstr($v, Si $si) {
        return (string) $v;
    }
    function nullable($v, Si $si, SettingValues $sv) {
        return $v === ($si->default_value ?? 0);
    }
}

class Cdate_Sitype extends Sitype {
    use Positive_Sitype;
    function parse_null_vstr(Si $si) {
        return 0;
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        if ($vstr !== "") {
            $curv = $sv->oldv($si);
            return $curv > 0 ? $curv : Conf::$now;
        } else {
            return 0;
        }
    }
    function unparse_vstr($v, Si $si) {
        return $v ? "1" : "";
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
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
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
    function unparse_vstr($v, Si $si) {
        if ($v === null) {
            return "";
        } else if ($v <= 0) {
            return $v === 0 && $this->explicit_none ? "none" : "";
        } else if ($v === 1) {
            return "now";
        } else {
            return $si->conf->parseableTime($v, true);
        }
    }
    function nullable($v, Si $si, SettingValues $sv) {
        return $v < 0 || ($v === 0 && !$this->explicit_none);
    }
}

class Grace_Sitype extends Sitype {
    use Positive_Sitype;
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        if (($v = SettingParser::parse_interval($vstr)) !== false) {
            return intval($v);
        } else {
            $sv->error_at($si, "<0>Please enter a valid grace period");
            return null;
        }
    }
    function unparse_vstr($v, Si $si) {
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
}

class Nonnegint_Sitype extends Sitype {
    use Positive_Sitype;
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        if (preg_match('/\A\+?[0-9]+\z/', $vstr)) {
            return intval($vstr);
        } else if ($vstr === "" && $si->default_value !== null) {
            return $si->default_value;
        } else {
            $sv->error_at($si, "<0>Please enter a nonnegative whole number");
            return null;
        }
    }
}

class Float_Sitype extends Sitype {
    function initialize_si(Si $si) {
        $si->size = $si->size ?? 15;
        $si->placeholder = $si->placeholder ?? "none";
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        if (is_numeric($vstr)) {
            return floatval($vstr);
        } else if ($vstr === "" && $si->default_value !== null) {
            return $si->default_value;
        } else {
            $sv->error_at($si, "<0>Please enter a number");
            return null;
        }
    }
}

class String_Sitype extends Sitype {
    use Data_Sitype;
    /** @var bool */
    private $simple;
    /** @var bool */
    private $long;
    function __construct($name) {
        $this->simple = $name === "simplestring";
        $this->long = $name === "longstring";
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        if ($this->simple) {
            $s = simplify_whitespace($vstr);
        } else if ($this->long) {
            $s = cleannl($vstr);
        } else {
            $s = trim($vstr);
        }
        if ($s === "" && $si->required === true) {
            $sv->error_at($si, "<0>Entry required");
            return null;
        } else {
            return $s;
        }
    }
    function nullable($v, Si $si, SettingValues $sv) {
        return $v === ""
            || (substr($si->name, 0, 9) === "mailbody_"
                && ($sv->expand_mail_template(substr($si->name, 9), true))["body"] === $v);
    }
}

class Url_Sitype extends Sitype {
    use Data_Sitype;
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
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
}

class Email_Sitype extends Sitype {
    use Data_Sitype;
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
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
}

class EmailHeader_Sitype extends Sitype {
    use Data_Sitype;
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        $mt = new MimeText;
        $t = $mt->encode_email_header("", $vstr);
        if ($t !== false) {
            return $t != "" ? MimeText::decode_header($t) : "";
        } else {
            $sv->append_item_at($si, $mt->mi);
            return null;
        }
    }
}

class Html_Sitype extends Sitype {
    use Data_Sitype;
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        $ch = CleanHTML::basic();
        if (($t = $ch->clean($vstr)) !== false) {
            return $t;
        } else {
            $sv->error_at($si, "<5>{$ch->last_error}");
            return null;
        }
    }
}

class Tag_Sitype extends Sitype {
    use Data_Sitype;
    /** @var int */
    private $flags;
    function __construct($name) {
        $this->flags = $name === "tagbase" ? Tagger::NOVALUE : 0;
    }
    function initialize_si(Si $si) {
        $si->required = $si->required ?? true;
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        if ($vstr === "" && $si->required === false) {
            return "";
        } else if (($t = $sv->tagger()->check($vstr, $this->flags))) {
            return $t;
        } else {
            $sv->error_at($si, "<5>" . $sv->tagger()->error_html());
            return null;
        }
    }
}

class TagList_Sitype extends Sitype {
    use Data_Sitype;
    /** @var int */
    private $flags;
    /** @var ?float */
    private $min_idx;
    function __construct($type, $subtype) {
        if ($subtype === "wildcard") {
            $this->flags = Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR;
        } else if ($subtype === "wildcard_chair") {
            $this->flags = Tagger::NOPRIVATE | Tagger::NOVALUE | Tagger::ALLOWSTAR;
        } else if ($subtype === "allotment") {
            $this->flags = Tagger::NOPRIVATE | Tagger::NOCHAIR;
            $this->min_idx = 1.0;
        } else {
            $this->flags = Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE;
        }
    }
    function parse_vstr($vstr, Si $si, SettingValues $sv) {
        $ts = [];
        foreach (preg_split('/[\s,;]+/', $vstr) as $t) {
            if ($t !== "" && ($tx = $sv->tagger()->check($t, $this->flags))) {
                list($tag, $idx) = Tagger::unpack($tx);
                if ($this->min_idx !== null) {
                    $tx = $tag . "#" . max($this->min_idx, (float) $idx);
                }
                $ts[strtolower($tag)] = $tx;
            } else if ($t !== "") {
                $sv->error_at($si, "<5>" . $sv->tagger()->error_html(true));
            }
        }
        return join(" ", array_values($ts));
    }
}
