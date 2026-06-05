<?php
// papertimes.php -- HotCRP script to approximate per-paper submission/approval times
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(PaperTimes_Batch::make_args($argv)->run());
}

class PaperTimes_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $accept_only;
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $iso;
    /** @var bool */
    public $save;
    /** @var ?string */
    public $query;
    /** @var string */
    public $type;

    /** @var array<int,list<int>> ts of non-final content modifications, by paper */
    private $paper_mods = [];
    /** @var array<int,int> earliest review/decision ts, by paper */
    private $first_review = [];
    /** @var array<int,array{int,string}> last real decision [ts, name], by paper */
    private $decision_set = [];
    /** @var array<int,list<array{int,int}>> "Sent mail #N" events [mailId, ts], by paper */
    private $sent_mail = [];
    /** @var list<int> ts of every au_seedec settings edit */
    private $seedec_edits = [];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->accept_only = isset($arg["accept-only"]);
        $this->verbose = isset($arg["verbose"]);
        $this->iso = isset($arg["iso"]);
        $this->save = isset($arg["save"]);
        $this->query = $arg["query"] ?? null;
        $this->type = $arg["type"] ?? "all";
    }

    /** @return array<int,bool> map of accept-class decision NAME (lowercased) => true */
    private function accept_decision_names() {
        $names = [];
        foreach ($this->conf->decision_set() as $dec) {
            if ($dec->category === DecisionInfo::CAT_YES) {
                $names[strtolower($dec->name)] = true;
            }
        }
        return $names;
    }

    /** Parse paperIds out of an ActionLog row: the paperId column plus any
     * "(papers 1, 2, 3)" / "(paper 1)" list embedded in the action text.
     * @return list<int> */
    private function row_paper_ids($row) {
        $pids = [];
        if (preg_match('/\A.* \(papers? ([\d, ]+)\)?\z/', $row->action, $m)) {
            foreach (preg_split('/[\s,]+/', $m[1]) as $p) {
                if ($p !== "") {
                    $pids[] = (int) $p;
                }
            }
        }
        if ((int) $row->paperId > 0) {
            $pids[] = (int) $row->paperId;
        }
        return array_values(array_unique($pids));
    }

    /** Single pass over the relevant ActionLog rows. */
    private function load_actionlog() {
        $result = $this->conf->qe("select paperId, timestamp, action from ActionLog
            where action like 'Paper %' or action like 'Review %'
               or action like 'Decision set:%' or action like 'Sent mail #%'
               or action like 'Settings edited:%au_seedec%'
            order by logId asc");
        while (($row = $result->fetch_object())) {
            $ts = (int) $row->timestamp;
            $action = $row->action;
            if (str_starts_with($action, "Paper ")) {
                // only submitted-mode content modifications count toward the
                // reviewable version: skip status removals and draft-mode saves
                // (log_save_activity appends " draft" when the paper isn't submitted)
                if (str_starts_with($action, "Paper withdrawn")
                    || str_starts_with($action, "Paper unsubmitted")
                    || str_contains($action, " draft")) {
                    continue;
                }
                $is_final = str_contains($action, " final");
                foreach ($this->row_paper_ids($row) as $pid) {
                    $this->paper_mods[$pid][] = $is_final ? -$ts : $ts;
                }
            } else if (str_starts_with($action, "Review ")) {
                foreach ($this->row_paper_ids($row) as $pid) {
                    if (!isset($this->first_review[$pid]) || $ts < $this->first_review[$pid]) {
                        $this->first_review[$pid] = $ts;
                    }
                }
            } else if (str_starts_with($action, "Decision set:")) {
                $name = trim(substr($action, strlen("Decision set:")));
                // strip any trailing "(papers ...)" list from the name
                if (($paren = strpos($name, " (paper")) !== false) {
                    $name = substr($name, 0, $paren);
                }
                foreach ($this->row_paper_ids($row) as $pid) {
                    if (!isset($this->first_review[$pid]) || $ts < $this->first_review[$pid]) {
                        $this->first_review[$pid] = $ts;
                    }
                    if (strcasecmp($name, "Unspecified") !== 0 && $name !== "") {
                        $this->decision_set[$pid] = [$ts, $name];
                    } else {
                        unset($this->decision_set[$pid]);
                    }
                }
            } else if (str_starts_with($action, "Sent mail #")) {
                if (preg_match('/\ASent mail #(\d+)/', $action, $m)) {
                    $mailid = (int) $m[1];
                    foreach ($this->row_paper_ids($row) as $pid) {
                        $this->sent_mail[$pid][] = [$mailid, $ts];
                    }
                }
            } else if (str_starts_with($action, "Settings edited:")
                       // match au_seedec as a setting name, not as part of some
                       // other setting's "name=value" text (the log includes values)
                       && preg_match('/(?:: |, )au_seedec(?![\w])/', $action)) {
                $this->seedec_edits[] = $ts;
            }
        }
        $result->close();
    }

    /** @return array<int,bool> map of mailId => true for accept-class notifications */
    private function accept_mail_ids() {
        $accept = $this->accept_decision_names();
        $ids = [];
        $result = $this->conf->qe("select mailId, t, recipients from MailLog");
        while (($row = $result->fetch_object())) {
            foreach ([$row->t, $row->recipients] as $sel) {
                if ($sel === null || !str_starts_with($sel, "dec:")) {
                    continue;
                }
                $name = substr($sel, 4);
                if ($name === "yes" || isset($accept[strtolower($name)])) {
                    $ids[(int) $row->mailId] = true;
                    break;
                }
            }
        }
        $result->close();
        return $ids;
    }

    /** Approximate when author decision visibility (au_seedec) was enabled.
     * The log records which setting changed, not its value, so this is a
     * heuristic: only meaningful if visibility is currently on.
     * @return ?int */
    private function visibility_enabled_at() {
        if (!$this->conf->setting("au_seedec")) {
            return null;
        }
        if (empty($this->seedec_edits)) {
            return null;
        }
        sort($this->seedec_edits);
        // earliest toggle at/after the first decision was set, else earliest toggle
        $first_dec = null;
        foreach ($this->decision_set as $info) {
            if ($first_dec === null || $info[0] < $first_dec) {
                $first_dec = $info[0];
            }
        }
        if ($first_dec !== null) {
            foreach ($this->seedec_edits as $ts) {
                if ($ts >= $first_dec) {
                    return $ts;
                }
            }
        }
        return $this->seedec_edits[0];
    }

    /** @param ?int $ts
     * @return string */
    private function fmt($ts) {
        if (!$ts) {
            return "";
        }
        return $this->iso ? $this->conf->unparse_time_iso8601($ts)
            : $this->conf->unparse_time_log($ts);
    }

    /** Last non-final modification at or before $cutoff (null = no cutoff).
     * @param list<int> $mods (final mods stored negated)
     * @param ?int $cutoff
     * @return ?int */
    private function last_mod_before($mods, $cutoff) {
        $best = null;
        foreach ($mods as $m) {
            if ($m <= 0) { // final-phase modification, excluded
                continue;
            }
            if ($cutoff !== null && $m > $cutoff) {
                continue;
            }
            if ($best === null || $m > $best) {
                $best = $m;
            }
        }
        return $best;
    }

    /** @return int */
    function run() {
        $this->load_actionlog();
        $accept_mailids = $this->accept_mail_ids();
        $vis_on = $this->visibility_enabled_at();
        $sub_sub = (int) $this->conf->setting("sub_sub");
        $accept_names = $this->accept_decision_names();

        if ($this->verbose) {
            fwrite(STDERR, "sub_sub deadline: " . ($sub_sub ? $this->fmt($sub_sub) : "(unset)") . "\n");
            fwrite(STDERR, "au_seedec currently: " . ($this->conf->setting("au_seedec") ? "on" : "off") . "\n");
            fwrite(STDERR, "au_seedec edits: " . (empty($this->seedec_edits) ? "(none)"
                : join(", ", array_map([$this, "fmt"], $this->seedec_edits))) . "\n");
            fwrite(STDERR, "visibility_enabled_at: " . ($vis_on ? $this->fmt($vis_on) : "(n/a)") . "\n");
            fwrite(STDERR, "accept-class mailIds: " . (empty($accept_mailids) ? "(none)"
                : join(", ", array_keys($accept_mailids))) . "\n");
        }

        // minimal output by default; the supporting analysis columns only with --verbose
        $cols = $this->verbose
            ? ["paper", "title", "decision",
               "submitted_deadline", "submitted_firstreview", "timeSubmittedReviewable",
               "decision_set_at", "visible_at", "accept_mail_at", "approval_best", "approval_src",
               "timeAcceptNotified"]
            : ["paper", "title", "timeSubmittedReviewable", "timeAcceptNotified"];
        $csv = (new CsvGenerator)->select($cols);

        $pset_opts = ["tags" => true];
        if ($this->query !== null || $this->type !== "all") {
            $search = new PaperSearch($this->conf->root_user(), ["q" => $this->query ?? "", "t" => $this->type]);
            $pids = $search->paper_ids();
            if (empty($pids)) {
                $csv->unparse_to_stream(STDOUT);
                return 0;
            }
            $pset_opts["paperId"] = $pids;
        }

        $n_reviewable = $n_notified = 0;
        foreach ($this->conf->paper_set($pset_opts) as $prow) {
            $pid = $prow->paperId;
            $outcome = $prow->outcome;
            $decname = $outcome ? $this->conf->decision_name($outcome) : "";
            $is_accept = $outcome && isset($accept_names[strtolower($decname)]);
            if ($this->accept_only && !$is_accept) {
                continue;
            }

            $mods = $this->paper_mods[$pid] ?? [];
            // the submission deadline is per submission round (the global sub_sub
            // is only the unnamed round's deadline)
            $deadline = $prow->submission_round()->submit;
            $submitted_deadline = $deadline > 0 ? $this->last_mod_before($mods, $deadline) : null;
            $submitted_firstreview = $this->last_mod_before($mods, $this->first_review[$pid] ?? null);

            $decision_set_at = isset($this->decision_set[$pid]) ? $this->decision_set[$pid][0] : null;
            $visible_at = null;
            if ($decision_set_at !== null) {
                if ($outcome && $this->conf->decision_set()->get($outcome)->sign === -2) {
                    // desk rejects are author-visible as soon as set, regardless of au_seedec
                    $visible_at = $decision_set_at;
                } else if ($vis_on !== null) {
                    $visible_at = max($decision_set_at, $vis_on);
                }
            }

            $accept_mail_at = null;
            foreach ($this->sent_mail[$pid] ?? [] as $ev) {
                if (isset($accept_mailids[$ev[0]])
                    && ($accept_mail_at === null || $ev[1] < $accept_mail_at)) {
                    $accept_mail_at = $ev[1];
                }
            }

            if ($accept_mail_at !== null) {
                $approval_best = $accept_mail_at;
                $approval_src = "mail";
            } else if ($visible_at !== null) {
                $approval_best = $visible_at;
                $approval_src = "decision+visibility";
            } else {
                $approval_best = null;
                $approval_src = "unknown";
            }

            // approximations of the live Paper columns: timeSubmittedReviewable is
            // the last submitted-mode author modification before reviewing began
            // (first-review method); timeAcceptNotified is the acceptance-mail time.
            $calc_reviewable = $submitted_firstreview;
            $calc_notified = $accept_mail_at;

            // the field columns report the value the running system has already
            // stored if there is one, otherwise the log approximation
            $cur_reviewable = (int) $prow->timeSubmittedReviewable;
            $cur_notified = (int) $prow->timeAcceptNotified;
            $show_reviewable = $cur_reviewable > 0 ? $cur_reviewable : $calc_reviewable;
            $show_notified = $cur_notified > 0 ? $cur_notified : $calc_notified;

            $csv->add_row([
                "paper" => $pid,
                "title" => $prow->title,
                "decision" => $decname,
                "submitted_deadline" => $this->fmt($submitted_deadline),
                "submitted_firstreview" => $this->fmt($submitted_firstreview),
                "timeSubmittedReviewable" => $this->fmt($show_reviewable),
                "decision_set_at" => $this->fmt($decision_set_at),
                "visible_at" => $this->fmt($visible_at),
                "accept_mail_at" => $this->fmt($accept_mail_at),
                "approval_best" => $this->fmt($approval_best),
                "approval_src" => $approval_src,
                "timeAcceptNotified" => $this->fmt($show_notified)
            ]);

            // backfill the live columns, but only where currently unset so we
            // never clobber values the running system has already recorded
            if ($this->save) {
                if ($calc_reviewable !== null && $cur_reviewable === 0) {
                    $r = $this->conf->qe("update Paper set timeSubmittedReviewable=? where paperId=? and timeSubmittedReviewable=0", $calc_reviewable, $pid);
                    $n_reviewable += $r->affected_rows;
                }
                if ($calc_notified !== null && $cur_notified === 0) {
                    $r = $this->conf->qe("update Paper set timeAcceptNotified=? where paperId=? and timeAcceptNotified=0", $calc_notified, $pid);
                    $n_notified += $r->affected_rows;
                }
            }
        }

        $csv->unparse_to_stream(STDOUT);
        if ($this->save) {
            fwrite(STDERR, "saved timeSubmittedReviewable for {$n_reviewable} paper(s), timeAcceptNotified for {$n_notified} paper(s)\n");
        }
        return 0;
    }

    /** @return PaperTimes_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "query:,q: =SEARCH Restrict to papers matching this search.",
            "type:,t: =TYPE Search collection type [all].",
            "accept-only Only output accept-class papers.",
            "iso Format dates as ISO 8601 instead of human-readable log time.",
            "save Backfill Paper.timeSubmittedReviewable and Paper.timeAcceptNotified (only where currently 0).",
            "verbose,V Output the supporting analysis columns and print the au_seedec timeline to stderr.",
            "help,h !"
        )->description("Approximate per-paper submission and approval times by scraping the log.

By default the output has just paper, title, timeSubmittedReviewable, and
timeAcceptNotified. Each reports the value already stored in the Paper table if
it is set, otherwise a log approximation: timeSubmittedReviewable approximates
to the last submitted-mode content modification before the paper's first
review/decision (draft-mode saves are ignored), and timeAcceptNotified to the
acceptance-mail time.

With --verbose the output also includes the supporting analysis columns
(decision; submitted_deadline and submitted_firstreview; and decision_set_at,
visible_at, accept_mail_at, approval_best, approval_src), and the au_seedec
timeline and accept-mail ids are printed to stderr.

With --save the two approximations are written into the live columns, but only
for papers where the column is currently 0, so values the running system has
already recorded are never overwritten. All values are approximations from
ActionLog and MailLog; pre-logging or SQL-imported data may be missing.

Usage: php batch/papertimes.php [-q SEARCH] [-t TYPE] [--accept-only] [--iso] [--save] [--verbose]")
         ->maxarg(0)
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new PaperTimes_Batch($conf, $arg);
    }
}
