<?php
// log.php -- HotCRP action log
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->is_manager()) {
    $Me->escape();
}

unset($Qreq->forceShow, $_GET["forceShow"], $_POST["forceShow"]);
$nlinks = 6;

$page = $Qreq->page;
if ($page === "earliest") {
    $page = false;
} else {
    $page = cvtint($page, -1);
    if ($page <= 0)
        $page = 1;
}

$count = 50;
if (isset($Qreq->n) && trim($Qreq->n) !== "") {
    $count = cvtint($Qreq->get("n", 50), -1);
    if ($count <= 0) {
        $count = 50;
        Ht::error_at("n", "Show records: Expected a number greater than 0.");
    }
}
$count = min($count, 200);

$Qreq->q = trim((string) $Qreq->q);
$Qreq->p = trim((string) $Qreq->p);
if (isset($Qreq->acct) && !isset($Qreq->u)) {
    $Qreq->u = $Qreq->acct;
}
$Qreq->u = trim((string) $Qreq->u);
$Qreq->date = trim($Qreq->get("date", "now"));

$wheres = array();

$include_pids = null;
if ($Qreq->p !== "") {
    $Search = new PaperSearch($Me, ["t" => "all", "q" => $Qreq->p]);
    $Search->set_allow_deleted(true);
    $include_pids = $Search->paper_ids();
    foreach ($Search->problem_texts() as $w) {
        Ht::warning_at("p", $w);
    }
    if (!empty($include_pids)) {
        $where = array();
        foreach ($include_pids as $p) {
            $where[] = "paperId=$p";
            $where[] = "action like '%(papers% $p,%'";
            $where[] = "action like '%(papers% $p)%'";
        }
        $wheres[] = "(" . join(" or ", $where) . ")";
        $include_pids = array_flip($include_pids);
    } else {
        if (!$Search->has_problem()) {
            Ht::warning_at("p", "No papers match that search.");
        }
        $wheres[] = "false";
    }
}

if ($Qreq->u !== "") {
    $ids = array();
    $accts = new SearchSplitter($Qreq->u);
    while (($word = $accts->shift()) !== "") {
        $flags = ContactSearch::F_TAG | ContactSearch::F_USER | ContactSearch::F_ALLOW_DELETED;
        if (substr($word, 0, 1) === "\"") {
            $flags |= ContactSearch::F_QUOTED;
            $word = preg_replace(',(?:\A"|"\z),', "", $word);
        }
        $Search = new ContactSearch($flags, $word, $Me);
        foreach ($Search->user_ids() as $id) {
            $ids[$id] = $id;
        }
    }
    $where = array();
    if (count($ids)) {
        $result = $Conf->qe("select contactId, email from ContactInfo where contactId?a union select contactId, email from DeletedContactInfo where contactId?a", $ids, $ids);
        while (($row = $result->fetch_row())) {
            $where[] = "contactId=$row[0]";
            $where[] = "destContactId=$row[0]";
            $where[] = "action like " . Dbl::utf8ci("'% " . sqlq_for_like($row[1]) . "%'");
        }
    }
    if (count($where)) {
        $wheres[] = "(" . join(" or ", $where) . ")";
    } else {
        Ht::warning_at("u", "No matching users.");
        $wheres[] = "false";
    }
}

if ($Qreq->q !== "") {
    $where = array();
    $str = $Qreq->q;
    while (($str = ltrim($str)) !== "") {
        if ($str[0] === '"') {
            preg_match('/\A"([^"]*)"?/', $str, $m);
        } else {
            preg_match('/\A([^"\s]+)/', $str, $m);
        }
        $str = (string) substr($str, strlen($m[0]));
        if ($m[1] !== "") {
            $where[] = "action like " . Dbl::utf8ci("'%" . sqlq_for_like($m[1]) . "%'");
        }
    }
    $wheres[] = "(" . join(" or ", $where) . ")";
}

$first_timestamp = false;
if ($Qreq->date === "") {
    $Qreq->date = "now";
}
if ($Qreq->date !== "now" && isset($Qreq->q)) {
    $first_timestamp = $Conf->parse_time($Qreq->date);
    if ($first_timestamp === false) {
        Ht::error_at("date", "Invalid date. Try format “YYYY-MM-DD HH:MM:SS”.");
    }
}

