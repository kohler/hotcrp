<?php
// o_topics.php -- HotCRP helper class for topics intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Topics_PaperOption extends CheckboxesBase_PaperOption {
    /** @var bool */
    private $_add_topics = false;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        if (!$this->conf->has_topics()) {
            $this->override_exists_condition(false);
        }
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->min_count > 1) {
            $j->min = $this->min_count;
        }
        if ($this->max_count > 0) {
            $j->max = $this->max_count;
        }
        return $j;
    }


    function topic_set() {
        return $this->conf->topic_set();
    }

    function interests($user) {
        return $user ? $user->topic_interest_map() : [];
    }

    /** @param bool $allow */
    function allow_new_topics($allow) {
        $this->_add_topics = $allow;
        $x = $allow || $this->conf->has_topics() ? null : false;
        $this->override_exists_condition($x);
    }

    function value_store_new_values(PaperValue $ov, PaperStatus $ps) {
        if (!$this->_add_topics) {
            return;
        }
        $vs = $ov->value_list();
        $newvs = $ov->anno("new_values");
        '@phan-var list<string> $newvs';
        $lctopics = $newids = [];
        foreach ($newvs as $tk) {
            if (in_array(strtolower($tk), $lctopics)) {
                continue;
            }
            $lctopics[] = strtolower($tk);
            $result = $ps->conf->qe("insert into TopicArea set topicName=?", $tk);
            $vs[] = $result->insert_id;
        }
        if (!$this->conf->has_topics()) {
            $this->conf->save_refresh_setting("has_topics", 1);
        }
        $this->conf->invalidate_topics();
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
        $ov->set_anno("bad_values", array_values(array_diff($ov->anno("bad_values"), $newvs)));
    }


    function value_force(PaperValue $ov) {
        if ($this->id === PaperOption::TOPICSID) {
            $vs = $ov->prow->topic_list();
            $ov->set_value_data($vs, array_fill(0, count($vs), null));
        }
    }

    function value_save(PaperValue $ov, PaperStatus $ps) {
        if ($this->id === PaperOption::TOPICSID) {
            $ps->change_at($this);
            $ov->prow->set_prop("topicIds", join(",", $ov->value_list()));
            return true;
        } else {
            return false;
        }
    }
}
