<?php
// paperoptionlist.php -- HotCRP helper class for sets of paper options
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PaperOptionList implements IteratorAggregate {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var array<int,object>
     * @readonly */
    private $_jmap;
    /** @var array<int,?PaperOption> */
    private $_omap = [];
    /** @var array<int,object> */
    private $_ijmap;
    /** @var array<int,?PaperOption> */
    private $_imap = [];
    /** @var ?array<int,PaperOption> */
    private $_olist;
    /** @var ?array<int,PaperOption> */
    private $_olist_nonfinal;
    /** @var AbbreviationMatcher<PaperOption> */
    private $_nonpaper_am;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function _add_json($oj, $k) {
        if (!isset($oj->id) && $k === 0) {
            throw new ErrorException("This conference could not be upgraded from an old database schema. A system administrator must fix this problem.");
        }
        if (is_string($oj->id) && is_numeric($oj->id)) { // XXX backwards compat
            $oj->id = intval($oj->id);
        }
        if (is_int($oj->id) && $oj->id > 0) {
            if (XtParams::static_allowed($oj, $this->conf, null)
                && (!isset($this->_jmap[$oj->id])
                    || Conf::xt_priority_compare($oj, $this->_jmap[$oj->id]) <= 0)) {
                $this->_jmap[$oj->id] = $oj;
            }
            return true;
        } else {
            return false;
        }
    }

    /** @return array<int,object>
     * @suppress PhanAccessReadOnlyProperty */
    private function option_json_map() {
        if ($this->_jmap === null) {
            $this->_jmap = [];
            if (($olist = $this->conf->setting_json("options"))) {
                expand_json_includes_callback($olist, [$this, "_add_json"]);
            }
            if (($olist = $this->conf->opt("fixedOptions"))) {
                expand_json_includes_callback($olist, [$this, "_add_json"]);
            }
            $this->_jmap = array_filter($this->_jmap, "Conf::xt_enabled");
        }
        return $this->_jmap;
    }

    private function add_abbrev_matcher(AbbreviationMatcher $am, $id, $oj) {
        $cb = [$this, "option_by_id"];
        $am->add_keyword_lazy("opt{$id}", $cb, [$id], Conf::MFLAG_OPTION);
        if ($oj->name ?? null) {
            $am->add_phrase_lazy($oj->name, $cb, [$id], Conf::MFLAG_OPTION);
        }
        $oj->search_keyword = $oj->search_keyword ?? $oj->json_key ?? null;
        if ($oj->search_keyword) {
            $am->add_keyword_lazy($oj->search_keyword, $cb, [$id], Conf::MFLAG_OPTION);
        }
        if (($oj->json_key ?? null)
            && $oj->json_key !== $oj->search_keyword
            && (($oj->name ?? null)
                || strcasecmp(str_replace("_", " ", $oj->json_key), $oj->name) !== 0)) {
            $am->add_keyword_lazy($oj->json_key, $cb, [$id], Conf::MFLAG_OPTION);
        }
    }

    function populate_abbrev_matcher(AbbreviationMatcher $am) {
        $cb = [$this, "option_by_id"];
        $am->add_keyword_lazy("paper", $cb, [DTYPE_SUBMISSION], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("submission", $cb, [DTYPE_SUBMISSION], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("final", $cb, [DTYPE_FINAL], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("title", $cb, [PaperOption::TITLEID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("authors", $cb, [PaperOption::AUTHORSID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("nonblind", $cb, [PaperOption::ANONYMITYID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("contacts", $cb, [PaperOption::CONTACTSID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("abstract", $cb, [PaperOption::ABSTRACTID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("topics", $cb, [PaperOption::TOPICSID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("pc_conflicts", $cb, [PaperOption::PCCONFID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("collaborators", $cb, [PaperOption::COLLABORATORSID], Conf::MFLAG_OPTION);
        $am->add_keyword("reviews", null); // reserve keyword
        foreach ($this->option_json_map() as $id => $oj) {
            if (($oj->nonpaper ?? false) !== true) {
                $this->add_abbrev_matcher($am, $id, $oj);
            }
        }
    }

    function assign_search_keywords($nonpaper, AbbreviationMatcher $am) {
        $cb = [$this, "option_by_id"];
        foreach ($this->option_json_map() as $id => $oj) {
            if (!isset($oj->search_keyword)
                && (($oj->nonpaper ?? false) === true) === $nonpaper) {
                if ($oj->name ?? null) {
                    $e = AbbreviationEntry::make_lazy($oj->name, $cb, [$id], Conf::MFLAG_OPTION);
                    $s = $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL) ?? false;
                } else {
                    $s = false;
                }
                $oj->search_keyword = $s;
                if (($o = $this->_omap[$id] ?? null)) {
                    $o->_search_keyword = $s;
                }
            }
        }
    }

    /** @param null|Conf|SettingValuesConf $vconf
     * @param bool $all
     * @return array<int,object> */
    static function make_intrinsic_json_map(Conf $conf, $vconf, $all) {
        // start with fixed defaults
        $map = [];
        foreach (json_decode(file_get_contents(SiteLoader::find("etc/intrinsicoptions.json"))) as $j) {
            $map[$j->id] = $j;
        }

        // add information extracted from conference settings
        if ($vconf) {
            if (($xv = $vconf->opt("noAbstract")) > 0) {
                $map[PaperOption::ABSTRACTID]->required = $xv !== 2;
                $map[PaperOption::ABSTRACTID]->exists_if = $xv === 1 ? "NONE" : null;
            }
            if (($xv = $vconf->opt("noPapers")) > 0) {
                $map[DTYPE_SUBMISSION]->required = $map[DTYPE_FINAL]->required = $xv === 2 ? false : "submit";
                $map[DTYPE_SUBMISSION]->exists_if = $map[DTYPE_FINAL]->exists_if = $xv === 1 ? "NONE" : null;
            }
            if (($xv = $vconf->opt("maxAuthors") ?? 0) > 0) {
                $map[PaperOption::AUTHORSID]->max = $xv;
            }
            $xv = $vconf->setting("topic_min");
            $xv1 = $vconf->setting("topic_max");
            if ($xv > 0 || $xv1 > 0) {
                $map[PaperOption::TOPICSID]->min = $xv;
                $map[PaperOption::TOPICSID]->max = $xv1;
                $map[PaperOption::TOPICSID]->required = $xv > 0;
            }
            $xv = $vconf->setting("sub_pcconf");
            $xv1 = $vconf->setting("sub_pcconfsel");
            if (!$xv || $xv1) {
                $map[PaperOption::PCCONFID]->exists_if = $xv ? null : "NONE";
                $map[PaperOption::PCCONFID]->selectors = !!$xv1;
            }
            if (!$vconf->setting("sub_collab")) {
                $map[PaperOption::COLLABORATORSID]->exists_if = "NONE";
            }
        }

        // return unless overrides exist (and, in the case of `ioptions`, are desired)
        $s1 = $conf->opt("intrinsicOptions");
        $s2 = $all ? $conf->setting_json("ioptions") : null;
        if (!$s1 && !$s2) {
            return $map;
        }

        $accum = [];
        $callback = function ($j) use (&$accum) {
            if (is_int($j->id)) {
                $accum[$j->id][] = $j;
                return true;
            } else {
                return false;
            }
        };
        $s1 && expand_json_includes_callback($s1, $callback);
        $s2 && expand_json_includes_callback($s2, $callback);
        $xtp = new XtParams($conf, null);
        foreach ($accum as $id => $list) {
            if (isset($map[$id])) {
                $list[] = $map[$id];
                $map[$id] = $xtp->search_list($list);
            }
        }
        return $map;
    }

    /** @return array<int,object> */
    function intrinsic_json_map() {
        if ($this->_ijmap === null) {
            $this->_ijmap = self::make_intrinsic_json_map($this->conf, $this->conf, true);
        }
        return $this->_ijmap;
    }

    /** @param int $id */
    private function populate_intrinsic($id) {
        $oj = ($this->intrinsic_json_map())[$id] ?? null;
        $opt = $oj ? PaperOption::make($this->conf, $oj) : null;
        $this->_imap[$id] = $opt;
    }

    /** @param int $id
     * @return ?PaperOption */
    function option_by_id($id) {
        if ($id <= 0) {
            if (!array_key_exists($id, $this->_imap)) {
                $this->populate_intrinsic($id);
            }
            return $this->_imap[$id];
        } else {
            if (!array_key_exists($id, $this->_omap)) {
                $opt = null;
                if (($oj = ($this->option_json_map())[$id] ?? null)
                    && Conf::xt_enabled($oj)
                    && XtParams::static_allowed($oj, $this->conf, null)) {
                    $opt = PaperOption::make($this->conf, $oj);
                }
                $this->_omap[$id] = $opt;
            }
            return $this->_omap[$id];
        }
    }

    /** @param int $id
     * @return PaperOption */
    function checked_option_by_id($id) {
        $o = $this->option_by_id($id);
        if (!$o) {
            throw new ErrorException("PaperOptionList::checked_option_by_id({$id}) failed");
        }
        return $o;
    }

    /** @param string $key
     * @return ?PaperOption */
    function option_by_field_key($key) {
        // Since this function is rarely used, don’t bother optimizing it.
        if (($colon = strpos($key, ":"))) {
            $key = substr($key, 0, $colon);
        }
        foreach ($this->unsorted_field_list(null, null) as $f) {
            if ($f->field_key() === $key)
                return $f;
        }
        return null;
    }

    /** @return array<int,PaperOption> */
    function normal() {
        if ($this->_olist === null) {
            $this->_olist = [];
            foreach ($this->option_json_map() as $id => $oj) {
                if (($oj->nonpaper ?? false) !== true
                    && ($o = $this->option_by_id($id))) {
                    $this->_olist[$id] = $o;
                }
            }
            uasort($this->_olist, "PaperOption::compare");
        }
        return $this->_olist;
    }

    /** @return Iterator<PaperOption> */
    #[\ReturnTypeWillChange]
    function getIterator() {
        $this->normal();
        return new ArrayIterator($this->_olist);
    }

    /** @return array<int,PaperOption> */
    function nonfinal() {
        if ($this->_olist_nonfinal === null) {
            $this->_olist_nonfinal = [];
            foreach ($this->option_json_map() as $id => $oj) {
                if (($oj->nonpaper ?? false) !== true
                    && ($oj->final ?? false) !== true
                    && ($o = $this->option_by_id($id))) {
                    $this->_olist_nonfinal[$id] = $o;
                }
            }
            uasort($this->_olist_nonfinal, "PaperOption::compare");
        }
        return $this->_olist_nonfinal;
    }

    /** @return array<int,PaperOption> */
    function nonpaper() {
        $list = [];
        foreach ($this->option_json_map() as $id => $oj) {
            if (($oj->nonpaper ?? false) === true
                && ($o = $this->option_by_id($id)))
                $list[$id] = $o;
        }
        uasort($list, "PaperOption::compare");
        return $list;
    }

    /** @return array<int,PaperOption> */
    function universal() {
        $list = [];
        foreach ($this->option_json_map() as $id => $oj) {
            if (($o = $this->option_by_id($id)))
                $list[$id] = $o;
        }
        uasort($list, "PaperOption::compare");
        return $list;
    }

    private function _get_field($id, $oj, $nonfinal) {
        if (($oj->nonpaper ?? false) !== true
            && !($nonfinal && ($oj->final ?? false) === true)) {
            return $this->option_by_id($id);
        } else {
            return null;
        }
    }

    /** @param ?string $key
     * @return list<PaperOption> */
    private function unsorted_field_list(PaperInfo $prow = null, $key = null) {
        $nonfinal = $prow && $prow->outcome_sign <= 0;
        $olist = [];
        foreach ($this->intrinsic_json_map() as $id => $oj) {
            if ((!$key || ($oj->$key ?? null) !== false)
                && ($o = $this->_get_field($id, $oj, $nonfinal)))
                $olist[] = $o;
        }
        foreach ($this->option_json_map() as $id => $oj) {
            if ((!$key || ($oj->$key ?? null) !== false)
                && ($o = $this->_get_field($id, $oj, $nonfinal)))
                $olist[] = $o;
        }
        return $olist;
    }

    /** @param bool $all
     * @return array<int,PaperOption> */
    function form_fields(PaperInfo $prow = null, $all = false) {
        $omap = [];
        foreach ($this->unsorted_field_list($prow, "form_order") as $o) {
            if ($all || $o->on_form())
                $omap[$o->id] = $o;
        }
        uasort($omap, "PaperOption::form_compare");
        return $omap;
    }

    /** @return array<int,PaperOption> */
    function page_fields(PaperInfo $prow = null) {
        $omap = [];
        foreach ($this->unsorted_field_list($prow, "page_order") as $o) {
            if ($o->on_page())
                $omap[$o->id] = $o;
        }
        uasort($omap, "PaperOption::compare");
        return $omap;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function invalidate_options() {
        if ($this->_jmap !== null || $this->_ijmap !== null) {
            $this->_jmap = $this->_ijmap = null;
            $this->_omap = $this->_imap = [];
            $this->_olist = $this->_olist_nonfinal = $this->_nonpaper_am = null;
        }
    }

    /** @param int $id */
    function invalidate_intrinsic_option($id) {
        unset($this->_imap[$id]);
    }

    /** @return bool */
    function has_universal() {
        return count($this->option_json_map()) !== 0;
    }

    /** @return array<int,PaperOption> */
    function find_all($name) {
        $iname = strtolower($name);
        if ($iname === (string) DTYPE_SUBMISSION
            || $iname === "paper"
            || $iname === "submission") {
            return [DTYPE_SUBMISSION => $this->option_by_id(DTYPE_SUBMISSION)];
        } else if ($iname === (string) DTYPE_FINAL
                   || $iname === "final") {
            return [DTYPE_FINAL => $this->option_by_id(DTYPE_FINAL)];
        } else if ($iname === "" || $iname === "none") {
            return [];
        } else if ($iname === "any") {
            return $this->normal();
        } else if (substr($iname, 0, 3) === "opt"
                   && ctype_digit(substr($iname, 3))) {
            $o = $this->option_by_id((int) substr($iname, 3));
            return $o ? [$o->id => $o] : [];
        } else {
            if (substr($iname, 0, 4) === "opt-") {
                $name = substr($name, 4);
            }
            $omap = [];
            foreach ($this->conf->find_all_fields($name, Conf::MFLAG_OPTION) as $o) {
                $omap[$o->id] = $o;
            }
            return $omap;
        }
    }

    /** @return ?PaperOption */
    function find($name) {
        $omap = $this->find_all($name);
        reset($omap);
        return count($omap) === 1 ? current($omap) : null;
    }

    /** @return AbbreviationMatcher<PaperOption> */
    function nonpaper_abbrev_matcher() {
        // Nonpaper options aren't stored in the main abbrevmatcher; put them
        // in their own.
        if (!$this->_nonpaper_am) {
            $this->_nonpaper_am = new AbbreviationMatcher;
            foreach ($this->option_json_map() as $id => $oj) {
                if (($oj->nonpaper ?? false) === true) {
                    $this->add_abbrev_matcher($this->_nonpaper_am, $id, $oj);
                }
            }
            $this->assign_search_keywords(true, $this->_nonpaper_am);
        }
        return $this->_nonpaper_am;
    }

    /** @return array<int,PaperOption> */
    function find_all_nonpaper($name) {
        $omap = [];
        foreach ($this->nonpaper_abbrev_matcher()->find_all($name) as $o) {
            $omap[$o->id] = $o;
        }
        return $omap;
    }

    /** @return ?PaperOption */
    function find_nonpaper($name) {
        $omap = $this->find_all_nonpaper($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
    }
}
