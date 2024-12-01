<?php
// o_topics.php -- HotCRP helper class for topics intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Topics_PaperOption extends CheckboxesBase_PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->refresh_topic_set();
    }

    function refresh_topic_set() {
        $ts = $this->topic_set();
        $empty = $ts->count() === 0 && !$ts->auto_add();
        $this->override_exists_condition($empty ? false : null);
    }

    function topic_set() {
        return $this->conf->topic_set();
    }

    function interests($user) {
        return $user ? $user->topic_interest_map() : [];
    }

    function value_force(PaperValue $ov) {
        if ($this->id === PaperOption::TOPICSID) {
            $vs = $ov->prow->topic_list();
            $ov->set_value_data($vs, array_fill(0, count($vs), null));
        }
    }

    private function _store_new_values(PaperValue $ov, PaperStatus $ps) {
        $this->topic_set()->commit_auto_add();
        $vs = $ov->value_list();
        $newvs = $ov->anno("new_values");
        '@phan-var list<string> $newvs';
        foreach ($newvs as $tk) {
            if (($tid = $this->topic_set()->find_exact($tk)) !== null) {
                $vs[] = $tid;
            }
        }
        $this->topic_set()->sort($vs); // to reduce unnecessary diffs
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
        $ov->set_anno("new_values", null);
    }

    function value_save(PaperValue $ov, PaperStatus $ps) {
        if (!$ov->anno("new_values")
            && $ov->equals($ov->prow->base_option($this->id))) {
            return true;
        }
        if ($ov->anno("new_values")) {
            if ($ps->save_status() < PaperStatus::SAVE_STATUS_PREPARED) {
                $ps->request_resave($this);
                $ps->change_at($this);
            } else {
                $this->_store_new_values($ov, $ps);
            }
        }
        $ps->change_at($this);
        if ($this->id === PaperOption::TOPICSID) {
            $ov->prow->set_prop("topicIds", join(",", $ov->value_list()));
        }
        return true;
    }
}
