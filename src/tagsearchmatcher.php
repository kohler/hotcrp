<?php
// tagsearchmatcher.php -- HotCRP helper class for tag search
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TagSearchMatcher {
    /** @var Contact */
    public $user;
    /** @var ?string */
    private $_re;
    /** @var bool */
    private $_include_twiddles = false;
    /** @var bool */
    private $_avoid_regex = false;
    /** Defines the class of match.
     *
     * * -2: Matches if no visible tags (is_empty()).
     * * -1: Matches if any visible tags.
     * * 0: Matches tags given by patterns (might include `*` or regex).
     * * 1: Matches two or more literal tags.
     * * 2: Matches exactly one literal tag.
     *
     * @var -2|-1|0|1|2 */
    private $_mtype = 0;
    /** @var list<string> */
    private $_tagpat = [];
    /** @var list<string> */
    private $_tagregex = [];
    /** @var list<string> */
    private $_sql_tagregex = [];
    /** @var ?string */
    private $_tag_exclusion_regex;
    /** @var list<CountMatcher> */
    private $_valm = [];
    private $_errors;

    function __construct(Contact $user) {
        $this->user = $user;
    }
    function set_include_twiddles(bool $on) {
        $this->_include_twiddles = $on;
    }
    function set_avoid_regex(bool $on) {
        $this->_avoid_regex = $on;
    }
    /** @return list<string> */
    function error_texts() {
        return $this->_errors ?? [];
    }

    /** @param string $tag
     * @param bool $allow_star_any */
    function add_check_tag($tag, $allow_star_any) {
        $xtag = $tag;
        $twiddle = strpos($xtag, "~");

        $checktag = substr($xtag, (int) $twiddle);
        $tagger = new Tagger($this->user);
        if (!$tagger->check($checktag, Tagger::NOVALUE | ($allow_star_any ? Tagger::ALLOWRESERVED | Tagger::ALLOWSTAR : 0))) {
            $this->_errors[] = $tagger->error_html();
            return false;
        }

        if ($twiddle === false
            || ($twiddle === 0 && str_starts_with($xtag, "~~"))) {
            $this->add_tag($xtag);
        } else if ($twiddle > 0) {
            $c = substr($xtag, 0, $twiddle);
            if (ctype_digit($c)) {
                $cids = [(int) $c];
            } else {
                $cids = ContactSearch::make_pc($c, $this->user)->user_ids();
            }
            if (empty($cids)) {
                $this->_errors[] = "#" . htmlspecialchars($tag) . " matches no users.";
                return false;
            }
            if ($this->user->can_view_some_peruser_tag()) {
                $xcids = $cids;
            } else if (in_array($this->user->contactId, $cids)) {
                $xcids = [$this->user->contactId];
            } else {
                $this->_errors[] = "You can’t search other users’ twiddle tags.";
                return false;
            }
            if (count($xcids) > 1 && !$allow_star_any) {
                $this->_errors[] = "Wildcard searches like #" . htmlspecialchars($tag) . " aren’t allowed here.";
                return false;
            }
            if (count($xcids) === 1 || $this->_avoid_regex) {
                foreach ($xcids as $xcid) {
                    $this->add_tag($xcid . $checktag);
                }
            } else if ($c === "*") {
                $ct = str_replace("\\*", "\\S*", preg_quote($checktag));
                $this->add_tag_regex(" \\d+{$ct}#", "[0-9]+" . str_replace("\\S*", ".*", $ct));
            } else {
                $ct = str_replace("\\*", "\\S*", preg_quote($checktag));
                $cidt = join("|", $xcids);
                $this->add_tag_regex(" (?:{$cidt}){$ct}#", "({$cidt})" . str_replace("\\S*", ".*", $ct));
            }
        } else {
            $this->add_tag($this->user->contactId . $xtag);
        }
        return true;
    }

    /** @param string $tag */
    function add_tag($tag) {
        if ($tag === "any" || $tag === "none") {
            $this->_mtype = $tag === "any" ? -1 : -2;
            $this->_tagpat = $this->_tagregex = [];
        } else if ($this->_mtype >= 0) {
            $this->_tagpat[] = $tag;
            if ($this->_include_twiddles && strpos($tag, "~") === false)  {
                $this->_tagpat[] = $this->user->contactId . "~" . $tag;
                if ($this->user->privChair) {
                    $this->_tagpat[] = "~~" . $tag;
                }
            }
            if (empty($this->_tagregex)
                && $this->_tag_exclusion_regex === null
                && strpos($tag, "*") === false) {
                $this->_mtype = count($this->_tagpat) === 1 ? 2 : 1;
            } else {
                $this->_mtype = min($this->_mtype, 0);
            }
        }
        $this->_re = null;
    }

    /** @param string $regex
     * @param string $sql_regex */
    private function add_tag_regex($regex, $sql_regex) {
        assert($regex[0] === " " && $regex[strlen($regex) - 1] === "#");
        if ($this->_mtype >= 0)  {
            $this->_tagregex[] = $regex;
            $this->_sql_tagregex[] = $sql_regex;
            $this->_mtype = 0;
            $this->_re = null;
        }
    }

    /** @param string $regex */
    function set_tag_exclusion_regex($regex) {
        $this->_tag_exclusion_regex = $regex;
        $this->_mtype = min($this->_mtype, 0);
        $this->_re = null;
    }

    function add_value_matcher(CountMatcher $valm) {
        $this->_valm[] = $valm;
    }


    /** @return string|false */
    function single_tag() {
        return $this->_mtype === 2 ? $this->_tagpat[0] : false;
    }

    /** @return list<string> */
    function tag_patterns() {
        return empty($this->_tagregex) ? $this->_tagpat : [];
    }

    /** @return string */
    function regex() {
        if ($this->_re === null) {
            $res = $this->_tagregex;
            foreach ($this->_tagpat as $tp) {
                $starpos = strpos($tp, "*");
                if ($starpos === 0) {
                    if ($tp !== "*" && $tp[1] === "~") {
                        $res[] = ' ' . str_replace("\\*", "\\S*", preg_quote($tp)) . "#";
                    } else {
                        $res[] = ' (?![~\d])' . str_replace("\\*", "\\S*", preg_quote($tp)) . "#";
                    }
                } else if ($starpos !== false) {
                    $res[] = ' ' . str_replace('\\*', '\\S*', preg_quote($tp)) . "#";
                } else {
                    $res[] = ' ' . preg_quote($tp) . "#";
                }
            }
            if ($this->_mtype < 0) {
                $rx = "(?!\\d)\\S*|{$this->user->contactId}~\\S*";
                if ($this->user->privChair) {
                    $rx .= "|~~\\S*";
                }
                $res[] = ' (?:' . $rx . ')#';
            }
            if (empty($res)) {
                // add something that will never match
                $res[] = '###';
            }
            if ($this->_tag_exclusion_regex) {
                $this->_re = '{(?!' . $this->_tag_exclusion_regex . ')(?:' . join("|", $res) . ')}i';
            } else {
                $this->_re = '{' . join("|", $res) . '}i';
            }
        }
        return $this->_re;
    }


    private function sqlexpr_tagpart($table) {
        if ($this->_mtype > 0) {
            return Dbl::format_query($this->user->conf->dblink, "$table.tag?a", $this->_tagpat);
        } else if ($this->_mtype === 0
                   && (!empty($this->_sql_tagregex) || !empty($this->_tagpat))) {
            $res = $this->_sql_tagregex;
            foreach ($this->_tagpat as $tp) {
                $res[] = str_replace('\\*', '.*', preg_quote($tp));
            }
            $regex = count($res) > 1 ? "^(" . join("|", $res) . ")$" : "^{$res[0]}$";
            $dbl = $this->user->conf->dblink;
            return Dbl::format_query($dbl, "$table.tag regexp " . Dbl::utf8ci($dbl, "?"), $regex);
        } else {
            return null;
        }
    }

    /** @return ?string */
    function sqlexpr($table) {
        if (($setp = $this->sqlexpr_tagpart($table)) !== null) {
            $s = [$setp];
            foreach ($this->_valm as $valm) {
                $s[] = "$table.tagIndex" . $valm->comparison();
            }
            return "(" . join(" and ", $s) . ")";
        } else {
            return null;
        }
    }

    /** @return bool */
    function is_sqlexpr_precise() {
        return $this->_mtype > 0;
    }

    /** @return bool */
    function test_empty() {
        return $this->_mtype === -2;
    }

    /** @return bool */
    function is_empty_after_exclusion() {
        if ($this->_tag_exclusion_regex
            && !empty($this->_tagpat)
            && empty($this->_tagregex)
            && $this->_mtype === 0) {
            $tl = " " . join("# ", $this->_tagpat) . "#";
            if (strpos($tl, "*") === false) {
                return !$this->test_ignore_value($tl);
            }
        }
        return false;
    }

    /** @param string $taglist
     * @return bool */
    function test_ignore_value($taglist) {
        if ($this->_mtype === 2) {
            return stripos($taglist, " {$this->_tagpat[0]}#") !== false;
        } else if ($this->_mtype === -2) {
            return !preg_match($this->regex(), $taglist);
        } else {
            return preg_match($this->regex(), $taglist);
        }
    }

    /** @param int|float $value
     * @return bool */
    function test_value($value) {
        foreach ($this->_valm as $valm) {
            if (!$valm->test($value))
                return false;
        }
        return true;
    }

    /** @param string $taglist
     * @return bool */
    function test($taglist) {
        if (empty($this->_valm)) {
            return $this->test_ignore_value($taglist);
        } else {
            $pos = 0;
            while (true) {
                if ($this->_mtype === 2) {
                    if ($pos === 0) {
                        $pos = stripos($taglist, " {$this->_tagpat[0]}#");
                    } else {
                        $pos = false;
                    }
                } else {
                    if (preg_match($this->regex(), $taglist, $m, PREG_OFFSET_CAPTURE, $pos)) {
                        $pos = $m[0][1];
                    } else {
                        $pos = false;
                    }
                }
                if ($pos === false) {
                    return false;
                }
                $pos = strpos($taglist, "#", $pos);
                if ($this->test_value((float) substr($taglist, $pos + 1))) {
                    return true;
                }
                ++$pos;
            }
        }
    }

    /** @return list<string> */
    function expand() {
        if ($this->_mtype > 0) {
            return $this->_tagpat;
        } else if ($this->_mtype === -2) {
            return [];
        } else {
            $t0 = array_map(function ($t) { return " {$t}#"; },
                            (AllTags_API::run($this->user))["tags"]);
            $t1 = preg_grep($this->regex(), $t0);
            return array_map(function ($t) { return substr($t, 1, -1); }, $t1);
        }
    }
}
