<?php
// topicset.php -- HotCRP helper class for topics
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TopicGroup {
    /** @var string */
    public $name;     // never contains a colon
    /** @var ?int */
    public $tid;      // if nonnull, its name equals the group name
    /** @var ?list<int> */
    public $members;  // if nonnull, contains all members

    /** @param string $name */
    function __construct($name) {
        $this->name = $name;
    }
    /** @return bool */
    function nontrivial() {
        return $this->members !== null && count($this->members) > 1;
    }
    /** @return bool */
    function improper() {
        return $this->tid !== null;
    }
    /** @return int */
    function size() {
        return $this->members !== null ? count($this->members) : 1;
    }
    /** @return list<int> */
    function members() {
        return $this->members ?? [$this->tid];
    }
    /** @return list<int> */
    function proper_members() {
        if ($this->members === null) {
            return [$this->tid];
        } else if ($this->tid !== $this->members[0]) {
            return $this->members;
        } else {
            return array_slice($this->members, 1);
        }
    }
}

class TopicSet implements ArrayAccess, IteratorAggregate, Countable {
    /** @var Conf */
    public $conf;
    /** @var array<int,string> */
    private $_topic_map = [];
    /** @var array<int,int> */
    private $_order = [];
    /** @var array<int,int> */
    private $_others_sfxlen = [];
    /** @var list<TopicGroup> */
    private $_group_list;
    /** @var array<int,TopicGroup> */
    private $_group_map;
    /** @var ?array<int,string> */
    private $_topic_html;
    /** @var ?AbbreviationMatcher<int> */
    private $_topic_abbrev_matcher;


    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param int $id
     * @param string $name */
    function __add($id, $name) {
        $this->_topic_map[$id] = $name;
        $this->_order[$id] = count($this->_order);

        // check for `None of the above`, `Others`, and `GROUP: (None of |Others)`
        $len = strlen($name);
        $colon = $pos = (int) strpos($name, ":");
        if ($colon !== 0) {
            $pos = $colon + 1;
            while ($pos !== $len && ctype_space($name[$pos])) {
                ++$pos;
            }
        }
        $ch = $pos !== $len ? ord($name[$pos]) | 0x20 : 0;
        if (($ch === 110 || $ch === 111)
            && preg_match('/\G(?:none of |others?(?: |\z))/i', $name, $m, 0, $pos)) {
            $this->_others_sfxlen[$id] = strlen($name) - ($colon !== 0 ? $colon + 1 : 0);
        }

        // XXX assert no abbrevmatcher, etc.
    }

    function sort_by_name() {
        if (empty($this->_topic_map)) {
            return;
        }

        $ids = array_keys($this->_topic_map);
        $collator = $this->conf->collator();
        usort($ids, function ($ida, $idb) use ($collator) {
            $na = $this->_topic_map[$ida];
            $nb = $this->_topic_map[$idb];

            // `none of the above`/`others` requires special handling
            $slena = $this->_others_sfxlen[$ida] ?? 0;
            $slenb = $this->_others_sfxlen[$idb] ?? 0;
            if ($slena !== 0 || $slenb !== 0) {
                $glena = strlen($na) - $slena;
                $glenb = strlen($nb) - $slenb;
                if (($glenb === 0 && $glena !== 0)
                    || ($slena === 0 && substr_compare($na, $nb, 0, $glenb, true) === 0)) {
                    return -1;
                } else if (($glena === 0 && $glenb !== 0)
                           || ($slenb === 0 && substr_compare($nb, $na, 0, $glena, true) === 0)) {
                    return 1;
                }
            }

            return $collator->compare($na, $nb);
        });

        $this->_order = array_combine($ids, range(0, count($ids) - 1));
        uksort($this->_topic_map, function ($ida, $idb) {
            return $this->_order[$ida] <=> $this->_order[$idb];
        });
    }

