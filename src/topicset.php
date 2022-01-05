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
        $txs = [];
        $result = $conf->qe_raw("select topicId, topicName from TopicArea");
        while (($row = $result->fetch_row())) {
            $colon = (int) strpos($row[1], ":");
            $other = 0;
            if (preg_match('{\G\s*(?:none of |others?(?: |\z))}i', $row[1], $m, 0, $colon !== 0 ? $colon + 1 : 0)) {
                $other = $colon === 0 ? 2 : 1;
            }
            $txs[] = [(int) $row[0], $row[1], $other, substr($row[1], 0, $colon)];
        }
        Dbl::free($result);

        $collator = $conf->collator();
        usort($txs, function ($ta, $tb) use ($collator) {
            if ($ta[2] !== $tb[2]
                && ($ta[2] === 2
                    || $tb[2] === 2
                    || strcasecmp($ta[3], $tb[3]) === 0)) {
                return $ta[2] > $tb[2] ? 1 : -1;
            } else {
                return $collator->compare($ta[1], $tb[1]);
            }
        });

        $n = 0;
        foreach ($txs as $t) {
            $this->_topic_map[$t[0]] = $t[1];
            $this->_order[$t[0]] = $n;
            ++$n;
        }
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

    function sort(&$a) {
        usort($a, function ($a, $b) {
            return $this->_order[$a] - $this->_order[$b];
        });
    }
    function ksort(&$a) {
        uksort($a, function ($a, $b) {
            return $this->_order[$a] - $this->_order[$b];
        });
    }

    const MFLAG_TOPIC = 1;
    const MFLAG_GROUP = 2;
    /** @return AbbreviationMatcher<int> */
    function abbrev_matcher() {
        if ($this->_topic_abbrev_matcher === null) {
            $this->_topic_abbrev_matcher = new AbbreviationMatcher;
            foreach ($this->_topic_map as $tid => $tname) {
                $this->_topic_abbrev_matcher->add_phrase($tname, $tid, self::MFLAG_TOPIC);
            }
            foreach ($this->group_list() as $tg) {
                if ($tg->size() > 1) {
                    foreach ($tg->members() as $tid) {
                        $this->_topic_abbrev_matcher->add_phrase($tg->name, $tid, self::MFLAG_GROUP);
                    }
                }
            }
        }
        return $this->_topic_abbrev_matcher;
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
            $i = $interests === null ? null : ($interests[$tid] ?? 0);
            if (empty($out) && $i !== null) {
                $n = '<span class="topic' . $i . '">' . $n . '</span>';
                $i = null;
            }
            $out[] = '<li class="pl_topicti' . ($i !== null ? " topic$i" : "") . '">' . $n . '</li>';
        }
        return '<ul class="pl_topict">' . join("", $out) . '</ul>';
    }
}