class LogRow {
    /** @var non-empty-string */
    public $logId;
    /** @var non-empty-string */
    public $timestamp;
    /** @var non-empty-string */
    public $contactId;
    /** @var ?non-empty-string */
    public $destContactId;
    /** @var ?non-empty-string */
    public $trueContactId;
    /** @var string */
    public $action;
    /** @var ?non-empty-string */
    public $paperId;
    public $data;

    public $cleanedAction;
    /** @var ?list<int> */
    public $paperIdArray;
    /** @var ?list<int> */
    public $destContactIdArray;
}

class LogRowGenerator {
    /** @var Conf */
    private $conf;
    private $wheres;
    private $page_size;
    private $delta = 0;
    private $lower_offset_bound;
    private $upper_offset_bound;
    private $rows_offset;
    private $rows_max_offset;
    /** @var list<LogRow> */
    private $rows = [];
    private $filter;
    private $page_to_offset;
    private $log_url_base;
    private $explode_mail = false;
    private $mail_stash;
    /** @var array<int,Contact> */
    private $users;
    /** @var array<int,true> */
    private $need_users;

    function __construct(Conf $conf, $wheres, $page_size) {
        $this->conf = $conf;
        $this->wheres = $wheres;
        $this->page_size = $page_size;
        $this->set_filter(null);
        $this->users = $conf->pc_users();
        $this->need_users = [];
    }

    function set_filter($filter) {
        $this->filter = $filter;
        $this->rows = [];
        $this->lower_offset_bound = 0;
        $this->upper_offset_bound = INF;
        $this->page_to_offset = [];
    }

    function set_explode_mail($explode_mail) {
        $this->explode_mail = $explode_mail;
    }

    function has_filter() {
        return !!$this->filter;
    }

    function page_size() {
        return $this->page_size;
    }

    function page_delta() {
        return $this->delta;
    }

    function set_page_delta($delta) {
        assert(is_int($delta) && $delta >= 0 && $delta < $this->page_size);
        $this->delta = $delta;
    }

    private function page_offset($pageno) {
        $offset = ($pageno - 1) * $this->page_size;
        if ($offset > 0 && $this->delta > 0) {
            $offset -= $this->page_size - $this->delta;
        }
        return $offset;
    }

