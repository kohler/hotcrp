<?php
// fmt.php -- HotCRP helper functions for message formatting i18n
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class FmtArg {
    /** @var int|string */
    public $name;
    /** @var mixed */
    public $value;
    /** @var ?int */
    public $format;

    /** @param int|string $name
     * @param ?int $format */
    function __construct($name, $value, $format = null) {
        $this->name = $name;
        $this->value = $value;
        $this->format = $format;
    }

    /** @param ?int $format
     * @return string */
    function convert_to($format) {
        return Ftext::convert_to($format, $this->format, $this->value);
    }

    /** @return FmtArg */
    static function blank() {
        return new FmtArg("", null);
    }
}

class FmtItem {
    /** @var ?string */
    public $context;
    /** @var string */
    public $out;
    /** @var ?list<string> */
    public $require;
    /** @var float */
    public $priority = 0.0;
    /** @var -1|0|1 */
    public $expand = 0;
    /** @var bool */
    public $template = false;
    /** @var ?FmtItem */
    public $next;

    const EXPAND_ALL = 0;
    const EXPAND_NONE = -1;
    const EXPAND_TEMPLATE = 1;


    /** @param string $out */
    function __construct($out) {
        $this->out = $out;
    }

    /** @param string $out
     * @param -1|0|1 $expand
     * @return FmtItem */
    static function make_template($out, $expand = 1) {
        $im = new FmtItem($out);
        $im->expand = $expand;
        $im->template = true;
        return $im;
    }

    /** @param string $context
     * @param Fmt $fmt
     * @return $this */
    function set_context($context, $fmt) {
        if ($fmt->_check > 0
            && $this->context !== null
            && !self::context_starts_with($context, $this->context)) {
            error_log("nested translation has unexpected context `{$context}`");
        }
        $this->context = ($context !== "" ? $context : null);
        return $this;
    }

    /** @param list<string> $req
     * @return $this */
    function add_require($req) {
        if ($this->require !== null) {
            $this->require = array_merge($this->require, $req);
        } else {
            $this->require = $req;
        }
        return $this;
    }


    /** @param ?string $c1
     * @param ?string $c2
     * @return bool */
    static function context_starts_with($c1, $c2) {
        $l1 = strlen($c1 ?? "");
        $l2 = strlen($c2 ?? "");
        return $l1 >= $l2
            && ($l2 === 0
                || (str_starts_with($c1, $c2)
                    && ($l1 === $l2 || $c1[$l2] === "/")));
    }

