<?php
// pages/p_log.php -- HotCRP action log page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Log_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var int */
    public $nlinks = 6;
    /** @var null|false|int */
    public $first_timestamp = false;
    /** @var array<int,string> */
    public $user_html = [];
    /** @var list<string> */
    private $lef_clauses = [];
    /** @var ?array<int,mixed> */
    private $include_pids;
    /** @var ?array<int,mixed> */
    private $exclude_pids;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
    }


    /** @param string $query
     * @param ?string $field */
    private function add_search_clause($query, $field) {
        $search = new PaperSearch($this->viewer, ["t" => "all", "q" => $query]);
        $search->set_allow_deleted(true);
        $pids = $search->paper_ids();
        if ($search->has_problem()) {
            Ht::warning_at($field, $search->full_feedback_html());
        }
        if (!empty($pids)) {
            $w = [];
            foreach ($pids as $p) {
                $w[] = "paperId=$p";
                $w[] = "action like '%(papers% $p,%'";
                $w[] = "action like '%(papers% $p)%'";
            }
            $this->lef_clauses[] = "(" . join(" or ", $w) . ")";
            $this->include_pids = array_flip($pids);
        } else {
            if (!$search->has_problem()) {
                Ht::warning_at($field, "No papers match that search.");
            }
            $this->lef_clauses[] = "false";
        }
    }

    private function add_user_clause() {
        $ids = [];
        $accts = new SearchSplitter($this->qreq->u);
        while (($word = $accts->shift()) !== "") {
            $flags = ContactSearch::F_TAG | ContactSearch::F_USER | ContactSearch::F_ALLOW_DELETED;
            if (substr($word, 0, 1) === "\"") {
                $flags |= ContactSearch::F_QUOTED;
                $word = preg_replace('/(?:\A"|"\z)/', "", $word);
            }
            $search = new ContactSearch($flags, $word, $this->viewer);
            foreach ($search->user_ids() as $id) {
                $ids[$id] = $id;
            }
        }
        $w = [];
        if (!empty($ids)) {
            $result = $this->conf->qe("select contactId, email from ContactInfo where contactId?a union select contactId, email from DeletedContactInfo where contactId?a", $ids, $ids);
            while (($row = $result->fetch_row())) {
                $w[] = "contactId=$row[0]";
                $w[] = "destContactId=$row[0]";
                $x = sqlq(Dbl::escape_like($row[1]));
                $w[] = "action like " . Dbl::utf8ci("'% {$x}%'");
            }
        }
        if (!empty($w)) {
            $this->lef_clauses[] = "(" . join(" or ", $w) . ")";
        } else {
            Ht::warning_at("u", "No matching users.");
            $this->lef_clauses[] = "false";
        }
    }

    private function add_action_clause() {
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
                $x = sqlq(Dbl::escape_like($m[1]));
                $w[] = "action like " . Dbl::utf8ci("'%{$x}%'");
            }
        }
        $this->lef_clauses[] = "(" . join(" or ", $w) . ")";
    }

    private function set_date() {
        $this->first_timestamp = $this->conf->parse_time($this->qreq->date);
        if ($this->first_timestamp === false) {
            Ht::error_at("date", "Invalid date. Try format “YYYY-MM-DD HH:MM:SS”.");
        }
    }


    /** @param int $count
     * @return LogEntryGenerator */
    private function make_generator($count) {
        $leg = new LogEntryGenerator($this->conf, $this->lef_clauses, $count);

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
            while ($leg->page_after($page, $this->first_timestamp, ceil(2000 / $leg->page_size()))) {
                ++$page;
            }
            $delta = 0;
            foreach ($leg->page_rows($page) as $row) {
                if ($row->timestamp > $this->first_timestamp)
                    ++$delta;
            }
            if ($delta) {
                $leg->set_page_delta($delta);
                ++$page;
            }
        } else if (!$page) { // handle `earliest`
            $page = 1;
            while ($leg->has_page($page + 1, ceil(2000 / $leg->page_size()))) {
                ++$page;
            }
        } else if ($this->qreq->offset
                   && ($delta = cvtint($this->qreq->offset)) >= 0
                   && $delta < $leg->page_size()) {
            $leg->set_page_delta($delta);
        }
        return $page;
    }


    /** @param LogEntryGenerator $leg */
    function handle_download($leg) {
        session_commit();
        assert(Contact::ROLE_PC === 1 && Contact::ROLE_ADMIN === 2 && Contact::ROLE_CHAIR === 4);
        $role_map = ["", "pc", "sysadmin", "pc sysadmin", "chair", "chair", "chair", "chair"];

        $csvg = $this->conf->make_csvg("log");
        $narrow = true;
        $headers = ["date", "ipaddr", "email"];
        if ($narrow) {
            $headers[] = "roles";
        }
        array_push($headers, "affected_email", "via", $narrow ? "paper" : "papers", "action");
        $csvg->select($headers);
        foreach ($leg->page_rows(1) as $row) {
            $date = date("Y-m-d H:i:s e", (int) $row->timestamp);
            $xusers = $leg->users_for($row, "contactId");
            $xdest_users = $leg->users_for($row, "destContactId");
            if ($xdest_users == $xusers) {
                $xdest_users = [];
            }
            if ($row->trueContactId) {
                $via = $row->trueContactId < 0 ? "link" : "admin";
            } else {
                $via = "";
            }
            $pids = $leg->paper_ids($row);
            $action = $leg->cleaned_action($row);
            if ($narrow) {
                empty($xusers) && ($xusers[] = null);
                empty($xdest_users) && ($xdest_users[] = null);
                empty($pids) && ($pids[] = "");
                foreach ($xusers as $u1) {
                    $u1e = $u1 ? $u1->email : "";
                    $u1r = $u1 ? $role_map[$u1->roles & 7] : "";
                    foreach ($xdest_users as $u2) {
                        $u2e = $u2 ? $u2->email : "";
                        foreach ($pids as $p) {
                            $csvg->add_row([
                                $date, $row->ipaddr ?? "", $u1e, $u1r, $u2e,
                                $via, $p, $action
                            ]);
                        }
                    }
                }
            } else {
                $u1es = $u2es = [];
                foreach ($xusers as $u) {
                    $u1es[] = $u->email;
                }
                foreach ($xdest_users as $u) {
                    $u2es[] = $u->email;
                }
                $csvg->add_row([
                    $date, $row->ipaddr ?? "", join(" ", $u1es), join(" ", $u2es),
                    $via, join(" ", $pids), $action
                ]);
            }
        }
        $csvg->emit();
        exit;
    }


    // render search list
    /** @param int $page */
    function print_searchbar(LogEntryGenerator $leg, $page) {
        $date = "";
        $dplaceholder = null;
        if (Ht::problem_status_at("date")) {
            $date = $this->qreq->date;
        } else if ($page === 1) {
            $dplaceholder = "now";
        } else if (($rows = $leg->page_rows($page))) {
            $dplaceholder = $this->conf->unparse_time_log((int) $rows[0]->timestamp);
        } else if ($this->first_timestamp) {
            $dplaceholder = $this->conf->unparse_time_log((int) $this->first_timestamp);
        }

        echo Ht::form($this->conf->hoturl("log"), ["method" => "get", "id" => "searchform", "class" => "clearfix"]);
        if ($this->qreq->forceShow) {
            echo Ht::hidden("forceShow", 1);
        }
        echo '<div class="d-inline-block" style="padding-right:2rem">',
            '<div class="', Ht::control_class("q", "entryi medium"),
            '"><label for="q">Concerning action(s)</label><div class="entry">',
            Ht::feedback_html_at("q"),
            Ht::entry("q", $this->qreq->q, ["id" => "q", "size" => 40]),
            '</div></div><div class="', Ht::control_class("p", "entryi medium"),
            '"><label for="p">Concerning paper(s)</label><div class="entry">',
            Ht::feedback_html_at("p"),
            Ht::entry("p", $this->qreq->p, ["id" => "p", "class" => "need-suggest papersearch", "autocomplete" => "off", "size" => 40, "spellcheck" => false]),
            '</div></div><div class="', Ht::control_class("u", "entryi medium"),
            '"><label for="u">Concerning user(s)</label><div class="entry">',
            Ht::feedback_html_at("u"),
            Ht::entry("u", $this->qreq->u, ["id" => "u", "size" => 40]),
            '</div></div><div class="', Ht::control_class("n", "entryi medium"),
            '"><label for="n">Show</label><div class="entry">',
            Ht::entry("n", $this->qreq->n, ["id" => "n", "size" => 4, "placeholder" => 50]),
            '  records at a time',
            Ht::feedback_html_at("n"),
            '</div></div><div class="', Ht::control_class("date", "entryi medium"),
            '"><label for="date">Starting at</label><div class="entry">',
            Ht::feedback_html_at("date"),
            Ht::entry("date", $date, ["id" => "date", "size" => 40, "placeholder" => $dplaceholder]),
            '</div></div></div>',
            Ht::submit("Show"),
            Ht::submit("download", "Download", ["class" => "ml-3"]),
            '</form>';

        if ($page > 1 || $leg->has_page(2)) {
            $urls = ["q=" . urlencode($this->qreq->q)];
            foreach (["p", "u", "n", "forceShow"] as $x) {
                if ($this->qreq[$x])
                    $urls[] = "$x=" . urlencode($this->qreq[$x]);
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
                echo "&nbsp;...";
            }
            for ($p = max($page - $this->nlinks, 1); $p < $page; ++$p) {
                echo "&nbsp;", $leg->page_link_html($p, $p);
            }
            echo "</div></td><td><div><strong class=\"thispage\">&nbsp;", $page, "&nbsp;</strong></div></td><td><div class=\"lognavd\">";
            for ($p = $page + 1; $p <= $page + $this->nlinks && $leg->has_page($p); ++$p) {
                echo $leg->page_link_html($p, $p), "&nbsp;";
            }
            if ($leg->has_page($page + $this->nlinks + 1)) {
                echo "...&nbsp;";
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
        if ($user->disablement & Contact::DISABLEMENT_DELETED) {
            $t = '<del>' . $user->name_h(NAME_E) . '</del>';
        } else {
            $t = $user->name_h(NAME_P);
        }
        $dt = null;
        if (($viewable = $user->viewable_tags($this->viewer))) {
            $dt = $this->conf->tags();
            if (($colors = $dt->color_classes($viewable))) {
                $t = '<span class="' . $colors . ' taghh">' . $t . '</span>';
            }
        }
        $url = $this->conf->hoturl("log", ["q" => "", "u" => $user->email, "n" => $this->qreq->n]);
        $t = "<a href=\"{$url}\">{$t}</a>";
        if ($dt && $dt->has_decoration) {
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
     * @return string */
    function users_html($users, $via) {
        if (empty($users) && $via < 0) {
            return "<i>via author link</i>";
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
                && (!isset($user->roles) || !($user->roles & Contact::ROLE_PCLIKE))) {
                $all_pc = false;
            }
            if ($user->disablement & Contact::DISABLEMENT_DELETED) {
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
                if ($via) {
                    $t .= ($via < 0 ? ' <i>via link</i>' : ' <i>via admin</i>');
                }
            }
            $ts[] = $t;
            $last_user = $user;
        }
        if (count($ts) <= 3) {
            return join(", ", $ts);
        } else {
            $fmt = $all_pc ? "%d PC users" : "%d users";
            return '<div class="has-fold foldc"><a href="" class="ui js-foldup">'
                . expander(null, 0)
                . '</a>'
                . '<span class="fn"><a href="" class="ui js-foldup q">'
                . sprintf($this->conf->_($fmt, count($ts)), count($ts))
                . '</a></span><span class="fx">' . join(", ", $ts)
                . '</span></div>';
        }
    }

    /** @param LogEntryGenerator $leg
     * @param int $page */
    function print_page($leg, $page) {
        $conf = $this->conf;
        $conf->header("Log", "actionlog");

        $trs = [];
        $has_dest_user = false;
        foreach ($leg->page_rows($page) as $row) {
            $time = $conf->unparse_time_log((int) $row->timestamp);
            $t = ["<td class=\"pl pl_logtime\">{$time}</td>"];

            $via = $row->trueContactId;
            $xusers = $leg->users_for($row, "contactId");
            $xusers_html = $this->users_html($xusers, $via);
            $xdest_users = $leg->users_for($row, "destContactId");

            if ($xdest_users && $xusers != $xdest_users) {
                $xdestusers_html = $this->users_html($xdest_users, false);
                $t[] = "<td class=\"pl pl_logname\">{$xusers_html}</td><td class=\"pl pl_logname\">{$xdestusers_html}</td>";
                $has_dest_user = true;
            } else {
                $t[] = "<td class=\"pl pl_logname\" colspan=\"2\">{$xusers_html}</td>";
            }

            // XXX users that aren't in contactId slot
            // if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
            //     $t .= htmlspecialchars($m[2]);
            //     $act = $m[1] . $m[3];
            // } else
            //     $t .= "[None]";

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
                $at = "<a href=\"" . $conf->hoturl("paper", "p={$row->paperId}#cid{$m[1]}") . "\">Comment " . $m[1] . "</a>";
                $act = $m[2];
            } else if (substr($act, 0, 8) === "Response"
                       && preg_match('/\AResponse (\d+)(.*)\z/s', $act, $m)) {
                $at = "<a href=\"" . $conf->hoturl("paper", "p={$row->paperId}#cid{$m[1]}") . "\">Response " . $m[1] . "</a>";
                $act = $m[2];
            } else if (strpos($act, " mail ") !== false
                       && preg_match('/\A(Sending|Sent|Account was sent) mail #(\d+)(.*)\z/s', $act, $m)) {
                $at = $m[1] . " <a href=\"" . $conf->hoturl("mail", "mailid=$m[2]") . "\">mail #$m[2]</a>";
                $act = $m[3];
            } else if (substr($act, 0, 3) === "Tag"
                       && preg_match('{\ATag:? ((?:[-+]#[^\s#]*(?:#[-+\d.]+|)(?: |\z))+)(.*)\z}s', $act, $m)) {
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
                       && (substr($act, 0, 8) === "Updated "
                           || substr($act, 0, 10) === "Submitted "
                           || substr($act, 0, 11) === "Registered ")
                       && preg_match('/\A(\S+(?: final)?)(.*)\z/', $act, $m)
                       && preg_match('/\A(.* )(final|submission)((?:,| |\z).*)\z/', $m[2], $mm)) {
                $at = $m[1] . $mm[1] . "<a href=\"" . $conf->hoturl("doc", "p={$row->paperId}&amp;dt={$mm[2]}&amp;at={$row->timestamp}") . "\">{$mm[2]}</a>";
                $act = $mm[3];
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
            $t[] = "<td class=\"pl pl_logaction\">{$at}</td>";
            $trs[] = '    <tr class="plnx k' . (count($trs) % 2) . '">' . join("", $t) . "</tr>\n";
        }

        if (!$this->viewer->privChair || !empty($this->exclude_pids)) {
            echo '<div class="msgs-wide">';
            if (!$this->viewer->privChair) {
                $conf->feedback_msg(new MessageItem(null, "<0>Only showing your actions, plus entries for papers you administer", MessageSet::MARKED_NOTE));
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
                    $conf->feedback_msg(new MessageItem(null, "<5>Showing all entries (" . Ht::link("unprivileged view", $conf->selfurl($this->qreq, $req + ["forceShow" => null])) . ")", MessageSet::MARKED_NOTE));
                } else {
                    $conf->feedback_msg(new MessageItem(null, "<5>Not showing entries for " . Ht::link("conflicted administered papers", $conf->hoturl("search", "q=" . join("+", array_keys($this->exclude_pids)))), MessageSet::MARKED_NOTE));
                }
            }
            echo '</div>';
        }

        $this->print_searchbar($leg, $page);
        if (!empty($trs)) {
            echo "<table class=\"pltable fullw pltable-log\">\n",
                '  <thead><tr class="pl_headrow">',
                '<th class="pll plh pl_logtime">Time</th>',
                '<th class="pll plh pl_logname">User</th>',
                '<th class="pll plh pl_logname">Affected user</th>',
                '<th class="pll plh pl_logaction">Action</th></tr></thead>',
                "\n  <tbody class=\"pltable\">\n",
                join("", $trs),
                "  </tbody>\n</table>\n";
        } else {
            echo "No records\n";
        }

        $conf->footer();
    }

    static function go(Contact $viewer, Qrequest $qreq) {
        if (!$viewer->is_manager()) {
            $viewer->escape();
        }

        // clean request
        unset($qreq->forceShow, $_GET["forceShow"], $_POST["forceShow"]);

        if ($qreq->page === "earliest") {
            $page = null;
        } else {
            $page = max(cvtint($qreq->page, -1), 1);
        }

        $count = 50;
        if (isset($qreq->n) && trim($qreq->n) !== "") {
            $count = cvtint($qreq->n, -1);
            if ($count <= 0) {
                $count = 50;
                Ht::error_at("n", "Show records: Expected a number greater than 0.");
            }
        }
        $count = min($count, 200);

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
        $lp = new Log_Page($viewer, $qreq);
        if ($qreq->p !== "") {
            $lp->add_search_clause($qreq->p, "p");
        }
        if ($qreq->u !== "") {
            $lp->add_user_clause();
        }
        if ($qreq->q !== "") {
            $lp->add_action_clause();
        }

        // create entry generator
        $leg = $lp->make_generator($qreq->download ? 10000000 : $count);

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