    private function load_rows($pageno, $limit, $delta_adjusted = false) {
        $limit = (int) $limit;
        if ($pageno > 1 && $this->delta > 0 && !$delta_adjusted) {
            --$pageno;
            $limit += $this->page_size;
        }
        $offset = ($pageno - 1) * $this->page_size;
        $db_offset = $offset;
        if (($this->filter || !$this->explode_mail) && $db_offset !== 0) {
            if (!isset($this->page_to_offset[$pageno])) {
                $xlimit = min(4 * $this->page_size + $limit, 2000);
                $xpageno = max($pageno - floor($xlimit / $this->page_size), 1);
                $this->load_rows($xpageno, $xlimit, true);
                if ($this->rows_offset <= $offset && $offset + $limit <= $this->rows_max_offset)
                    return;
            }
            $xpageno = $pageno;
            while ($xpageno > 1 && !isset($this->page_to_offset[$xpageno])) {
                --$xpageno;
            }
            $db_offset = $xpageno > 1 ? $this->page_to_offset[$xpageno] : 0;
        }

        $q = "select logId, timestamp, contactId, destContactId, trueContactId, action, paperId from ActionLog";
        if (!empty($this->wheres)) {
            $q .= " where " . join(" and ", $this->wheres);
        }
        $q .= " order by logId desc";

        $this->rows = [];
        $this->rows_offset = $offset;
        $n = 0;
        $exhausted = false;
        while ($n < $limit && !$exhausted) {
            $result = $this->conf->qe_raw($q . " limit $db_offset,$limit");
            $first_db_offset = $db_offset;
            while (($row = $result->fetch_object("LogRow"))) {
                '@phan-var LogRow $row';
                $this->need_users[(int) $row->contactId] = true;
                $destuid = (int) ($row->destContactId ? : $row->contactId);
                $this->need_users[$destuid] = true;
                ++$db_offset;
                if (!$this->explode_mail
                    && $this->mail_stash
                    && $this->mail_stash->action === $row->action) {
                    $this->mail_stash->destContactIdArray[] = $destuid;
                    if ($row->paperId) {
                        $this->mail_stash->paperIdArray[] = (int) $row->paperId;
                    }
                    continue;
                }
                if (!$this->filter || call_user_func($this->filter, $row)) {
                    $this->rows[] = $row;
                    ++$n;
                    if ($n % $this->page_size === 0) {
                        $this->page_to_offset[$pageno + ($n / $this->page_size)] = $db_offset;
                    }
                    if (!$this->explode_mail) {
                        if (substr($row->action, 0, 11) === "Sent mail #") {
                            $this->mail_stash = $row;
                            $row->destContactIdArray = [$destuid];
                            $row->destContactId = null;
                            $row->paperIdArray = [];
                            if ($row->paperId) {
                                $row->paperIdArray[] = (int) $row->paperId;
                                $row->paperId = null;
                            }
                        } else {
                            $this->mail_stash = null;
                        }
                    }
                }
            }
            Dbl::free($result);
            $exhausted = $first_db_offset + $limit !== $db_offset;
        }

        if ($n > 0) {
            $this->lower_offset_bound = max($this->lower_offset_bound, $this->rows_offset + $n);
        }
        if ($exhausted) {
            $this->upper_offset_bound = min($this->upper_offset_bound, $this->rows_offset + $n);
        }
        $this->rows_max_offset = $exhausted ? INF : $this->rows_offset + $n;
    }

    /** @param int $pageno
     * @return bool */
    function has_page($pageno, $load_npages = null) {
        global $nlinks;
        assert(is_int($pageno) && $pageno >= 1);
        $offset = $this->page_offset($pageno);
        if ($offset >= $this->lower_offset_bound
            && $offset < $this->upper_offset_bound) {
            if ($load_npages) {
                $limit = $load_npages * $this->page_size;
            } else {
                $limit = ($nlinks + 1) * $this->page_size + 30;
            }
            if ($this->filter) {
                $limit = max($limit, 2000);
            }
            $this->load_rows($pageno, $limit);
        }
        return $offset < $this->lower_offset_bound;
    }

    /** @param int $pageno
     * @param int $timestamp
     * @return bool */
    function page_after($pageno, $timestamp, $load_npages = null) {
        $rows = $this->page_rows($pageno, $load_npages);
        return !empty($rows) && $rows[count($rows) - 1]->timestamp > $timestamp;
    }

    /** @param int $pageno
     * @return list<LogRow> */
    function page_rows($pageno, $load_npages = null) {
        assert(is_int($pageno) && $pageno >= 1);
        if (!$this->has_page($pageno, $load_npages)) {
            return [];
        }
        $offset = $this->page_offset($pageno);
        if ($offset < $this->rows_offset
            || $offset + $this->page_size > $this->rows_max_offset) {
            $this->load_rows($pageno, $this->page_size);
        }
        return array_slice($this->rows, $offset - $this->rows_offset, $this->page_size);
    }

    function set_log_url_base($url) {
        $this->log_url_base = $url;
    }

    function page_link_html($pageno, $html) {
        $url = $this->log_url_base;
        if ($pageno !== 1 && $this->delta > 0) {
            $url .= "&amp;offset=" . $this->delta;
        }
        return '<a href="' . $url . '&amp;page=' . $pageno . '">' . $html . '</a>';
    }

