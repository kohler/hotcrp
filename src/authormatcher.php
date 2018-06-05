<?php
// authormatcher.php -- HotCRP author matchers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class AuthorMatcher extends Author {
    public $firstName_matcher;
    public $lastName_matcher;
    public $affiliation_matcher;
    public $general_pregexes;
    public $is_author = false;

    private static $wordinfo;

    function __construct($x) {
        if (is_string($x) && ($hash = strpos($x, "#")) !== false) {
            $x = substr($x, 0, $hash);
        }
        parent::__construct($x);

        $any = [];
        if ($this->firstName !== "") {
            preg_match_all('/[a-z0-9]+/', strtolower(UnicodeHelper::deaccent($this->firstName)), $m);
            $rr = [];
            foreach ($m[0] as $w) {
                $any[] = $rr[] = $w;
                if (ctype_alpha($w[0])) {
                    if (strlen($w) === 1)
                        $any[] = $rr[] = $w . "[a-z]*";
                    else
                        $any[] = $rr[] = $w[0] . "(?=\\.)";
                }
            }
            if (!empty($rr))
                $this->firstName_matcher = (object) [
                    "preg_raw" => '\b(?:' . join("|", $rr) . ')\b',
                    "preg_utf8" => Text::UTF8_INITIAL_NONLETTERDIGIT . '(?:' . join("|", $rr) . ')' . Text::UTF8_FINAL_NONLETTERDIGIT
                ];
        }
        if ($this->lastName !== "") {
            preg_match_all('/[a-z0-9]+/', strtolower(UnicodeHelper::deaccent($this->lastName)), $m);
            $rr = $ur = [];
            foreach ($m[0] as $w) {
                $any[] = $w;
                $rr[] = '(?=.*\b' . $w . '\b)';
                $ur[] = '(?=.*' . Text::UTF8_INITIAL_NONLETTERDIGIT . $w . Text::UTF8_FINAL_NONLETTERDIGIT . ')';
            }
            if (!empty($rr))
                $this->lastName_matcher = (object) [
                    "preg_raw" => '\A' . join("", $rr),
                    "preg_utf8" => '\A' . join("", $ur)
                ];
        }

        $aff = "";
        if ($this->affiliation !== ""
            && $this->firstName === ""
            && $this->lastName === ""
            && $this->email === "") {
            $aff = $this->affiliation;
        } else if ($this->affiliation === "" && is_string($x)) {
            $aff = $x;
        }
        if ($aff !== "") {
            self::wordinfo();
            preg_match_all('/[a-z0-9&]+/', strtolower(UnicodeHelper::deaccent($aff)), $m);

            $directs = $alts = [];
            $any_weak = false;
            foreach ($m[0] as $w) {
                $aw = get(self::$wordinfo, $w);
                if ($aw && isset($aw->stop) && $aw->stop)
                    continue;
                $any[] = preg_quote($w);
                $directs[] = $w;
                if ($aw && isset($aw->weak) && $aw->weak)
                    $any_weak = true;
                if ($aw && isset($aw->alternate)) {
                    if (is_array($aw->alternate))
                        $alts = array_merge($alts, $aw->alternate);
                    else
                        $alts[] = $aw->alternate;
                }
                if ($aw && isset($aw->sync))
                    $alts[] = $aw->sync;
            }

            $rs = $directs;
            foreach ($alts as $alt) {
                if (is_object($alt)) {
                    if ((isset($alt->if) && !self::match_if($alt->if, $rs))
                        || (isset($alt->if_not) && self::match_if($alt->if_not, $rs)))
                        continue;
                    $alt = $alt->word;
                }
                foreach (explode(" ", $alt) as $altw)
                    if ($altw !== "") {
                        $any[] = preg_quote($altw);
                        $rs[] = $altw;
                        $any_weak = true;
                    }
            }

            $rex = '{\b(?:' . str_replace('&', '\\&', join("|", $rs)) . ')\b}';
            $this->affiliation_matcher = [$directs, $any_weak, $rex];
        }

        $content = join("|", $any);
        if ($content !== "" && $content !== "none") {
            $this->general_pregexes = (object) [
                "preg_raw" => '\b(?:' . $content . ')\b',
                "preg_utf8" => Text::UTF8_INITIAL_NONLETTER . '(?:' . $content . ')' . Text::UTF8_FINAL_NONLETTER
            ];
        }
    }
    function is_empty() {
        return !$this->general_pregexes;
    }
    static function make($x, $nonauthor) {
        if ($x !== "") {
            $m = new AuthorMatcher($x);
            if (!$m->is_empty()) {
                $m->nonauthor = $nonauthor;
                return $m;
            }
        }
        return null;
    }
    static function make_affiliation($x, $nonauthor) {
        return self::make((object) ["firstName" => "", "lastName" => "", "email" => "", "affiliation" => $x], $nonauthor);
    }

    const MATCH_NAME = 1;
    const MATCH_AFFILIATION = 2;
    function test($au) {
        if (!$this->general_pregexes) {
            return false;
        }
        if (is_string($au)) {
            $au = new Author($au);
        }
        if ($au->firstName_deaccent === null) {
            $au->firstName_deaccent = $au->lastName_deaccent = false;
            $au->firstName_deaccent = UnicodeHelper::deaccent($au->firstName);
            $au->lastName_deaccent = UnicodeHelper::deaccent($au->lastName);
            $au->affiliation_deaccent = strtolower(UnicodeHelper::deaccent($au->affiliation));
        }
        if ($this->lastName_matcher
            && $au->lastName !== ""
            && Text::match_pregexes($this->lastName_matcher, $au->lastName, $au->lastName_deaccent)
            && ($au->firstName === ""
                || !$this->firstName_matcher
                || Text::match_pregexes($this->firstName_matcher, $au->firstName, $au->firstName_deaccent))) {
            return self::MATCH_NAME;
        }
        if ($this->affiliation_matcher
            && $au->affiliation !== ""
            && $this->test_affiliation($au->affiliation_deaccent)) {
            return self::MATCH_AFFILIATION;
        }
        return false;
    }
    static function highlight_all($au, $matchers) {
        $aff_suffix = null;
        if (is_object($au)) {
            if ($au->affiliation)
                $aff_suffix = "(" . htmlspecialchars($au->affiliation) . ")";
            $au = $au->nameaff_text();
        }
        $pregexes = [];
        foreach ($matchers as $matcher)
            $pregexes[] = $matcher->general_pregexes;
        if (count($pregexes) > 1)
            $pregexes = [Text::merge_pregexes($pregexes)];
        if (!empty($pregexes))
            $au = Text::highlight($au, $pregexes[0]);
        if ($aff_suffix && str_ends_with($au, $aff_suffix))
            $au = substr($au, 0, -strlen($aff_suffix))
                . ' <span class="auaff">' . $aff_suffix . '</span>';
        return $au;
    }
    function highlight($au) {
        return self::highlight_all($au, [$this]);
    }

    static function wordinfo() {
        global $ConfSitePATH;
        // XXX validate input JSON
        if (self::$wordinfo === null)
            self::$wordinfo = (array) json_decode(file_get_contents("$ConfSitePATH/etc/affiliationmatchers.json"));
        return self::$wordinfo;
    }
    private function test_affiliation($mtext) {
        list($am_words, $am_any_weak, $am_regex) = $this->affiliation_matcher;
        if (!$am_any_weak)
            return preg_match($am_regex, $mtext) === 1;
        else if (!preg_match_all($am_regex, $mtext, $m))
            return false;
        $result = true;
        foreach ($am_words as $w) { // $am_words contains no alternates
            $aw = get(self::$wordinfo, $w);
            $weak = $aw && isset($aw->weak) && $aw->weak;
            $saw_w = in_array($w, $m[0]);
            if (!$saw_w && $aw && isset($aw->alternate)) {
                // We didn't see a requested word; did we see one of its alternates?
                foreach ($aw->alternate as $alt) {
                    if (is_object($alt)) {
                        if ((isset($alt->if) && !self::match_if($alt->if, $am_words))
                            || (isset($alt->if_not) && self::match_if($alt->if_not, $am_words)))
                            continue;
                        $alt = $alt->word;
                    }
                    // Check for every word in the alternate list
                    $saw_w = true;
                    $altws = explode(" ", $alt);
                    foreach ($altws as $altw)
                        if ($altw !== "" && !in_array($altw, $m[0])) {
                            $saw_w = false;
                            break;
                        }
                    // If all are found, exit; check if the found alternate is strong
                    if ($saw_w) {
                        if ($weak && count($altws) == 1) {
                            $aw2 = get(self::$wordinfo, $alt);
                            if (!$aw2 || !isset($aw2->weak) || !$aw2->weak)
                                $weak = false;
                        }
                        break;
                    }
                }
            }
            // Check for sync words: e.g., "penn state university" ≠ "university penn".
            // If *any* sync word is in matcher, then *some* sync word must be in subject.
            // If *no* sync word is in matcher, then *no* sync word allowed in subject.
            if ($saw_w && $aw && isset($aw->sync) && $aw->sync !== "") {
                $syncws = explode(" ", $aw->sync);
                $has_any_syncs = false;
                foreach ($syncws as $syncw)
                    $has_any_syncs = $has_any_syncs || in_array($syncw, $am_words);
                if ($has_any_syncs) {
                    $saw_w = false;
                    foreach ($syncws as $syncw)
                        $saw_w = $saw_w || in_array($syncw, $m[0]);
                } else {
                    $saw_w = true;
                    foreach ($syncws as $syncw)
                        $saw_w = $saw_w && !in_array($syncw, $m[0]);
                }
            }
            if ($saw_w) {
                if (!$weak)
                    return true;
            } else
                $result = false;
        }
        return $result;
    }
    private static function match_if($iftext, $ws) {
        foreach (explode(" ", $iftext) as $w)
            if ($w !== "" && !in_array($w, $ws))
                return false;
        return true;
    }
}