    /** @return TopicSet */
    static function make_main(Conf $conf) {
        $ts = new TopicSet($conf);
        $result = $conf->qe_raw("select topicId, topicName from TopicArea");
        while (($row = $result->fetch_row())) {
            $ts->__add((int) $row[0], $row[1]);
        }
        Dbl::free($result);
        $ts->sort_by_name();
        return $ts;
    }


    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->_topic_map);
    }
    /** @return array<int,string> */
    function as_array() {
        return $this->_topic_map;
    }
    #[\ReturnTypeWillChange]
    /** @return Iterator<int,string> */
    function getIterator() {
        return new ArrayIterator($this->_topic_map);
    }
    #[\ReturnTypeWillChange]
    function offsetExists($offset) {
        return isset($this->_topic_map[$offset]);
    }
    #[\ReturnTypeWillChange]
    function offsetGet($offset) {
        return $this->_topic_map[$offset];
    }
    #[\ReturnTypeWillChange]
    function offsetSet($offset, $value) {
        throw new Exception("invalid TopicSet::offsetSet");
    }
    #[\ReturnTypeWillChange]
    function offsetUnset($offset) {
        throw new Exception("invalid TopicSet::offsetUnset");
    }
    /** @param int $tid
     * @return ?string */
    function name($tid) {
        return $this->_topic_map[$tid] ?? null;
    }

    /** @return list<TopicGroup> */
    function group_list() {
        if ($this->_group_list === null) {
            $this->_group_list = $this->_group_map = [];
            $lastg = null;
            foreach ($this->_topic_map as $tid => $tname) {
                $colon = (int) strpos($tname, ":");
                $group = $colon ? substr($tname, 0, $colon) : $tname;
                if ($lastg !== null
                    && strcasecmp($lastg->name, $group) === 0) {
                    if ($lastg->members === null && $lastg->tid !== null) {
                        $lastg->members = [$lastg->tid];
                    }
                    $lastg->members[] = $tid;
                } else {
                    $lastg = new TopicGroup($group);
                    if ($colon === 0) {
                        $lastg->tid = $tid;
                    } else {
                        $lastg->members = [$tid];
                    }
                    $this->_group_list[] = $lastg;
                }
                $this->_group_map[$tid] = $lastg;
            }
        }
        return $this->_group_list;
    }

    /** @param list<int> &$ids */
    function sort(&$ids) {
        usort($ids, function ($a, $b) {
            return $this->_order[$a] - $this->_order[$b];
        });
    }
    /** @param array<int,mixed> &$by_id */
    function ksort(&$by_id) {
        uksort($by_id, function ($a, $b) {
            return $this->_order[$a] - $this->_order[$b];
        });
    }

    const MFLAG_TOPIC = 1;
    const MFLAG_GROUP = 2;
    const MFLAG_SPECIAL = 4;
    /** @return AbbreviationMatcher<int> */
    function abbrev_matcher() {
        if ($this->_topic_abbrev_matcher === null) {
            $am = $this->_topic_abbrev_matcher = new AbbreviationMatcher;
            foreach ($this->_topic_map as $tid => $tname) {
                $am->add_phrase($tname, $tid, self::MFLAG_TOPIC);
            }
            foreach ($this->group_list() as $tg) {
                if ($tg->size() > 1) {
                    foreach ($tg->members() as $tid) {
                        $am->add_phrase($tg->name, $tid, self::MFLAG_GROUP);
                    }
                }
            }
            $am->add_keyword("none", 0, self::MFLAG_SPECIAL);
            $am->add_keyword("any", -1, self::MFLAG_SPECIAL);
        }
        return $this->_topic_abbrev_matcher;
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<int> */
    function find_all($pattern, $tflags = 0) {
        if ($tflags === 0
            && ($colon = strpos($pattern, ":")) !== false) {
            $pword = ltrim(substr($pattern, $colon + 1));
            $any = strcasecmp($pword, "any") === 0;
            if ($any || strcasecmp($pword, "none") === 0) {
                $ts = $this->abbrev_matcher()->find_all(substr($pattern, 0, $colon), self::MFLAG_GROUP);
                if (!empty($ts)) {
                    if (!$any) {
                        array_unshift($ts, 0);
                    }
                    return $ts;
                }
            }
        }
        return $this->abbrev_matcher()->find_all($pattern, $tflags);
    }

    /** @param string $pattern
     * @param int $tflags
     * @return ?int */
    function find1($pattern, $tflags = 0) {
        return $this->abbrev_matcher()->find1($pattern, $tflags);
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<int> */
    function findp($pattern, $tflags = 0) {
        return $this->abbrev_matcher()->findp($pattern, $tflags);
    }

    /** @param 'long'|'medium'|'short' $lenclass
     * @param string $tname
     * @return 'long'|'medium'|'short' */
    static function max_topici_lenclass($lenclass, $tname) {
        if ($lenclass === "long"
            || (strlen($tname) > 50
                && UnicodeHelper::utf8_glyphlen($tname) > 50)) {
            return "long";
        } else if ($lenclass === "medium"
                   || (strlen($tname) > 20
                       && UnicodeHelper::utf8_glyphlen($tname) > 20)) {
            return "medium";
        } else {
            return "short";
        }
    }

    /** @param int $tid
     * @return string|false */
    function subtopic_name($tid) {
        $n = $this->_topic_map[$tid] ?? null;
        if ($n && ($colon = (int) strpos($n, ":")) !== 0) {
            return ltrim(substr($n, $colon + 1));
        } else {
            return false;
        }
    }

    /** @param int $tid
     * @return string */
    function unparse_name_html($tid) {
        if ($this->_topic_html === null) {
            $this->_topic_html = [];
            $this->group_list();
        }
        if (!isset($this->_topic_html[$tid])) {
            $tname = $this->_topic_map[$tid] ?? "";
            $tg = $this->_group_map[$tid];
            if ($tg->nontrivial()) {
                if ($tg->tid === $tid) {
                    $this->_topic_html[$tid] = '<span class="topicg">'
                        . htmlspecialchars($tg->name) . '</span>';
                } else {
                    $this->_topic_html[$tid] = '<span class="topicg">'
                        . htmlspecialchars($tg->name) . ':</span> '
                        . htmlspecialchars(ltrim(substr($tname, strlen($tg->name) + 1)));
                }
            } else {
                $this->_topic_html[$tid] = htmlspecialchars($tname);
            }
        }
        return $this->_topic_html[$tid];
    }

    /** @param int $tid
     * @return string */
    function unparse_subtopic_name_html($tid) {
        if ($this->_group_list === null) {
            $this->group_list();
        }
        $tg = $this->_group_map[$tid];
        if ($tg->tid === $tid) {
            return $this->unparse_name_html($tid);
        } else {
            $tname = $this->_topic_map[$tid] ?? "";
            return htmlspecialchars(ltrim(substr($tname, strlen($tg->name) + 1)));
        }
    }

    /** @param list<int> $tlist
     * @param ?array<int,int> $interests
     * @return string */
    function unparse_list_html($tlist, $interests = null) {
        $out = [];
        foreach ($tlist as $tid) {
            $n = $this->unparse_name_html($tid);
            if ($interests !== null) {
                $i = $interests[$tid] ?? 0;
                $out[] = "<li class=\"topic{$i}\">{$n}</li>";
            } else {
                $out[] = "<li>{$n}</li>";
            }
        }
        return "<ul class=\"semi\">" . join("", $out) . "</ul>";
    }
}