    private function _make_users() {
        unset($this->need_users[0]);
        $this->need_users = array_diff_key($this->need_users, $this->users);
        if (!empty($this->need_users)) {
            $result = $this->conf->qe("select contactId, firstName, lastName, affiliation, email, contactTags, roles from ContactInfo where contactId?a", array_keys($this->need_users));
            while (($user = Contact::fetch($result, $this->conf))) {
                $this->users[$user->contactId] = $user;
                unset($this->need_users[$user->contactId]);
            }
            Dbl::free($result);
        }
        if (!empty($this->need_users)) {
            foreach ($this->need_users as $cid => $x) {
                $user = $this->users[$cid] = new Contact(["contactId" => $cid, "disabled" => 1], $this->conf);
                $user->disabled = "deleted";
            }
            $result = $this->conf->qe("select contactId, firstName, lastName, '' affiliation, email, 1 disabled from DeletedContactInfo where contactId?a", array_keys($this->need_users));
            while (($user = Contact::fetch($result, $this->conf))) {
                $this->users[$user->contactId] = $user;
                $user->disabled = "deleted";
            }
            Dbl::free($result);
        }
        $this->need_users = [];
    }

    /** @param LogRow $row
     * @param 'contactId'|'destContactId'|'trueContactId' $key
     * @return list<Contact> */
    function users_for($row, $key) {
        if (!empty($this->need_users)) {
            $this->_make_users();
        }
        $uid = $row->$key;
        if (!$uid && $key === "contactId") {
            $uid = $row->destContactId;
        }
        $u = $uid ? [$this->users[$uid]] : [];
        if ($key === "destContactId" && isset($row->destContactIdArray)) {
            foreach ($row->destContactIdArray as $uid) {
                $u[] = $this->users[$uid];
            }
        }
        return $u;
    }

    /** @param LogRow $row
     * @return list<int> */
    function paper_ids($row) {
        if (!isset($row->cleanedAction)) {
            if (!isset($row->paperIdArray)) {
                $row->paperIdArray = [];
            }
            if (preg_match('/\A(.* |)\(papers ([\d, ]+)\)?\z/', $row->action, $m)) {
                $row->cleanedAction = rtrim($m[1]);
                foreach (preg_split('/[\s,]+/', $m[2]) as $p) {
                    if ($p !== "")
                        $row->paperIdArray[] = (int) $p;
                }
            } else {
                $row->cleanedAction = $row->action;
            }
            if ($row->paperId) {
                $row->paperIdArray[] = (int) $row->paperId;
            }
            $row->paperIdArray = array_values(array_unique($row->paperIdArray));
        }
        return $row->paperIdArray;
    }

    function cleaned_action($row) {
        if (!isset($row->cleanedAction)) {
            $this->paper_ids($row);
        }
        return $row->cleanedAction;
    }
}

class LogRowFilter {
    private $user;
    private $pidset;
    private $want;
    private $includes;

    function __construct(Contact $user, $pidset, $want, $includes) {
        $this->user = $user;
        $this->pidset = $pidset;
        $this->want = $want;
        $this->includes = $includes;
    }
    private function test_pidset($row, $pidset, $want, $includes) {
        if ($row->paperId) {
            return isset($pidset[$row->paperId]) === $want
                && (!$includes || isset($includes[$row->paperId]));
        } else if (preg_match('/\A(.*) \(papers ([\d, ]+)\)?\z/', $row->action, $m)) {
            preg_match_all('/\d+/', $m[2], $mm);
            $pids = [];
            $included = !$includes;
            foreach ($mm[0] as $pid) {
                if (isset($pidset[$pid]) === $want) {
                    $pids[] = $pid;
                    $included = $included || isset($includes[$pid]);
                }
            }
            if (empty($pids) || !$included) {
                return false;
            } else if (count($pids) === 1) {
                $row->action = $m[1];
                $row->paperId = $pids[0];
            } else {
                $row->action = $m[1] . " (papers " . join(", ", $pids) . ")";
            }
            return true;
        } else
            return $this->user->privChair;
    }
    function __invoke($row) {
        if ($this->user->hidden_papers !== null
            && !$this->test_pidset($row, $this->user->hidden_papers, false, null)) {
            return false;
        } else if ($row->contactId === $this->user->contactId) {
            return true;
        } else {
            return $this->test_pidset($row, $this->pidset, $this->want, $this->includes);
        }
    }
}

if ($Qreq->download) {
    $lrg = new LogRowGenerator($Conf, $wheres, 1000000);
} else {
    $lrg = new LogRowGenerator($Conf, $wheres, $count);
}