    /** @param list<string> $args
     * @return int|false */
    function check_require(Fmt $ms, $args) {
        if (!$this->require) {
            return 0;
        }
        foreach ($this->require as $req) {
            $ok = false;
            if (preg_match('/\A\s*(!*)\s*(\S+?)\s*(\z|[=!<>]=?|≠|≤|≥|!?\^=)\s*(\S*)\s*\z/', $req, $m)
                && ($m[1] === "" || ($m[3] === "" && $m[4] === ""))
                && ($m[3] === "") === ($m[4] === "")
                && $ms->test_requirement($m[2], $args, $val)) {
                $compar = $m[3];
                $cv = $compval = $m[4];
                if ($cv !== ""
                    && ($cv[0] === "\$" /* XXX */ || $cv[0] === "{" || str_starts_with($cv, "#{"))
                    && !$ms->test_requirement($cv, $args, $compval)) {
                    return false;
                }
                if ($compar === "") {
                    $bval = (bool) $val && $val !== "0";
                    $ok = $bval === (strlen($m[1]) % 2 === 0);
                } else if (!is_scalar($val)) {
                    // skip
                } else if ($compar === "^=") {
                    $ok = str_starts_with($val, $compval);
                } else if ($compar === "!^=") {
                    $ok = !str_starts_with($val, $compval);
                } else if (is_numeric($compval)) {
                    $ok = CountMatcher::compare((float) $val, $compar, (float) $compval);
                } else if ($compar === "=" || $compar === "==") {
                    $ok = (string) $val === (string) $compval;
                } else if ($compar === "!=" || $compar === "≠") {
                    $ok = (string) $val !== (string) $compval;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return count($this->require);
    }
}

class FmtContext {
    /** @var Fmt
     * @readonly */
    public $fmt;
    /** @var ?string */
    public $context;
    /** @var list */
    public $args;
    /** @var int */
    public $argnum = 0;
    /** @var bool */
    private $need_format = true;
    /** @var ?int */
    public $format;
    /** @var ?int */
    public $pos;

    /** @var ?bool */
    static private $complained;

    /** @param Fmt $fmt
     * @param ?string $context
     * @param list $args */
    function __construct($fmt, $context, $args) {
        $this->fmt = $fmt;
        $this->context = $context;
        $this->args = $args;
    }

    /** @param ?int $format
     * @return $this */
    function set_format($format) {
        $this->format = $format;
        return $this;
    }

    /** @param string $detail
     * @return array{?int,string} */
    function complain($detail) {
        if (!self::$complained) {
            self::$complained = true;
            error_log("invalid Fmt replacement: {$detail}\n" . debug_string_backtrace());
        }
        return [0, "ERROR"];
    }

    /** @param string $fspec
     * @param ?int $vformat
     * @param array $value
     * @param -1|0|1 $expansion
     * @return array{?int,mixed} */
    private function apply_fmtspec_array($fspec, $vformat, $value, $expansion) {
        if ($fspec === ":list") {
            return [$vformat, commajoin($value)];
        } else if ($fspec === ":lcrestlist") {
            for ($i = 1; $i < count($value); ++$i) {
                if (is_string($value[$i])) {
                    $value[$i] = strtolower($value[$i]);
                }
            }
            return [$vformat, commajoin($value)];
        } else if ($fspec === ":nblist") {
            $value = array_values($value);
            $n = count($value);
            $xvalue = "";
            for ($i = 0; $i !== $n; ++$i) {
                $v = Ftext::convert_to($this->format, $vformat, $value[$i]);
                if ($i < $n - 1 && $n > 2) {
                    $v .= ",";
                }
                if ($this->format === 5) {
                    $v = "<span class=\"nb\">{$v}</span>";
                }
                if ($i < $n - 1) {
                    $v .= $i < $n - 2 ? " " : " and ";
                }
                $xvalue .= $v;
            }
            return [$this->format, $xvalue];
        } else if ($fspec === ":numlist") {
            return [$vformat, numrangejoin($value)];
        } else if ($fspec === ":numlist#") {
            $a = unparse_numrange_list($value);
            foreach ($a as &$x) {
                $x = "#{$x}";
            }
            unset($x);
            return [$vformat, commajoin($a)];
        } else if (str_starts_with($fspec, ":plural ")) {
            $word = $this->expand(ltrim(substr($fspec, 8)), $expansion);
            return [$vformat, plural_word(count($value), substr($fspec, 8))];
        }
        return $this->complain("{$fspec} does not expect array");
    }

    /** @param string $fspec
     * @param ?int $vformat
     * @param mixed $value
     * @param -1|0|1 $expansion
     * @return array{?int,mixed} */
    function apply_fmtspec($fspec, $vformat, $value, $expansion) {
        if ($fspec === ":j") {
            return [0, json_encode_db($value)];
        } else if ($fspec === ":jx") {
            if (is_int($value)) {
                return [0, sprintf("0x%x", $value)];
            }
            return [0, json_encode_db($value)];
        }

        if (is_array($value)) {
            return $this->apply_fmtspec_array($fspec, $vformat, $value, $expansion);
        }

        if ($fspec === ":time" || $fspec === ":expandedtime") {
            if ($value instanceof DateTimeInterface) {
                $value = $value->getTimestamp();
            } else if (!is_int($value)) {
                return $this->complain("unexpected value type for `{$fspec}`");
            }
            if ($this->fmt->conf) {
                if ($fspec === ":expandedtime" && ($this->format ?? 5) === 5) {
                    return [5, $this->fmt->conf->unparse_time_with_local_span($value)];
                } else {
                    return [0, $this->fmt->conf->unparse_time_long($value)];
                }
            } else if ($value <= 0) {
                return [0, "N/A"];
            } else {
                return [0, date("l j M Y g:i:sa", $value)];
            }
        }

        if (!is_scalar($value)) {
            return $this->complain("unexpected value type");
        }

        if ($fspec === ":url") {
            return [null, urlencode((string) $value)];
        } else if ($fspec === ":html") { // unneeded if FmtArg has correct format
            return [null, htmlspecialchars((string) $value, ENT_QUOTES)];
        } else if ($fspec === ":humanize_url") {
            if (preg_match('/\Ahttps?:\/\/([^\[\]:\/?#\s]*)([\/?#]\S*|)\z/i', (string) $value, $mm)) {
                $value = $mm[1] . ($mm[2] === "/" ? "" : $mm[2]);
            }
            return [$vformat, $value];
        } else if ($fspec === ":ftext") {
            if ($vformat === null) {
                list($vformat, $value) = Ftext::parse((string) $value, 0);
            }
            if ($this->pos === 0 && $this->format === null) {
                $value = "<{$vformat}>{$value}";
                $vformat = null;
            }
            return [$vformat, $value];
        } else if ($fspec === ":sq") {
            if (!preg_match('/\A[-a-zA-Z_0-9.:]+\z/', $value)) {
                $value = "\"{$value}\"";
            }
            return [$vformat, $value];
        } else if ($fspec === ":nonempty") {
            if ($value === "") {
                return [0, "<empty>"];
            }
            return [$vformat, $value];
        } else if (str_starts_with($fspec, ":plural ")) {
            $word = $this->expand(ltrim(substr($fspec, 8)), $expansion);
            return [$vformat, plural_word($value, $word)];
        } else if (preg_match('/\A:[-+]?\d*(?:|\.\d+)[difgGxX]\z/', $fspec)) {
            if (!is_numeric($value)) {
                return $this->complain("{$fspec} expected number");
            }
            return [$vformat, sprintf("%" . substr($fspec, 1), $value)];
        }
        return $this->complain("unknown format specification {$fspec}");
    }

    /** @param string $s
     * @param -1|0|1 $expansion
     * @return string */
    function expand($s, $expansion = 0) {
        if ($s === null || $s === false || $s === "" || $expansion === -1) {
            return $s;
        }

        $pos = $bpos = 0;
        $len = strlen($s);
        $ppos = $lpos = $rpos = -1;
        $t = "";

        while (true) {
            if ($ppos < $pos) {
                $ppos = strpos($s, "%", $pos);
                $ppos = $ppos !== false ? $ppos : $len;
            }
            if ($lpos < $pos) {
                $lpos = strpos($s, "{", $pos);
                $lpos = $lpos !== false ? $lpos : $len;
            }
            if ($rpos < $pos) {
                $rpos = strpos($s, "}", $pos);
                $rpos = $rpos !== false ? $rpos : $len;
            }

            $pos = min($ppos, $lpos, $rpos);
            if ($pos === $len) {
                $t .= (string) substr($s, $bpos);
                break;
            }

            $x = null;
            $npos = $pos + 1;
            if ($npos < $len && $s[$pos] === $s[$npos]) {
                if ($expansion === 0) {
                    $x = $s[$pos];
                    ++$npos;
                }
            } else {
                if ($this->need_format) {
                    $this->format = Ftext::format($s) ?? $this->format;
                    $this->need_format = false;
                }
                if ($pos === $ppos) {
                    list($npos, $x) = $this->expand_percent($s, $pos, $expansion);
                } else if ($pos === $lpos) {
                    list($npos, $x) = $this->expand_brace($s, $pos, $expansion);
                }
            }

            if ($x !== null) {
                $t .= substr($s, $bpos, $pos - $bpos) . $x;
                $bpos = $npos;
            }
            $pos = $npos;
        }

        return $t;
    }

    /** @param string $s
     * @param int $pos
     * @param -1|0|1 $expansion
     * @return array{int,?string} */
    private function expand_percent($s, $pos, $expansion) {
        if (preg_match('/%((?!\d)\w+)%/A', $s, $m, 0, $pos)) {
            if (($fa = Fmt::find_arg($this->args, strtolower($m[1])))) {
                // error_log("Old percent specification (arg {$m[1]}) in Fmt");
                return [$pos + strlen($m[0]), $fa->convert_to($this->format)];
            } else if (($imt = $this->fmt->find($this->context, strtolower($m[1]), [], null, 2))
                       && $imt->template) {
                // error_log("Old percent specification (template {$m[1]}) in Fmt");
                return [$pos + strlen($m[0]), $this->expand($imt->out, $imt->expand)];
            }
        }
        return [$pos + 1, null];
    }

    static private function skip_fmtspec($s, $pos, &$fmtspecs) {
        $br = 0;
        $len = strlen($s);
        $pos0 = $pos;
        ++$pos;
        while ($pos !== $len) {
            $ch = $s[$pos];
            if ($ch === "{") {
                ++$br;
            } else if ($ch === "}") {
                if ($br === 0) {
                    $fmtspecs[] = substr($s, $pos0, $pos - $pos0);
                    return $pos + 1;
                }
                --$br;
            } else if ($ch === ":") {
                if ($pos === $pos0 + 1) {
                    return false;
                }
                $fmtspecs[] = substr($s, $pos0, $pos - $pos0);
                $pos0 = $pos;
            }
            ++$pos;
        }
        return false;
    }

    /** @param string $s
     * @param int $pos
     * @param -1|0|1 $expansion
     * @return array{int,?string} */
    private function expand_brace($s, $pos, $expansion) {
        if (!preg_match('/\{(|0|[1-9]\d*+|[a-zA-Z_][-\w]*+)(|\[[^\]]*+\])(:(?!\})|\})/A', $s, $m, 0, $pos)
            || ($m[1] === "" && ($this->argnum === null || $m[2] !== ""))
            || ($expansion !== 0 && ($m[1] === "" || ctype_digit($m[1])))) {
            return [$pos + 1, null];
        }
        $fmtspecs = [];
        $epos = $pos + strlen($m[0]);
        if ($m[3] === ":") {
            $epos = self::skip_fmtspec($s, $epos - 1, $fmtspecs);
            if ($epos === false) {
                return [$pos + 1, null];
            }
        }
        if ($m[1] === "") {
            $fa = Fmt::find_arg($this->args, $this->argnum);
            ++$this->argnum;
        } else if (ctype_digit($m[1])) {
            $fa = Fmt::find_arg($this->args, intval($m[1]));
        } else {
            $fa = Fmt::find_arg($this->args, $m[1]);
        }
        if (!$fa
            && ($imt = $this->fmt->find($this->context, $m[1], [], null, 2))
            && $imt->template) {
            list($format, $out) = Ftext::parse($this->expand($imt->out, $imt->expand));
            $fa = new FmtArg("", $out, $format);
        }
        if (!$fa) {
            return [$pos + 1, null];
        }
        if ($m[2]) {
            assert(is_array($fa->value));
            $value = $fa->value[substr($m[2], 1, -1)] ?? null;
        } else {
            $value = $fa->value;
        }
        $vformat = $fa->format;

        $this->pos = $pos;
        if ($this->format === null && $pos !== 0) {
            $this->format = Ftext::format($s);
        }

        foreach ($fmtspecs as $fmtspec) {
            list($vformat, $value) = $this->apply_fmtspec($fmtspec, $vformat, $value, $expansion);
        }

        if (!is_scalar($value)) {
            if ($value instanceof DateTimeInterface) {
                list($vformat, $value) = $this->apply_fmtspec(":time", $vformat, $value, $expansion);
            } else {
                list($vformat, $value) = $this->complain("require scalar value");
            }
        }
        return [$epos, Ftext::convert_to($this->format, $vformat, $value)];
    }
}

class Fmt {
    /** @var ?Conf */
    public $conf;
    /** @var array<string,FmtItem> */
    private $ims = [];
    /** @var list<callable(string):(false|array{true,mixed})> */
    private $_require_resolvers = [];
    /** @var int */
    public $_check = 0;
    /** @var ?string */
    private $_default_in;
    /** @var FmtItem */
    private $_default_item;
    /** @var ?list */
    private $_sources;

    const PRIO_OVERRIDE = 1000.0;


    /** @param ?Conf $conf */
    function __construct($conf = null) {
        $this->conf = $conf;
        $this->_default_item = new FmtItem(null);
    }

    /** @return $this */
    function fmt() {
        return $this;
    }

    /** @param int|float $p */
    function set_default_priority($p) {
        $this->_default_item->priority = (float) $p;
    }

    function clear_default_priority() {
        $this->_default_item->priority = 0.0;
    }

    /** @param object $m
     * @return bool */
    private function _addj_object($m) {
        $im = clone $this->_default_item;
        if (isset($m->context) && is_string($m->context) && $m->context !== "") {
            $im->set_context($m->context, $this);
        }
        if (isset($m->priority) && (is_float($m->priority) || is_int($m->priority))) {
            $im->priority = (float) $m->priority;
        }
        if (isset($m->require) && is_array($m->require)) {
            $im->add_require($m->require);
        }
        if (isset($m->expand)) {
            if ($m->expand === -1 || $m->expand === "none") {
                $im->expand = -1;
            } else if ($m->expand === 1 || $m->expand === "template") {
                $im->expand = 1;
            }
        }
        if (isset($m->template) && is_bool($m->template)) {
            $im->template = $m->template;
        }

        $members = $m->m ?? $m->members /* XXX */ ?? null;
        if (is_array($members)) {
            $save_default_in = $this->_default_in;
            $save_default_item = $this->_default_item;
            if (!isset($this->_default_in) && isset($m->in) && is_string($m->in)) {
                $this->_default_in = $m->in;
            }
            $this->_default_item = $im;
            $ret = true;
            foreach ($members as $mm) {
                $ret = $this->addj($mm) && $ret;
            }
            $this->_default_in = $save_default_in;
            $this->_default_item = $save_default_item;
            return $ret;
        }

        if (isset($this->_default_in)) {
            $in = $this->_default_in;
            $out = $m->out ?? $in;
        } else if (isset($m->id) /* XXX */) {
            $in = $m->id;
            $out = $m->otext ?? $m->itext ?? null;
        } else {
            $in = $m->in ?? $m->itext /* XXX */ ?? null;
            $out = $m->out ?? $m->otext /* XXX */ ?? $in;
        }
        if (!is_string($in) || !is_string($out)) {
            return false;
        }

        $im->out = $out;
        $this->define($in, $im);
        return true;
    }

    /** @param list $m */
    private function _addj_list($m) {
        $im = clone $this->_default_item;
        if ($this->_default_in !== null) {
            $context = "";
            $in = $this->_default_in;
        } else {
            $context = $in = null;
        }
        $out = $prio = $req = null;
        foreach ($m as $e) {
            if (is_string($e)) {
                if ($out !== null) {
                    if ($context !== null) {
                        return false;
                    } else if ($in !== null) {
                        $im->set_context(($context = $in), $this);
                    }
                    $in = $out;
                }
                $out = $e;
            } else if (is_int($e) || is_float($e)) {
                if ($prio !== null) {
                    return false;
                }
                $im->priority = $prio = (float) $e;
            } else if (is_array($e)) {
                if ($req !== null) {
                    return false;
                }
                $im->add_require(($req = $e));
            } else {
                return false;
            }
        }
        $out = $out ?? $in;
        if ($out !== null) {
            $im->out = $out;
            $this->define($in ?? $out, $im);
            return true;
        } else {
            return false;
        }
    }

    /** @param array{string,string}|array{string,string,int}|object|array<string,mixed> $m */
    function addj($m) {
        if (is_array($m)) {
            if (array_is_list($m)) {
                return $this->_addj_list($m);
            }
            return $this->_addj_object((object) $m);
        } else if (is_object($m)) {
            return $this->_addj_object($m);
        } else if (is_string($m)) {
            return $this->_addj_list([$m]);
        }
        return false;
    }

    /** @param string $in
     * @param string|FmtItem $out
     * @return $this */
    function define($in, $out) {
        if (is_string($out)) {
            $im = clone $this->_default_item;
            $im->out = $out;
        } else {
            $im = $out;
        }
        if ($this->_check > 0
            && $im->template
            && !preg_match('/\A[A-Za-z_]\w+\z/', $in)) {
            error_log("bad template name {$in}");
        }
        $im->next = $this->ims[$in] ?? null;
        $this->ims[$in] = $im;
        return $this;
    }

    /** @param string $in
     * @param string $out
     * @return $this */
    function define_template($in, $out) {
        $im = clone $this->_default_item;
        $im->out = $out;
        $im->template = true;
        $this->define($in, $im);
        return $this;
    }

    /** @param string $in
     * @return bool */
    function has_override($in) {
        $im = $this->ims[$in] ?? null;
        return $im && $im->priority === self::PRIO_OVERRIDE;
    }

    /** @param string $in
     * @param string $out
     * @return $this */
    function define_override($in, $out) {
        $imp = $this->ims[$in] ?? null;
        $im = new FmtItem($out);
        $im->priority = self::PRIO_OVERRIDE;
        $im->expand = $imp ? $imp->expand : FmtItem::EXPAND_TEMPLATE;
        $im->template = $imp && $imp->template;
        $this->define($in, $im);
        return $this;
    }

    function remove_overrides() {
        $ids = [];
        foreach ($this->ims as $id => $im) {
            if ($im->priority >= self::PRIO_OVERRIDE)
                $ids[] = $id;
        }
        foreach ($ids as $id) {
            while (($im = $this->ims[$id]) && $im->priority >= self::PRIO_OVERRIDE) {
                $this->ims[$id] = $im->next;
            }
            if (!$im) {
                unset($this->ims[$id]);
            }
        }
    }

    /** @param callable(string):(false|array{true,mixed}) $function */
    function add_requirement_resolver($function) {
        $this->_require_resolvers[] = $function;
    }

    /** @param ?string $context
     * @param string $in */
    private function _record_source($context, $in) {
        $lm = caller_landmark(2, '/\AFmt::|::_[ci]?5?\z/');
        if ($lm && preg_match('/\A(.*?):(\d+)/', $lm, $m)) {
            $file = $m[1];
            if (str_starts_with($file, SiteLoader::$root . "/")) {
                $file = substr($file, strlen(SiteLoader::$root) + 1);
            }
            $line = (int) $m[2];
        } else {
            $file = "";
            $line = 0;
        }
        $this->_sources[] = [$context ?? "", $in, $file, $line, 1, Conf::$now];
        if (count($this->_sources) % 512 === 0) {
            $this->record_sources(true);
        }
    }

    /** @param null|bool|float $limit */
    function record_sources($limit) {
        if (is_bool($limit)) {
            $limit = $limit ? 1.0 : 0.0;
        } else if (!is_float($limit)) {
            $limit = (float) $limit;
        }
        $cdb = $this->conf ? $this->conf->contactdb() : null;
        if ($cdb && !empty($this->_sources)) {
            Dbl::qe($cdb, "insert into MessageSources (context, `in`, file, line, count, timestamp) values ?v ?U on duplicate key update file=?U(file), line=?U(line), count=count+1, timestamp=?U(timestamp)", $this->_sources);
            $this->_sources = [];
        }
        if ($cdb && ($limit >= 1 || ($limit > 0 && mt_rand() < $limit * (mt_getrandmax() + 1)))) {
            if ($this->_sources === null) {
                register_shutdown_function([$this, "record_sources"], false);
                $this->_sources = [];
            }
        } else {
            $this->_sources = null;
        }
    }

    /** @param ?string $context
     * @param string $in
     * @param list<mixed> $args
     * @param ?float $priobound
     * @param 0|1|2 $source
     * @return ?FmtItem */
    function find($context, $in, $args, $priobound, $source) {
        if ($this->_sources !== null && $source !== 2) {
            $this->_record_source($context, $in);
        }
        $match = null;
        $matchnreq = $matchctxlen = 0;
        $ctxlen = strlen($context ?? "");
        $priobound = $priobound ?? INF;
        for ($im = $this->ims[$in] ?? null; $im; $im = $im->next) {
            // check priority
            if ($im->priority >= $priobound
                || ($match && $im->priority < $match->priority)) {
                continue;
            }
            // check context match
            $ctxpfx = strlen($im->context ?? "");
            if ($ctxpfx > $ctxlen
                || ($ctxpfx > 0 && !FmtItem::context_starts_with($context, $im->context))
                || ($match && $im->priority == $match->priority && $ctxpfx < $matchctxlen)) {
                continue;
            }
            // check requirements
            $nreq = $im->require ? $im->check_require($this, $args) : 0;
            if ($nreq === false
                || ($match && $im->priority == $match->priority && $ctxpfx === $matchctxlen && $nreq <= $matchnreq)) {
                continue;
            }
            // new winner
            $match = $im;
            $matchctxlen = $ctxpfx;
            $matchnreq = $nreq;
        }
        return $match;
    }

    /** @param list $args
     * @param int|string $argdef
     * @return ?FmtArg */
    static function find_arg($args, $argdef) {
        $arg = null;
        if (is_string($argdef)) {
            foreach ($args as $arg) {
                if ($arg instanceof FmtArg
                    && strcasecmp($arg->name, $argdef) === 0) {
                    return $arg;
                }
            }
        } else if (is_int($argdef) && $argdef >= 0 && $argdef < count($args)) {
            $arg = $args[$argdef];
            if (!($arg instanceof FmtArg)) {
                return new FmtArg($argdef, $arg);
            } else if ($arg->name === $argdef) {
                return $arg;
            }
        }
        return null;
    }

    /** @param string $s */
    private function resolve_requirement($s) {
        foreach ($this->_require_resolvers as $fn) {
            if (($v = call_user_func($fn, $s)))
                return $v[1];
        }
        return null;
    }

    /** @param string $s
     * @param list $args
     * @param ?string &$val
     * @return bool */
    function test_requirement($s, $args, &$val) {
        $pos = 0;
        $len = strlen($s);
        if ($pos !== $len && $s[$pos] === "#") {
            $iscount = true;
            ++$pos;
        } else {
            $iscount = false;
        }

        if ($pos !== $len
            && $s[$pos] === "{"
            && preg_match('/\{(0|[1-9]\d*|[A-Za-z_]\w*)(|\[[^\]]+\])\}\z/A', $s, $m, 0, $pos)) {
            if (($fa = Fmt::find_arg($args, ctype_digit($m[1]) ? intval($m[1]) : $m[1]))) {
                $val = $fa->value;
            } else {
                return false;
            }
            $component = $m[2] === "" ? null : substr($m[2], 1, -1);
        } else if ($pos !== $len
                   && $s[$pos] === "\$"
                   && preg_match('/\$([1-9]\d*)(|\[[^\]]+\])\z/A', $s, $m, 0, $pos)) {
            if (($fa = Fmt::find_arg($args, intval($m[1]) - 1))) {
                $val = $fa->value;
            } else {
                return false;
            }
            $component = $m[2] === "" ? null : substr($m[2], 1, -1);
        } else {
            if (($bpos = strpos($s, "[", $pos)) !== false
                && $bpos !== $len - 1
                && $s[$len - 1] === "]") {
                $val = $this->resolve_requirement(substr($s, $pos, $bpos - $pos));
                $component = substr($s, $bpos + 1, $len - $bpos - 2);
            } else {
                $val = $this->resolve_requirement($s);
                $component = null;
            }
        }

        if ($component !== null) {
            if (is_array($val)) {
                $val = $val[$component] ?? null;
            } else if (is_object($val)) {
                $val = $val->$component ?? null;
            } else {
                return false;
            }
        }

        if ($iscount) {
            if (is_array($val)) {
                $val = count($val);
            } else {
                return false;
            }
        }

        return true;
    }

    /** @param string $out
     * @return string */
    function _x($out, ...$args) {
        $fctx = new FmtContext($this, null, $args);
        return $fctx->expand($out, 0);
    }

    /** @param string $in
     * @return string */
    function _($in, ...$args) {
        if (($im = $this->find(null, $in, $args, null, 0))) {
            $in = $im->out;
        }
        $fctx = new FmtContext($this, null, $args);
        return $fctx->expand($in, $im ? $im->expand : 0);
    }

    /** @param ?string $context
     * @param string $in
     * @return string */
    function _c($context, $in, ...$args) {
        if (($im = $this->find($context, $in, $args, null, 0))) {
            $in = $im->out;
        }
        $fctx = new FmtContext($this, $context, $args);
        return $fctx->expand($in, $im ? $im->expand : 0);
    }

    /** @param string $id
     * @return ?string */
    function _i($id, ...$args) {
        if (!($im = $this->find(null, $id, $args, null, 1))) {
            return null;
        }
        $fctx = new FmtContext($this, null, $args);
        return $fctx->expand($im->out, $im->expand);
    }

    /** @param ?string $context
     * @param string $id
     * @return ?string */
    function _ci($context, $id, ...$args) {
        if (!($im = $this->find($context, $id, $args, null, 1))) {
            return null;
        }
        $fctx = new FmtContext($this, $context, $args);
        return $fctx->expand($im->out, $im->expand);
    }

    /** @param FieldRender $fr
     * @param ?string $context
     * @param string $id */
    function render_ci($fr, $context, $id, ...$args) {
        if (!($im = $this->find($context, $id, $args, null, 1))) {
            return;
        }
        $fctx = new FmtContext($this, $context, $args);
        $fctx->set_format($fr->value_format);
        $s = $fctx->expand($im->out, $im->expand);
        list($fr->value_format, $fr->value) = Ftext::parse($s, $fr->value_format);
    }

    /** @param string $id
     * @return ?string */
    function default_translation($id, ...$args) {
        $im = $this->find(null, $id, $args, self::PRIO_OVERRIDE, 1);
        return $im ? $im->out : null;
    }

    /** @param string $text
     * @return string */
    static function simple($text, ...$args) {
        $fctx = new FmtContext(new Fmt, null, $args);
        return $fctx->expand($text, 0);
    }
}
