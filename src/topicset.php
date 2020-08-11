<?php
// topicset.php -- HotCRP helper class for topics
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class TopicSet implements ArrayAccess, IteratorAggregate, Countable {
    /** @var Conf */
    public $conf;
    /** @var array<int,string> */
    private $_topic_map = [];
    /** @var array<int,int> */
    private $_order = [];
    private $_topic_groups;
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

    /** @return int */
    function count() {
        return count($this->_topic_map);
    }
    /** @return array<int,string> */
    function as_array() {
        return $this->_topic_map;
    }
    function getIterator() {
        return new ArrayIterator($this->_topic_map);
    }
    function offsetExists($offset) {
        return isset($this->_topic_map[$offset]);
    }
    function offsetGet($offset) {
        return $this->_topic_map[$offset];
    }
    /** @return ?string */
    function get($tid) {
        return $this->_topic_map[$tid] ?? null;
    }
    function offsetSet($offset, $value) {
        throw new Exception("invalid TopicSet::offsetSet");
    }
    function offsetUnset($offset) {
        throw new Exception("invalid TopicSet::offsetUnset");
    }

    function group_list() {
        if ($this->_topic_groups === null) {
            $this->_topic_groups = [];
            $last_gs = null;
            foreach ($this->_topic_map as $tid => $tname) {
                $colon = (int) strpos($tname, ":");
                $group = $colon ? substr($tname, 0, $colon) : $tname;
                if ($last_gs !== null
                    && strcasecmp($last_gs[0], $group) === 0) {
                    $last_gs[] = $tid;
                } else {
                    if ($last_gs !== null) {
                        $this->_topic_groups[] = $last_gs;
                    }
                    $last_gs = [$group, $tid];
                }
            }
            if ($last_gs !== null) {
                $this->_topic_groups[] = $last_gs;
            }
        }
        return $this->_topic_groups;
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
                for ($i = 1; count($tg) > 2 && $i !== count($tg); ++$i) {
                    $this->_topic_abbrev_matcher->add_phrase($tg[0], $tg[$i], self::MFLAG_GROUP);
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
        }
        if (!isset($this->_topic_html[$tid])) {
            $t = "";
            $tname = $this->_topic_map[$tid] ?? "";
            $colon = (int) strpos($tname, ":");
            if ($colon > 0) {
                foreach ($this->group_list() as $tg) {
                    if (count($tg) > 2 && in_array($tid, $tg, true)) {
                        $t = '<span class="topicg">' . htmlspecialchars($tg[0]) . ':</span> ';
                        $tname = ltrim(substr($tname, strlen($tg[0]) + 1));
                        break;
                    }
                }
            }
            $this->_topic_html[$tid] = $t . htmlspecialchars($tname);
        }
        return $this->_topic_html[$tid];
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