$exclude_pids = $Me->hidden_papers ? : [];
if ($Me->privChair && $Conf->has_any_manager()) {
    foreach ($Me->paper_set(["myConflicts" => true]) as $prow) {
        if (!$Me->allow_administer($prow))
            $exclude_pids[$prow->paperId] = true;
    }
}

if (!$Me->privChair) {
    $good_pids = [];
    foreach ($Me->paper_set($Conf->check_any_admin_tracks($Me) ? [] : ["myManaged" => true]) as $prow) {
        if ($Me->allow_administer($prow))
            $good_pids[$prow->paperId] = true;
    }
    $lrg->set_filter(new LogRowFilter($Me, $good_pids, true, $include_pids));
} else if (!$Qreq->forceShow && !empty($exclude_pids)) {
    $lrg->set_filter(new LogRowFilter($Me, $exclude_pids, false, $include_pids));
}

if ($Qreq->download) {
    session_commit();
    $csvg = $Conf->make_csvg("log");
    $narrow = true;
    $csvg->select(["date", "email", "affected_email", "via",
                   $narrow ? "paper" : "papers", "action"]);
    foreach ($lrg->page_rows(1) as $row) {
        $date = strftime("%Y-%m-%d %H:%M:%S %z", (int) $row->timestamp);
        $xusers = $xdest_users = [];
        foreach ($lrg->users_for($row, "contactId") as $u) {
            $xusers[] = $u->email;
        }
        foreach ($lrg->users_for($row, "destContactId") as $u) {
            $xdest_users[] = $u->email;
        }
        if ($xdest_users == $xusers) {
            $xdest_users = [];
        }
        if ($row->trueContactId) {
            $via = $row->trueContactId < 0 ? "link" : "admin";
        } else {
            $via = "";
        }
        $pids = $lrg->paper_ids($row);
        $action = $lrg->cleaned_action($row);
        if ($narrow) {
            if (empty($xusers)) {
                $xusers = [""];
            }
            if (empty($xdest_users)) {
                $xdest_users = [""];
            }
            if (empty($pids)) {
                $pids = [];
            }
            foreach ($xusers as $u1) {
                foreach ($xdest_users as $u2) {
                    foreach ($pids as $p) {
                        $csvg->add_row([$date, $u1, $u2, $via, $p, $action]);
                    }
                }
            }
        } else {
            $csvg->add_row([
                $date, join(" ", $xusers), join(" ", $xdest_users),
                $via, join(" ", $pids), $action
            ]);
        }
    }
    csv_exit($csvg);
}

if ($first_timestamp) {
    $page = 1;
    while ($lrg->page_after($page, $first_timestamp, ceil(2000 / $lrg->page_size()))) {
        ++$page;
    }
    $delta = 0;
    foreach ($lrg->page_rows($page) as $row) {
        if ($row->timestamp > $first_timestamp)
            ++$delta;
    }
    if ($delta) {
        $lrg->set_page_delta($delta);
        ++$page;
    }
} else if ($page === false) { // handle `earliest`
    $page = 1;
    while ($lrg->has_page($page + 1, ceil(2000 / $lrg->page_size()))) {
        ++$page;
    }
} else if ($Qreq->offset
           && ($delta = cvtint($Qreq->offset)) >= 0
           && $delta < $lrg->page_size()) {
    $lrg->set_page_delta($delta);
}


