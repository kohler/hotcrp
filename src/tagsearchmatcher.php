<?php
// tagsearchmatcher.php -- HotCRP helper class for tag search
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class TagSearchMatcher {
    public $user;
    private $_re;
    private $_include_twiddles = false;
    private $_avoid_regex = false;
    private $_mtype = 0;
    private $_tagpat = [];
    private $_tagregex = [];
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
    function errors() {
        return $this->_errors ?? [];
    }

    function add_check_tag($tag, $allow_multiple) {
        $xtag = $tag;
        $twiddle = strpos($xtag, "~");

        $checktag = substr($xtag, (int) $twiddle);
        $tagger = new Tagger($this->user);
        if (!$tagger->check($checktag, Tagger::NOVALUE | ($allow_multiple ? Tagger::ALLOWRESERVED | Tagger::ALLOWSTAR : 0))) {
            $this->_errors[] = $tagger->error_html;
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
                $cids = ContactSearch::make_pc($c, $this->user)->ids;
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
            if (count($xcids) > 1 && !$allow_multiple) {
                $this->_errors[] = "Wildcard searches like #" . htmlspecialchars($tag) . " aren’t allowed here.";
                return false;
            }
            if (count($xcids) === 1 || $this->_avoid_regex) {
                foreach ($xcids as $xcid) {
                    $this->add_tag($xcid . $checktag);
                }
            } else if ($c === "*") {
                $this->add_tag_regex(" \\d+" . str_replace("\\*", "\\S*", preg_quote($checktag)) . "#");
            } else {
                $this->add_tag_regex(" (?:" . join("|", $xcids) . ")" . str_replace("\\*", "\\S*", preg_quote($checktag)) . "#");
            }
        } else {
            $this->add_tag($this->user->contactId . $xtag);
        }
        return true;
    }

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
            if (count($this->_tagpat) === 1) {
                $this->_mtype = strpos($tag, "*") === false ? 2 : 0;
            } else if ($this->_mtype === 2) {
                $this->_mtype = 1;
            }
        }
        $this->_re = null;
    }

    function add_tag_regex($regex) {
        assert($regex[0] === " " && $regex[strlen($regex) - 1] === "#");
        if ($this->_mtype >= 0)  {
            $this->_tagregex[] = $regex;
            $this->_mtype = 0;
            $this->_re = null;
        }
    }

    function add_value_matcher(CountMatcher $valm) {
        $this->_valm[] = $valm;
    }


    function single_tag() {
        return $this->_mtype === 2 ? $this->_tagpat[0] : false;
    }

    function tag_patterns() {
        return empty($this->_tagregex) ? $this->_tagpat : [];
    }

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
            $this->_re = '{' . join("|", $res) . '}i';
        }
        return $this->_re;
    }


    function sqlexpr($table) {
        if ($this->_mtype > 0) {
            $s = [Dbl::format_query($this->user->conf->dblink,
                                    "$table.tag?a", $this->_tagpat)];
            foreach ($this->_valm as $valm) {
                $s[] = "$table.tagIndex" . $valm->countexpr();
            }
            return "(" . join(" and ", $s) . ")";
        } else {
            return false;
        }
    }

    function test_empty() {
        return $this->_mtype === -2;
    }

    function test($taglist) {
        if (empty($this->_valm)) {
            if ($this->_mtype === 2) {
                return stripos($taglist, " {$this->_tagpat[0]}#") !== false;
            } else if ($this->_mtype === -2) {
                return !preg_match($this->regex(), $taglist);
            } else {
                return preg_match($this->regex(), $taglist);
            }
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
                $val = (float) substr($taglist, $pos + 1);
                $ok = true;
                foreach ($this->_valm as $valm) {
                    $ok = $ok && $valm->test($val);
                }
                if ($ok) {
                    return true;
                }
                ++$pos;
            }
        }
    }

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
