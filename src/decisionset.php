<?php
// decisionset.php -- HotCRP helper class for set of decisions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class DecisionSet implements IteratorAggregate, Countable {
    /** @var Conf */
    public $conf;
    /** @var array<int,DecisionInfo> */
    private $_decision_map = [];
    /** @var ?AbbreviationMatcher */
    private $_decision_matcher;
    /** @var bool */
    private $_has_desk_reject = false;
    /** @var bool */
    private $_has_other = false;


    function __construct(Conf $conf, $j = null) {
        $this->conf = $conf;
        if (is_object($j)) {
            foreach ((array) $j as $decid => $dj) {
                if (is_numeric($decid) && (is_string($dj) || is_object($dj))) {
                    $this->__add(+$decid, $dj);
                }
            }
        } else if (is_array($j)) {
            foreach ($j as $i => $dj) {
                if ((is_object($dj) && is_int($dj->id ?? null)) || is_string($dj)) {
                    $this->__add($i, $dj);
                }
            }
        } else {
            $this->__add(1, "Accepted");
            $this->__add(-1, "Rejected");
        }
        $this->_decision_map[0] = $conf->unspecified_decision;

        $collator = $conf->collator();
        uasort($this->_decision_map, function ($da, $db) use ($collator) {
            if ($da->id === 0 || $db->id === 0) {
                return $da->id === 0 ? -1 : 1;
            } else if ($da->catbits !== $db->catbits) {
                return $da->catbits <=> $db->catbits;
            } else {
                return $collator->compare($da->name, $db->name);
            }
        });
        $order = 1;
        foreach ($this->_decision_map as $dinfo) {
            if ($dinfo->id !== 0) {
                $dinfo->order = $order;
                ++$order;
            }
        }
    }

    /** @param int $id
     * @param string|object $dj */
    function __add($id, $dj) {
        if (is_object($dj) && is_int($dj->id ?? null)) {
            $id = $dj->id;
        }
        if (is_object($dj)) {
            $dinfo = new DecisionInfo($id, $dj->name, $dj->category ?? null);
        } else {
            $dinfo = new DecisionInfo($id, $dj);
        }
        $this->_decision_map[$id] = $dinfo;

        if ($id !== 0 && ($dinfo->catbits & DecisionInfo::CAT_OTHER) !== 0) {
            $this->_has_other = true;
        } else if ($dinfo->catbits === DecisionInfo::CB_DESKREJECT) {
            $this->_has_desk_reject = true;
        }

        // XXX assert no abbrevmatcher, etc.
    }

    /** @param string $dname
     * @return string|false */
    static function name_error($dname) {
        $dname = simplify_whitespace($dname);
        if ((string) $dname === "") {
            return "Empty decision name";
        } else if (preg_match('/\A(?:yes|no|maybe|any|none|unknown|unspecified|undecided|\?)\z/i', $dname)) {
            return "Decision name “{$dname}” is reserved";
        } else {
            return false;
        }
    }

    /** @return DecisionSet */
    static function make_main(Conf $conf) {
        $j = json_decode($conf->setting_data("outcome_map") ?? "null");
        return new DecisionSet($conf, $j);
    }


    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->_decision_map);
    }
    /** @return array<int,DecisionInfo> */
    function as_array() {
        return $this->_decision_map;
    }
    #[\ReturnTypeWillChange]
    /** @return Iterator<int,DecisionInfo> */
    function getIterator() {
        return new ArrayIterator($this->_decision_map);
    }

    /** @param int $decid
     * @return bool */
    function contains($decid) {
        return isset($this->_decision_map[$decid]);
    }

    /** @param int $decid
     * @return DecisionInfo */
    function get($decid) {
        return $this->_decision_map[$decid] ?? DecisionInfo::make_placeholder($decid);
    }

    /** @return list<int> */
    function ids() {
        return array_keys($this->_decision_map);
    }

    /** @param string|list<int> $matchexpr
     * @return list<DecisionInfo> */
    function filter_using($matchexpr) {
        $a = [];
        foreach ($this->_decision_map as $dec) {
            if (CountMatcher::compare_using($dec->id, $matchexpr))
                $a[] = $dec;
        }
        return $a;
    }

    /** @return list<int> */
    function desk_reject_ids() {
        if (!$this->_has_desk_reject) {
            return [];
        }
        $decids = [];
        foreach ($this->_decision_map as $dec) {
            if ($dec->catbits === DecisionInfo::CB_DESKREJECT)
                $decids[] = $dec->id;
        }
        return $decids;
    }

    /** @param int $mask
     * @return list<int> */
    private function cat_ids($mask) {
        $decids = [];
        foreach ($this->_decision_map as $dec) {
            if (($dec->catbits & $mask) !== 0)
                $decids[] = $dec->id;
        }
        return $decids;
    }

    /** @return AbbreviationMatcher<int> */
    function abbrev_matcher() {
        if ($this->_decision_matcher === null) {
            $this->_decision_matcher = new AbbreviationMatcher;
            foreach ($this->_decision_map as $dinfo) {
                $this->_decision_matcher->add_phrase($dinfo->name, $dinfo->id);
            }
            foreach (["none", "unknown", "undecided"] as $dname) {
                $this->_decision_matcher->add_phrase($dname, 0);
            }
        }
        return $this->_decision_matcher;
    }

    /** @param string $pattern
     * @return list<int> */
    function find_all($pattern) {
        return $this->abbrev_matcher()->find_all($pattern);
    }

    /** @param string $word
     * @param bool $list
     * @return string|list<int> */
    function matchexpr($word, $list = false) {
        if (strcasecmp($word, "yes") === 0) {
            return $list ? $this->cat_ids(DecisionInfo::CAT_YES) : ">0";
        } else if (strcasecmp($word, "no") === 0) {
            return $list || $this->_has_other ? $this->cat_ids(DecisionInfo::CAT_NO) : "<0";
        } else if (strcasecmp($word, "maybe") === 0 || $word === "?") {
            return $list || $this->_has_other ? $this->cat_ids(DecisionInfo::CAT_OTHER) : "=0";
        } else if (strcasecmp($word, "active") === 0) {
            return $list || $this->_has_other ? $this->cat_ids(DecisionInfo::CAT_OTHER | DecisionInfo::CAT_YES) : ">=0";
        } else if (strcasecmp($word, "any") === 0) {
            return $list ? array_values(array_diff($this->ids(), [0])) : "!=0";
        } else {
            return $this->abbrev_matcher()->find_all($word);
        }
    }

    /** @param string $word
     * @return string */
    function sqlexpr($word) {
        $compar = $this->matchexpr($word);
        if (is_string($compar)) {
            return "outcome{$compar}";
        } else if (empty($compar)) {
            return "false";
        } else {
            return "outcome in (" . join(",", $compar) . ")";
        }
    }


    /** @return ?string */
    function unparse_database() {
        $x = [];
        foreach ($this->_decision_map as $dinfo) {
            if ($dinfo->id === 0) {
                continue;
            }
            $ecat = $dinfo->id > 0 ? DecisionInfo::CAT_YES : DecisionInfo::CAT_NO;
            if ($ecat === $dinfo->catbits) {
                $x[$dinfo->id] = $dinfo->name;
            } else {
                $x[$dinfo->id] = (object) ["name" => $dinfo->name, "category" => $dinfo->category_name()];
            }
        }
        return json_encode_db((object) $x);
    }
}