// render search list
function searchbar(LogRowGenerator $lrg, $page) {
    global $Conf, $Me, $nlinks, $Qreq, $first_timestamp;

    $date = "";
    $dplaceholder = null;
    if (Ht::problem_status_at("date")) {
        $date = $Qreq->date;
    } else if ($page === 1) {
        $dplaceholder = "now";
    } else if (($rows = $lrg->page_rows($page))) {
        $dplaceholder = $Conf->unparse_time((int) $rows[0]->timestamp);
    } else if ($first_timestamp) {
        $dplaceholder = $Conf->unparse_time((int) $first_timestamp);
    }

    echo Ht::form(hoturl("log"), ["method" => "get", "id" => "searchform"]);
    if ($Qreq->forceShow)
        echo Ht::hidden("forceShow", 1);
    echo '<div class="d-inline-block" style="padding-right:2rem">',
        '<div class="', Ht::control_class("q", "entryi medium"),
        '"><label for="q">Concerning action(s)</label><div class="entry">',
        Ht::render_feedback_at("q"),
        Ht::entry("q", $Qreq->q, ["id" => "q", "size" => 40]),
        '</div></div><div class="', Ht::control_class("p", "entryi medium"),
        '"><label for="p">Concerning paper(s)</label><div class="entry">',
        Ht::render_feedback_at("p"),
        Ht::entry("p", $Qreq->p, ["id" => "p", "class" => "need-suggest papersearch", "autocomplete" => "off", "size" => 40]),
        '</div></div><div class="', Ht::control_class("u", "entryi medium"),
        '"><label for="u">Concerning user(s)</label><div class="entry">',
        Ht::render_feedback_at("u"),
        Ht::entry("u", $Qreq->u, ["id" => "u", "size" => 40]),
        '</div></div><div class="', Ht::control_class("n", "entryi medium"),
        '"><label for="n">Show</label><div class="entry">',
        Ht::entry("n", $Qreq->n, ["id" => "n", "size" => 4, "placeholder" => 50]),
        '  records at a time',
        Ht::render_feedback_at("n"),
        '</div></div><div class="', Ht::control_class("date", "entryi medium"),
        '"><label for="date">Starting at</label><div class="entry">',
        Ht::render_feedback_at("date"),
        Ht::entry("date", $date, ["id" => "date", "size" => 40, "placeholder" => $dplaceholder]),
        '</div></div></div>',
        Ht::submit("Show"),
        Ht::submit("download", "Download", ["class" => "ml-3"]),
        '</form>';

    if ($page > 1 || $lrg->has_page(2)) {
        $urls = ["q=" . urlencode($Qreq->q)];
        foreach (["p", "u", "n", "forceShow"] as $x) {
            if ($Qreq[$x])
                $urls[] = "$x=" . urlencode($Qreq[$x]);
        }
        $lrg->set_log_url_base(hoturl("log", join("&amp;", $urls)));
        echo "<table class=\"lognav\"><tr><td><div class=\"lognavdr\">";
        if ($page > 1) {
            echo $lrg->page_link_html(1, "<strong>Newest</strong>"), " &nbsp;|&nbsp;&nbsp;";
        }
        echo "</div></td><td><div class=\"lognavxr\">";
        if ($page > 1) {
            echo $lrg->page_link_html($page - 1, "<strong>" . Icons::ui_linkarrow(3) . "Newer</strong>");
        }
        echo "</div></td><td><div class=\"lognavdr\">";
        if ($page - $nlinks > 1) {
            echo "&nbsp;...";
        }
        for ($p = max($page - $nlinks, 1); $p < $page; ++$p) {
            echo "&nbsp;", $lrg->page_link_html($p, $p);
        }
        echo "</div></td><td><div><strong class=\"thispage\">&nbsp;", $page, "&nbsp;</strong></div></td><td><div class=\"lognavd\">";
        for ($p = $page + 1; $p <= $page + $nlinks && $lrg->has_page($p); ++$p) {
            echo $lrg->page_link_html($p, $p), "&nbsp;";
        }
        if ($lrg->has_page($page + $nlinks + 1)) {
            echo "...&nbsp;";
        }
        echo "</div></td><td><div class=\"lognavx\">";
        if ($lrg->has_page($page + 1)) {
            echo $lrg->page_link_html($page + 1, "<strong>Older" . Icons::ui_linkarrow(1) . "</strong>");
        }
        echo "</div></td><td><div class=\"lognavd\">";
        if ($lrg->has_page($page + $nlinks + 1)) {
            echo "&nbsp;&nbsp;|&nbsp; ", $lrg->page_link_html("earliest", "<strong>Oldest</strong>");
        }
        echo "</div></td></tr></table>";
    }
    echo "<hr class=\"g\">\n";
}

// render rows
$user_html = [];

