<?php
// pages/p_log.php -- HotCRP action log page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Log_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var int */
    public $nlinks;
    /** @var null|false|int */
    public $first_timestamp = false;
    /** @var array<int,string> */
    public $user_html = [];
    /** @var ?array<int,mixed> */
    private $include_pids;
    /** @var ?array<int,mixed> */
    private $exclude_pids;
    /** @var bool */
    private $unix_timestamp;
    /** @var string */
    private $document_regexp;
    /** @var MessageSet */
    private $ms;

    /** @param int $count */
    function __construct(Contact $viewer, Qrequest $qreq, $count) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->ms = new MessageSet;
        $x = [];
        foreach ($this->conf->options()->form_fields() as $opt) {
            if ($opt->is_document())
                $x[] = $opt->json_key();
        }
        $this->document_regexp = join("|", $x);
        $this->unix_timestamp = $qreq->time === "u";
        $this->nlinks = $count <= 0 ? 6 : max(3, min(6, (int) ceil(1000 / $count)));
    }


    private function parse_papers(LogEntryGenerator $leg) {
        $query = $this->qreq->p ?? "";
        if (trim($query) === "") {
            return;
        }
        $search = new PaperSearch($this->viewer, ["t" => "all", "q" => $query]);
        $search->set_allow_deleted(true);
        $pids = $search->paper_ids();
        if ($search->has_problem()) {
            foreach ($search->message_list() as $mi) {
                $this->ms->append_item_at("p", $mi);
            }
        } else if (empty($pids)) {
            $this->ms->warning_at("p", "<0>No papers match that search");
        }
        if (!empty($pids)) {
            $this->include_pids = array_flip($pids);
        }
        $leg->set_paper_ids($pids);
    }

    private function add_user_clause(LogEntryGenerator $leg) {
        $query = $this->qreq->u ?? "";
        if (trim($query) === "") {
            return;
        }
        $ids = [];
        $accts = new SearchParser($query);
        while (($word = $accts->shift_balanced_parens()) !== "") {
            $flags = ContactSearch::F_TAG | ContactSearch::F_USER | ContactSearch::F_ALLOW_DELETED;
            if (substr($word, 0, 1) === "\"") {
                $flags |= ContactSearch::F_QUOTED;
                $word = preg_replace('/(?:\A"|"\z)/', "", $word);
            }
            $search = new ContactSearch($flags, $word, $this->viewer);
            foreach ($search->user_ids() as $id) {
                $ids[$id] = true;
            }
        }
        if (empty($ids)) {
            $this->ms->warning_at("u", "<0>User not found");

        }
        ksort($ids);
        $leg->set_user_ids(array_keys($ids));
    }

    private function add_action_clause(LogEntryGenerator $leg) {
        $w = [];
        $str = $this->qreq->q;
        while (($str = ltrim($str)) !== "") {
            if ($str[0] === '"') {
                preg_match('/\A"([^"]*)"?/', $str, $m);
            } else {
                preg_match('/\A([^"\s]+)/', $str, $m);
            }
            $str = (string) substr($str, strlen($m[0]));
            if ($m[1] !== "") {
                $w[] = $m[1];
            }
        }
        $leg->set_action_matchers($w);
    }

    private function set_date() {
        $this->first_timestamp = $this->conf->parse_time($this->qreq->date);
        if ($this->first_timestamp === false) {
            $this->ms->error_at("date", "<0>Invalid date. Try format “YYYY-MM-DD HH:MM:SS”.");
        }
    }


    /** @param int $count
     * @return LogEntryGenerator */
    private function make_generator($count) {
        $leg = new LogEntryGenerator($this->conf, $count);

        $this->exclude_pids = $this->viewer->hidden_papers ? : [];
        if ($this->viewer->privChair && $this->conf->has_any_manager()) {
            foreach ($this->viewer->paper_set(["myConflicts" => true]) as $prow) {
                if (!$this->viewer->allow_administer($prow)) {
                    $this->exclude_pids[$prow->paperId] = true;
                }
            }
        }

        if (!$this->viewer->privChair) {
            $good_pids = [];
            foreach ($this->viewer->paper_set($this->conf->check_any_admin_tracks($this->viewer) ? [] : ["myManaged" => true]) as $prow) {
                if ($this->viewer->allow_administer($prow)) {
                    $good_pids[$prow->paperId] = true;
                }
            }
            $leg->set_filter(new LogEntryFilter($this->viewer, $good_pids, true, $this->include_pids));
        } else if (!$this->qreq->forceShow && !empty($this->exclude_pids)) {
            $leg->set_filter(new LogEntryFilter($this->viewer, $this->exclude_pids, false, $this->include_pids));
        }

        return $leg;
    }

    /** @param LogEntryGenerator $leg
     * @param ?int $page
     * @return int */
    function choose_page($leg, $page) {
        if ($this->first_timestamp) {
            $page = 1;
            while (true) {
                $delta = $n = 0;
                foreach ($leg->page_rows($page, true) as $row) {
                    ++$n;
                    if ($row->timestamp > $this->first_timestamp)
                        ++$delta;
                }
                if ($n === 0) {
                    return max($page - 1, 1);
                } else if ($delta !== $n) {
                    $leg->set_page_delta($delta);
                    return $delta ? $page + 1 : $page;
                }
                ++$page;
            }
        } else if (!$page) { // handle `earliest`
            $page = 1;
            while ($leg->has_page($page + 1)) {
                ++$page;
            }
        } else if ($this->qreq->offset
                   && ($delta = stoi($this->qreq->offset) ?? -1) >= 0
                   && $delta < $leg->page_size()) {
            $leg->set_page_delta($delta);
        }
        return $page;
    }


    /** @param LogEntryGenerator $leg */
    function handle_download($leg) {
        $this->qreq->qsession()->commit();
        $leg->set_consolidate_mail(false);

        $csvg = $this->conf->make_csvg("log")->set_emit_live(true);
        $csvg->select($leg->narrow_csv_fields());
        set_time_limit(300); // might take a while

        for ($page = 1; ($rows = $leg->page_rows($page)); ++$page) {
            foreach ($rows as $row) {
                $csvg->append($leg->narrow_csv_data_list($row));
            }
        }

        $csvg->emit();
        exit(0);
    }


    // render search list
    /** @param int $page */
    function print_searchbar(LogEntryGenerator $leg, $page) {
        $date = "";
        $dplaceholder = null;
        if ($this->ms->problem_status_at("date")) {
            $date = $this->qreq->date;
        } else if ($page === 1) {
            $dplaceholder = "now";
        } else if (($rows = $leg->page_rows($page))) {
            $dplaceholder = $this->conf->unparse_time_log($rows[0]->timestamp);
        } else if ($this->first_timestamp) {
            $dplaceholder = $this->conf->unparse_time_log($this->first_timestamp);
        }

        echo Ht::form($this->conf->hoturl("log"), ["method" => "get", "id" => "f-search", "class" => "mx-auto clearfix"]);
        if ($this->qreq->forceShow) {
            echo Ht::hidden("forceShow", 1);
        }
        echo '<div class="d-inline-block" style="padding-right:2rem">',
            '<div class="', $this->ms->control_class("q", "entryi medium"),
            '"><label for="q">Concerning action(s)</label><div class="entry">',
            $this->ms->feedback_html_at("q"),
            Ht::entry("q", $this->qreq->q, ["id" => "q", "size" => 40]),
            '</div></div><div class="', $this->ms->control_class("p", "entryi medium"),
            '"><label for="p">Concerning paper(s)</label><div class="entry">',
            $this->ms->feedback_html_at("p"),
            Ht::entry("p", $this->qreq->p, ["id" => "p", "class" => "need-suggest papersearch", "size" => 40, "spellcheck" => false]),
            '</div></div><div class="', $this->ms->control_class("u", "entryi medium"),
            '"><label for="u">Concerning user(s)</label><div class="entry">',
            $this->ms->feedback_html_at("u"),
            Ht::entry("u", $this->qreq->u, ["id" => "u", "size" => 40]),
            '</div></div><div class="', $this->ms->control_class("n", "entryi medium"),
            '"><label for="n">Show</label><div class="entry">',
            Ht::entry("n", $this->ms->has_message_at("n") ? $this->qreq->n : ($leg->page_size() === 50 ? "" : $leg->page_size()), ["id" => "n", "size" => 4, "placeholder" => 50]),
            ' records at a time',
            $this->ms->feedback_html_at("n"),
            '</div></div><div class="', $this->ms->control_class("date", "entryi medium"),
            '"><label for="date">Starting at</label><div class="entry">',
            $this->ms->feedback_html_at("date"),
            Ht::entry("date", $date, ["id" => "date", "size" => 40, "placeholder" => $dplaceholder]),
            '</div></div></div>',
            Ht::submit("Show"),
            Ht::submit("download", "Download", ["class" => "ml-3"]),
            '</form>';

        if ($page > 1 || $leg->has_page(2)) {
            $urls = ["q=" . urlencode($this->qreq->q)];
            foreach (["p", "u", "n", "forceShow"] as $x) {
                if ($this->qreq[$x])
                    $urls[] = "{$x}=" . urlencode($this->qreq[$x]);
            }
            $leg->set_log_url_base($this->conf->hoturl("log", join("&amp;", $urls)));
            echo "<table class=\"lognav\"><tr><td><div class=\"lognavdr\">";
            if ($page > 1) {
                echo $leg->page_link_html(1, "<strong>Newest</strong>"), " &nbsp;|&nbsp;&nbsp;";
            }
            echo "</div></td><td><div class=\"lognavxr\">";
            if ($page > 1) {
                echo $leg->page_link_html($page - 1, "<strong>" . Icons::ui_linkarrow(3) . "Newer</strong>");
            }
            echo "</div></td><td><div class=\"lognavdr\">";
            if ($page - $this->nlinks > 1) {
                echo "&nbsp;…";
            }
            for ($p = max($page - $this->nlinks, 1); $p < $page; ++$p) {
                echo "&nbsp;", $leg->page_link_html($p, $p);
            }
            echo "</div></td><td><div><strong class=\"thispage\">&nbsp;", $page, "&nbsp;</strong></div></td><td><div class=\"lognavd\">";
            for ($p = $page + 1; $p <= $page + $this->nlinks && $leg->has_page($p); ++$p) {
                echo $leg->page_link_html($p, $p), "&nbsp;";
            }
            if ($leg->has_page($page + $this->nlinks + 1)) {
                echo "…&nbsp;";
            }
            echo "</div></td><td><div class=\"lognavx\">";
            if ($leg->has_page($page + 1)) {
                echo $leg->page_link_html($page + 1, "<strong>Older" . Icons::ui_linkarrow(1) . "</strong>");
            }
            echo "</div></td><td><div class=\"lognavd\">";
            if ($leg->has_page($page + $this->nlinks + 1)) {
                echo "&nbsp;&nbsp;|&nbsp; ", $leg->page_link_html("earliest", "<strong>Oldest</strong>");
            }
            echo "</div></td></tr></table>";
        }
        echo "<hr class=\"g\">\n";
    }

    /** @param Contact $user */
    function user_html($user) {
        if (($pc = $this->conf->pc_member_by_id($user->contactId))) {
            $user = $pc;
        }
        if ($user->disabled_flags() & Contact::CF_DELETED) {
            $t = '<del>' . $user->name_h(NAME_E) . '</del>';
        } else {
            $t = $user->name_h(NAME_P);
        }
        $dt = null;
        if (($viewable = $user->viewable_tags($this->viewer))) {
            $dt = $this->conf->tags();
            if (($colors = $dt->color_classes($viewable))) {
                $t = "<span class=\"{$colors} taghh\">{$t}</span>";
            }
        }
        $url = $this->conf->hoturl("log", ["q" => "", "u" => $user->email, "n" => $this->qreq->n]);
        $t = "<a href=\"{$url}\">{$t}</a>";
        if ($dt && $dt->has(TagInfo::TFM_DECORATION)) {
            $tagger = new Tagger($this->viewer);
            $t .= $tagger->unparse_decoration_html($viewable, Tagger::DECOR_USER);
        }
        $roles = 0;
        if (isset($user->roles) && ($user->roles & Contact::ROLE_PCLIKE)) {
            $roles = $user->viewable_pc_roles($this->viewer);
        }
        if (!($roles & Contact::ROLE_PCLIKE)) {
            $t .= ' &lt;' . htmlspecialchars($user->email) . '&gt;';
        }
        if ($roles !== 0 && ($rolet = Contact::role_html_for($roles))) {
            $t .= " $rolet";
        }
        return $t;
    }

    /** @param list<Contact> $users
     * @param int $trueContactId
     * @return string */
    function users_html($users, $trueContactId) {
        if ($trueContactId === -1 && empty($users)) {
            return "<i>via link</i>";
        }
        $all_pc = true;
        $ts = [];
        $last_user = null;
        usort($users, $this->conf->user_comparator());
        foreach ($users as $user) {
            if ($user === $last_user) {
                continue;
            }
            if ($all_pc
                && (($user->roles ?? 0) & Contact::ROLE_PCLIKE) === 0) {
                $all_pc = false;
            }
            if ($user->disabled_flags() & Contact::CF_DELETED) {
                if ($user->email) {
                    $t = '<del>' . $user->name_h(NAME_E) . '</del>';
                } else {
                    $t = '<del>[deleted user ' . $user->contactId . ']</del>';
                }
            } else {
                $t = $this->user_html[$user->contactId] ?? null;
                if ($t === null) {
                    $t = $this->user_html[$user->contactId] = $this->user_html($user);
                }
                if ($trueContactId !== 0) {
                    if ($trueContactId > 0) {
                        $t .= ' <i>via admin</i>';
                    } else if ($trueContactId === -1) {
                        $t .= ' <i>via link</i>';
                    } else if ($trueContactId === -2) {
                        $t .= ' <i>via API token</i>';
                    } else if ($trueContactId === -3) {
                        $t .= ' <i>via command line</i>';
                    }
                }
            }
            $ts[] = $t;
            $last_user = $user;
        }
        if (count($ts) <= 3) {
            return join(", ", $ts);
        } else {
            $fmt = $all_pc ? "{} PC users" : "{} users";
            return '<div class="has-fold foldc"><button type="button" class="q ui js-foldup">'
                . expander(null, 0)
                . '</button>'
                . '<span class="fn"><button type="button" class="q ui js-foldup">'
                . $this->conf->_($fmt, count($ts))
                . '</button></span><span class="fx">' . join(", ", $ts)
                . '</span></div>';
        }
    }

    /** @param LogEntryGenerator $leg
     * @param int $page */
    function print_page($leg, $page) {
        $conf = $this->conf;
        $this->qreq->print_header("Log", "actionlog");
        file_put_contents("/tmp/x.txt", "");

        $leg->load_row_range($leg->page_index($page),
                             $leg->page_index($page + $this->nlinks + 1) + 1);
        $trs = $leg->page_rows($page);

        if (!$this->viewer->privChair || !empty($this->exclude_pids)) {
            echo '<div class="msgs-wide">';
            if (!$this->viewer->privChair) {
                $conf->feedback_msg(MessageItem::marked_note("<0>Only showing your actions, plus entries for papers you administer"));
            } else if (!empty($this->exclude_pids)
                       && (!$this->include_pids || array_intersect_key($this->include_pids, $this->exclude_pids))
                       && array_keys($this->exclude_pids) != array_keys($this->viewer->hidden_papers ? : [])) {
                $req = [];
                foreach (["q", "p", "u", "n"] as $k) {
                    if ($this->qreq->$k !== "")
                        $req[$k] = $this->qreq->$k;
                }
                $req["page"] = $page;
                if ($page > 1 && $leg->page_delta() > 0) {
                    $req["offset"] = $leg->page_delta();
                }
                if ($this->qreq->forceShow) { // XXX never true
                    $conf->feedback_msg(MessageItem::marked_note("<5>Showing all entries (" . Ht::link("unprivileged view", $conf->selfurl($this->qreq, $req + ["forceShow" => null])) . ")"));
                } else {
                    $conf->feedback_msg(MessageItem::marked_note("<5>Not showing entries for " . Ht::link("conflicted administered papers", $conf->hoturl("search", "q=" . join("+", array_keys($this->exclude_pids))))));
                }
            }
            echo '</div>';
        }

        $this->print_searchbar($leg, $page);

        if (!empty($trs)) {
            echo "<table class=\"pltable fullw pltable-log\">\n",
                '  <thead><tr class="pl_headrow">',
                '<th class="pl plh pl_logtime">Time</th>',
                '<th class="pl plh pl_logname">User</th>',
                '<th class="pl plh pl_logname">Affected user</th>',
                '<th class="pl plh pl_logaction">Action</th></tr></thead>',
                "\n  <tbody class=\"pltable\">\n";
            foreach ($trs as $n => $row) {
                $this->print_entry($row, $n, $leg);
            }
            echo "  </tbody>\n</table>\n";
        } else {
            echo "No records\n";
        }

        $this->qreq->print_footer();
    }

    /** @param LogEntry $row
     * @param int $n
     * @param LogEntryGenerator $leg */
    private function print_entry($row, $n, $leg) {
        $conf = $this->conf;
        echo '<tr class="plnx k', $n % 2, '">';

        // timestamp
        if ($this->unix_timestamp) {
            $time = "@{$row->timestamp}";
        } else {
            $time = $conf->unparse_time_log($row->timestamp);
        }
        echo '<td class="pl pl_logtime">', $time, '</td>';

        // users
        $xusers = $leg->users_for($row, "contactId");
        $xusers_html = $this->users_html($xusers, $row->trueContactId);
        $xdest_users = $leg->users_for($row, "destContactId");

        if ($xdest_users && $xusers != $xdest_users) {
            $xdestusers_html = $this->users_html($xdest_users, 0);
            echo '<td class="pl pl_logname">', $xusers_html, '</td>',
                '<td class="pl pl_logname">', $xdestusers_html, '</td>';
        } else {
            echo '<td class="pl pl_logname" colspan="2">', $xusers_html, '</td>';
        }

        // XXX users that aren't in contactId slot
        // if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
        //     $t .= htmlspecialchars($m[2]);
        //     $act = $m[1] . $m[3];
        // } else
        //     $t .= "[None]";

        // action
        $act = $leg->cleaned_action($row);
        $at = "";
        if (strpos($act, "eview ") !== false
            && preg_match('/\A(.* |)([Rr]eview )(\d+)( .*|)\z/', $act, $m)) {
            $at = htmlspecialchars($m[1])
                . Ht::link($m[2] . $m[3], $conf->hoturl("review", ["p" => $row->paperId, "r" => $m[3]]))
                . "</a>";
            $act = $m[4];
        } else if (substr($act, 0, 7) === "Comment"
                   && preg_match('/\AComment (\d+)(.*)\z/s', $act, $m)) {
            $at = "<a href=\"" . $conf->hoturl("paper", "p={$row->paperId}#cx{$m[1]}") . "\">Comment " . $m[1] . "</a>";
            $act = $m[2];
        } else if (substr($act, 0, 8) === "Response"
                   && preg_match('/\AResponse (\d+)(.*)\z/s', $act, $m)) {
            $at = "<a href=\"" . $conf->hoturl("paper", "p={$row->paperId}#cx{$m[1]}") . "\">Response " . $m[1] . "</a>";
            $act = $m[2];
        } else if (strpos($act, " mail ") !== false
                   && preg_match('/\A(Sending|Sent|Account was sent) mail #(\d+)(.*)\z/s', $act, $m)) {
            $at = $m[1] . " <a href=\"" . $conf->hoturl("mail", "mailid=$m[2]") . "\">mail #$m[2]</a>";
            $act = $m[3];
        } else if (substr($act, 0, 3) === "Tag"
                   && preg_match('/\ATag:? ((?:[-+]\#[^\s\#]*(?:\#[-+\d.]+|)(?: |\z))+)(.*)\z/s', $act, $m)) {
            $at = "Tag";
            $act = $m[2];
            foreach (explode(" ", rtrim($m[1])) as $word) {
                if (($hash = strpos($word, "#", 2)) === false) {
                    $hash = strlen($word);
                }
                $at .= " " . $word[0] . '<a href="'
                    . $conf->hoturl("search", ["q" => substr($word, 1, $hash - 1)])
                    . '">' . htmlspecialchars(substr($word, 1, $hash - 1))
                    . '</a>' . substr($word, $hash);
            }
        } else if ($row->paperId > 0
                   && str_starts_with($act, "Paper ")
                   && ($colon = strpos($act, ":")) !== false
                   && $this->document_regexp !== "") {
            $at = substr($act, 0, $colon);
            $act = substr($act, $colon);
            while (preg_match("/\\A(.*? )({$this->document_regexp})((?:,| |\\z).*)\\z/", $act, $m)) {
                $at .= htmlspecialchars($m[1])
                    . "<a href=\"" . $conf->hoturl("doc", ["p" => $row->paperId, "dt" => $m[2], "at" => $row->timestamp])
                    . "\">{$m[2]}</a>";
                $act = $m[3];
            }
        }
        $at .= htmlspecialchars($act);
        if (($pids = $leg->paper_ids($row))) {
            if (count($pids) === 1)
                $at .= ' (<a class="track" href="' . $conf->hoturl("paper", "p=" . $pids[0]) . '">paper ' . $pids[0] . "</a>)";
            else {
                $at .= ' (<a href="' . $conf->hoturl("search", "t=all&amp;q=" . join("+", $pids)) . '">papers</a>';
                foreach ($pids as $i => $p) {
                    $at .= ($i ? ', ' : ' ') . '<a class="track" href="' . $conf->hoturl("paper", "p=" . $p) . '">' . $p . '</a>';
                }
                $at .= ')';
            }
        }

        echo '<td class="pl pl_logaction">', $at, '</td></tr>';
    }

    static function go(Contact $viewer, Qrequest $qreq) {
        if (!$viewer->is_manager()) {
            $viewer->escape();
        }

        // clean request
        unset($qreq->forceShow, $_GET["forceShow"], $_POST["forceShow"]);

        if ($qreq->page === "earliest") { // NB `page` URL PARAMETER
            $page = null;
        } else {
            $page = max(stoi($qreq->page) ?? -1, 1);
        }

        $count = 50;
        if (isset($qreq->n) && trim($qreq->n) !== "") {
            $count = stoi($qreq->n) ?? -1;
        }
        $bad_count = $count <= 0;
        $count = $bad_count ? 50 : min($count, 300);

        $qreq->q = trim((string) $qreq->q);
        $qreq->p = trim((string) $qreq->p);
        if (isset($qreq->acct) && !isset($qreq->u)) {
            $qreq->u = $qreq->acct;
        }
        $qreq->u = trim((string) $qreq->u);
        $qreq->date = trim((string) $qreq->date);
        if (trim($qreq->date) === "") {
            $qreq->date = "now";
        }

        // parse filter parts
        $lp = new Log_Page($viewer, $qreq, $count);
        if ($bad_count) {
            $lp->ms->error_at("n", "<0>Expected a number greater than 0");
        }

        // create entry generator
        $leg = $lp->make_generator($qreq->download ? 10000 : $count);
        if ($qreq->p !== "") {
            $lp->parse_papers($leg);
        }
        if ($qreq->u !== "") {
            $lp->add_user_clause($leg);
        }
        if ($qreq->q !== "") {
            $lp->add_action_clause($leg);
        }

        if ($qreq->download) {
            $lp->handle_download($leg);
        }

        if ($qreq->date !== "now") {
            $lp->set_date();
        }
        $page = $lp->choose_page($leg, $page);
        $lp->print_page($leg, $page);
    }
}
