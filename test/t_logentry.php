<?php
// t_logentry.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class LogEntry_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $u_chair;
    /** @var Contact */
    public $u_floyd;
    /** @var Contact */
    public $u_estrin;
    /** @var int */
    private $counted = 0;
    /** @var ?HashContext */
    private $idhash;

    const N = 1000;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        // delete all log entries, insert placeholders
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $conf->qe("delete from ActionLog");
        $conf->qe("alter table ActionLog AUTO_INCREMENT = 1");
        $conf->pause_log();
        for ($i = 1; $i <= self::N; $i += 4) {
            $this->add_log($this->u_chair, $this->u_chair, $i);
            $this->add_log($this->u_floyd, $this->u_floyd, $i + 1);
            $this->add_log($this->u_estrin, $this->u_estrin, $i + 2);
            $this->add_log($this->u_chair, $this->u_floyd, $i + 3);
        }
        $conf->resume_log();
    }

    private function add_log($user, $dst_user, $i) {
        $pid = $i % 6;
        $this->conf->log_for($user, $dst_user, "Entry {$i}", $pid <= 1 ? null : $pid);
    }

    private function check_log(LogEntry $le) {
        xassert_str_starts_with($le->action, "Entry ");
        xassert(ctype_digit(substr($le->action, 6)));
        $logid = (int) substr($le->action, 6);
        xassert_eqq($le->logId, $logid);
        $pid = $logid % 6;
        xassert_eqq($le->paperId, $pid <= 1 ? null : $pid);
        if ($logid % 4 === 1 || $logid % 4 === 0) {
            xassert_eqq($le->contactId, $this->u_chair->contactId);
        } else if ($logid % 4 === 2) {
            xassert_eqq($le->contactId, $this->u_floyd->contactId);
        } else {
            xassert_eqq($le->contactId, $this->u_estrin->contactId);
        }
        if ($logid % 4 === 0) {
            xassert_eqq($le->destContactId, $this->u_floyd->contactId);
        } else {
            xassert_eqq($le->destContactId, 0);
        }
    }

    private function check_log_page(LogEntryGenerator $leg, $pn) {
        $entries = $leg->page_rows($pn);
        if (!$leg->has_filter()) {
            $first = max(self::N - ($pn - 1) * $leg->page_size(), 0);
            $last = max($first - $leg->page_size() + 1, 1);
            xassert_eqq(count($entries), $first - $last + 1);
        }
        foreach ($entries as $i => $le) {
            if (!$leg->has_filter()) {
                xassert_eqq($le->logId, $first - $i);
            }
            $this->check_log($le);
            ++$this->counted;
            if ($this->idhash) {
                hash_update($this->idhash, (string) $le->logId);
            }
        }
    }

    function test_basic_by_50() {
        $this->counted = 0;
        $leg = new LogEntryGenerator($this->conf, 50);
        $this->check_log_page($leg, 1);
        $this->check_log_page($leg, 2);
        $this->check_log_page($leg, 3);
        $this->check_log_page($leg, 4);
        $this->check_log_page($leg, 5);
        $this->check_log_page($leg, 6);
        $this->check_log_page($leg, 7);
        xassert_eqq($this->counted, min(7 * 50, self::N));
    }

    function test_basic_by_49() {
        $this->counted = 0;
        $leg = new LogEntryGenerator($this->conf, 49);
        $this->check_log_page($leg, 1);
        $this->check_log_page($leg, 2);
        $this->check_log_page($leg, 3);
        $this->check_log_page($leg, 4);
        $this->check_log_page($leg, 5);
        $this->check_log_page($leg, 6);
        $this->check_log_page($leg, 7);
        xassert_eqq($this->counted, min(7 * 49, self::N));
    }

    function test_basic_by_200() {
        $this->counted = 0;
        $this->idhash = hash_init("sha256");
        $leg = new LogEntryGenerator($this->conf, 200);
        $this->check_log_page($leg, 1);
        $this->check_log_page($leg, 2);
        $this->check_log_page($leg, 3);
        $this->check_log_page($leg, 4);
        $this->check_log_page($leg, 5);
        $this->check_log_page($leg, 6);
        $this->check_log_page($leg, 7);
        xassert_eqq($this->counted, self::N);
        $hash1 = hash_final($this->idhash);

        $this->counted = 0;
        $this->idhash = hash_init("sha256");
        $leg = (new LogEntryGenerator($this->conf, 200))
            ->set_consolidate_mail(false);
        $this->check_log_page($leg, 1);
        $this->check_log_page($leg, 2);
        $this->check_log_page($leg, 3);
        $this->check_log_page($leg, 4);
        $this->check_log_page($leg, 5);
        $this->check_log_page($leg, 6);
        $this->check_log_page($leg, 7);
        xassert_eqq($this->counted, self::N);
        xassert_eqq($hash1, hash_final($this->idhash));

        $this->counted = 0;
        $this->idhash = null;
        $leg = (new LogEntryGenerator($this->conf, 50))
            ->set_consolidate_mail(false);
        $this->check_log_page($leg, 10);
        $this->check_log_page($leg, 4);
        xassert_eqq($this->counted, 100);
    }

    function test_basic_by_125_filtered() {
        $this->counted = 0;
        $leg = (new LogEntryGenerator($this->conf, 125))
            ->set_filter(new LogEntryFilter($this->u_chair, [4 => true], true, null));
        $this->check_log_page($leg, 1);
        $this->check_log_page($leg, 2);
        $this->check_log_page($leg, 3);
        $this->check_log_page($leg, 4);
        $this->check_log_page($leg, 5);
        $this->check_log_page($leg, 6);
        $this->check_log_page($leg, 7);
        xassert_gt($this->counted, (int) (self::N / 4));
        xassert_lt($this->counted, self::N);
        $counted = $this->counted;

        $this->counted = 0;
        $leg = (new LogEntryGenerator($this->conf, 3000))
            ->set_filter(new LogEntryFilter($this->u_chair, [4 => true], true, null));
        $this->check_log_page($leg, 1);
        xassert_eqq($this->counted, $counted);

        $this->counted = 0;
        $leg = (new LogEntryGenerator($this->conf, 3000))
            ->set_consolidate_mail(false)
            ->set_filter(new LogEntryFilter($this->u_chair, [4 => true], true, null));
        $this->check_log_page($leg, 1);
        xassert_eqq($this->counted, $counted);
    }

    function test_basic_by_125_first_page() {
        $this->counted = 0;
        $leg = (new LogEntryGenerator($this->conf, 125))
            ->set_filter(function ($x) { return $x->logId < self::N - 500; });
        $this->check_log_page($leg, 1);
        xassert_eqq($this->counted, 125);
    }

    function finalize() {
        $this->conf->qe("delete from ActionLog");
        $this->conf->qe("alter table ActionLog AUTO_INCREMENT = 1");
    }
}