/** @param Contact $user */
function set_user_html($user, $qreq_n) {
    global $Conf, $Me, $user_html;
    if (($pc = $Conf->pc_member_by_id($user->contactId))) {
        $user = $pc;
    }
    if ($user->disabled === "deleted") {
        $t = '<del>' . $user->name_h(NAME_E) . '</del>';
    } else {
        $t = $user->name_h(NAME_P);
    }
    $dt = null;
    if (($viewable = $user->viewable_tags($Me))) {
        $dt = $Conf->tags();
        if (($colors = $dt->color_classes($viewable))) {
            $t = '<span class="' . $colors . ' taghh">' . $t . '</span>';
        }
    }
    $t = '<a href="' . $Conf->hoturl("log", ["q" => "", "u" => $user->email, "n" => $qreq_n]) . '">' . $t . '</a>';
    if ($dt && $dt->has_decoration) {
        $tagger = new Tagger($Me);
        $t .= $tagger->unparse_decoration_html($viewable, Tagger::DECOR_USER);
    }
    $roles = 0;
    if (isset($user->roles) && ($user->roles & Contact::ROLE_PCLIKE)) {
        $roles = $user->viewable_pc_roles($Me);
    }
    if (!($roles & Contact::ROLE_PCLIKE)) {
        $t .= ' &lt;' . htmlspecialchars($user->email) . '&gt;';
    }
    if ($roles !== 0 && ($rolet = Contact::role_html_for($roles))) {
        $t .= " $rolet";
    }
    $user_html[$user->contactId] = $t;
    return $t;
}

/** @param list<Contact> $users */
function render_users($users, $via) {
    global $Conf, $Qreq, $Me, $user_html;
    if (empty($users) && $via < 0) {
        return "<i>via author link</i>";
    }
    $all_pc = true;
    $ts = [];
    $last_user = null;
    usort($users, $Conf->user_comparator());
    foreach ($users as $user) {
        if ($user === $last_user) {
            continue;
        }
        if ($all_pc
            && (!isset($user->roles) || !($user->roles & Contact::ROLE_PCLIKE))) {
            $all_pc = false;
        }
        if ($user->disabled === "deleted") {
            if ($user->email) {
                $t = '<del>' . $user->name_h(NAME_E) . '</del>';
            } else {
                $t = '<del>[deleted user ' . $user->contactId . ']</del>';
            }
        } else {
            if (isset($user_html[$user->contactId])) {
                $t = $user_html[$user->contactId];
            } else {
                $t = set_user_html($user, $Qreq->n);
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
            . '<span class="fn"><a href="" class="ui js-foldup qq">'
            . sprintf($Conf->_($fmt, count($ts)), count($ts))
            . '</a></span><span class="fx">' . join(", ", $ts)
            . '</span></div>';
    }
}

$Conf->header("Log", "actionlog");

$trs = [];
$has_dest_user = false;
foreach ($lrg->page_rows($page) as $row) {
    $t = ['<td class="pl pl_logtime">' . $Conf->unparse_time((int) $row->timestamp) . '</td>'];

    $xusers = $lrg->users_for($row, "contactId");
    $xdest_users = $lrg->users_for($row, "destContactId");
    $via = $row->trueContactId;

    if ($xdest_users && $xusers != $xdest_users) {
        $t[] = '<td class="pl pl_logname">' . render_users($xusers, $via) . '</td>'
            . '<td class="pl pl_logname">' . render_users($xdest_users, false) . '</td>';
        $has_dest_user = true;
    } else {
        $t[] = '<td class="pl pl_logname" colspan="2">' . render_users($xusers, $via) . '</td>';
    }

    // XXX users that aren't in contactId slot
    // if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
    //     $t .= htmlspecialchars($m[2]);
    //     $act = $m[1] . $m[3];
    // } else
    //     $t .= "[None]";

    $act = $lrg->cleaned_action($row);
    $at = "";
    if (strpos($act, "eview ") !== false
        && preg_match('/\A(.* |)([Rr]eview )(\d+)( .*|)\z/', $act, $m)) {
        $at = htmlspecialchars($m[1])
            . Ht::link($m[2] . $m[3], $Conf->hoturl("review", ["p" => $row->paperId, "r" => $m[3]]))
            . "</a>";
        $act = $m[4];
    } else if (substr($act, 0, 7) === "Comment"
               && preg_match('/\AComment (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . $Conf->hoturl("paper", "p={$row->paperId}#cid{$m[1]}") . "\">Comment " . $m[1] . "</a>";
        $act = $m[2];
    } else if (substr($act, 0, 8) === "Response"
               && preg_match('/\AResponse (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . $Conf->hoturl("paper", "p={$row->paperId}#cid{$m[1]}") . "\">Response " . $m[1] . "</a>";
        $act = $m[2];
    } else if (strpos($act, " mail ") !== false
               && preg_match('/\A(Sending|Sent|Account was sent) mail #(\d+)(.*)\z/s', $act, $m)) {
        $at = $m[1] . " <a href=\"" . $Conf->hoturl("mail", "fromlog=$m[2]") . "\">mail #$m[2]</a>";
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
                . $Conf->hoturl("search", ["q" => substr($word, 1, $hash - 1)])
                . '">' . htmlspecialchars(substr($word, 1, $hash - 1))
                . '</a>' . substr($word, $hash);
        }
    } else if ($row->paperId > 0
               && (substr($act, 0, 8) === "Updated "
                   || substr($act, 0, 10) === "Submitted "
                   || substr($act, 0, 11) === "Registered ")
               && preg_match('/\A(\S+(?: final)?)(.*)\z/', $act, $m)
               && preg_match('/\A(.* )(final|submission)((?:,| |\z).*)\z/', $m[2], $mm)) {
        $at = $m[1] . $mm[1] . "<a href=\"" . hoturl("doc", "p={$row->paperId}&amp;dt={$mm[2]}&amp;at={$row->timestamp}") . "\">{$mm[2]}</a>";
        $act = $mm[3];
    }
    $at .= htmlspecialchars($act);
    if (($pids = $lrg->paper_ids($row))) {
        if (count($pids) === 1)
            $at .= ' (<a class="track" href="' . hoturl("paper", "p=" . $pids[0]) . '">paper ' . $pids[0] . "</a>)";
        else {
            $at .= ' (<a href="' . hoturl("search", "t=all&amp;q=" . join("+", $pids)) . '">papers</a>';
            foreach ($pids as $i => $p)
                $at .= ($i ? ', ' : ' ') . '<a class="track" href="' . hoturl("paper", "p=" . $p) . '">' . $p . '</a>';
            $at .= ')';
        }
    }
    $t[] = '<td class="pl pl_logaction">' . $at . '</td>';
    $trs[] = '    <tr class="plnx k' . (count($trs) % 2) . '">' . join("", $t) . "</tr>\n";
}

if (!$Me->privChair || !empty($exclude_pids)) {
    echo '<div class="msgs-wide">';
    if (!$Me->privChair) {
        $Conf->msg("Only showing your actions and entries for papers you administer.", "xinfo");
    } else if (!empty($exclude_pids)
               && (!$include_pids || array_intersect_key($include_pids, $exclude_pids))
               && array_keys($exclude_pids) != array_keys($Me->hidden_papers ? : [])) {
        $req = [];
        foreach (["q", "p", "u", "n"] as $k) {
            if ($Qreq->$k !== "")
                $req[$k] = $Qreq->$k;
        }
        $req["page"] = $page;
        if ($page > 1 && $lrg->page_delta() > 0) {
            $req["offset"] = $lrg->page_delta();
        }
        if ($Qreq->forceShow) {
            $Conf->msg("Showing all entries. (" . Ht::link("Unprivileged view", $Conf->selfurl($Qreq, $req + ["forceShow" => null])) . ")", "xinfo");
        } else {
            $Conf->msg("Not showing entries for " . Ht::link("conflicted administered papers", hoturl("search", "q=" . join("+", array_keys($exclude_pids)))) . ".", "xinfo");
        }
    }
    echo '</div>';
}

searchbar($lrg, $page);
if (!empty($trs)) {
    echo "<table class=\"pltable pltable-fullw pltable-log\">\n",
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

$Conf->footer();
