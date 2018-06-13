<?php
// log.php -- HotCRP action log
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->is_manager())
    $Me->escape();

$Conf->header("Log", "actionlog");
unset($Qreq->forceShow, $_GET["forceShow"], $_POST["forceShow"]);
$Eclass = [];
$nlinks = 6;

$page = $Qreq->page;
if ($page === "earliest")
    $page = false;
else {
    $page = cvtint($page, -1);
    if ($page <= 0)
        $page = 1;
}

$count = cvtint($Qreq->get("n", 50), -1);
if ($count <= 0) {
    $count = 50;
    Conf::msg_error("\"Show <i>n</i> records\" requires a number greater than 0.");
    $Eclass["n"] = " error";
}
$count = min($count, 200);

$Qreq->q = trim((string) $Qreq->q);
$Qreq->p = trim((string) $Qreq->p);
$Qreq->acct = trim((string) $Qreq->acct);
$Qreq->date = trim($Qreq->get("date", "now"));

$wheres = array();

$include_pids = null;
if ($Qreq->p !== "") {
    $Search = new PaperSearch($Me, ["t" => "all", "q" => $Qreq->p]);
    $Search->set_allow_deleted(true);
    $include_pids = $Search->paper_ids();
    if (!empty($Search->warnings))
        $Conf->warnMsg(join("<br />\n", $Search->warnings));
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
        if (empty($Search->warnings))
            $Conf->warnMsg("No papers match that search.");
        $wheres[] = "false";
    }
}

if ($Qreq->acct !== "") {
    $ids = array();
    $accts = new SearchSplitter($Qreq->acct);
    while (($word = $accts->shift()) !== "") {
        $flags = ContactSearch::F_TAG | ContactSearch::F_USER | ContactSearch::F_ALLOW_DELETED;
        if (substr($word, 0, 1) === "\"") {
            $flags |= ContactSearch::F_QUOTED;
            $word = preg_replace(',(?:\A"|"\z),', "", $word);
        }
        $Search = new ContactSearch($flags, $word, $Me);
        foreach ($Search->ids as $id)
            $ids[$id] = $id;
    }
    $where = array();
    if (count($ids)) {
        $result = $Conf->qe("select contactId, email from ContactInfo where contactId?a union select contactId, email from DeletedContactInfo where contactId?a", $ids, $ids);
        while (($row = edb_row($result))) {
            $where[] = "contactId=$row[0]";
            $where[] = "destContactId=$row[0]";
            $where[] = "action like " . Dbl::utf8ci("'% " . sqlq_for_like($row[1]) . "%'");
        }
    }
    if (count($where))
        $wheres[] = "(" . join(" or ", $where) . ")";
    else {
        $Conf->infoMsg("No users match “" . htmlspecialchars($Qreq->acct) . "”.");
        $wheres[] = "false";
    }
}

if ($Qreq->q !== "") {
    $where = array();
    $str = $Qreq->q;
    while (($str = ltrim($str)) !== "") {
        if ($str[0] === '"')
            preg_match('/\A"([^"]*)"?/', $str, $m);
        else
            preg_match('/\A([^"\s]+)/', $str, $m);
        $str = (string) substr($str, strlen($m[0]));
        if ($m[1] !== "")
            $where[] = "action like " . Dbl::utf8ci("'%" . sqlq_for_like($m[1]) . "%'");
    }
    $wheres[] = "(" . join(" or ", $where) . ")";
}

$first_timestamp = false;
if ($Qreq->date === "")
    $Qreq->date = "now";
if ($Qreq->date !== "now" && isset($Qreq->search)) {
    $first_timestamp = $Conf->parse_time($Qreq->date);
    if ($first_timestamp === false) {
        Conf::msg_error("“" . htmlspecialchars($Qreq->date) . "” is not a valid date.");
        $Eclass["date"] = " error";
    }
}

class LogRowGenerator {
    private $conf;
    private $wheres;
    private $page_size;
    private $delta = 0;
    private $lower_offset_bound;
    private $upper_offset_bound;
    private $rows_offset;
    private $rows_max_offset;
    private $rows;
    private $filter;
    private $page_to_offset;
    private $log_url_base;
    private $explode_mail = false;
    private $mail_stash;

    function __construct(Conf $conf, $wheres, $page_size) {
        $this->conf = $conf;
        $this->wheres = $wheres;
        $this->page_size = $page_size;
        $this->set_filter(null);
    }

    function set_filter($filter) {
        $this->filter = $filter;
        $this->rows = null;
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

    function page_delta() {
        return $this->delta;
    }

    function set_page_delta($delta) {
        assert(is_int($delta) && $delta >= 0 && $delta < $this->page_size);
        $this->delta = $delta;
    }

    private function page_offset($pageno) {
        $offset = ($pageno - 1) * $this->page_size;
        if ($offset > 0 && $this->delta > 0)
            $offset -= $this->page_size - $this->delta;
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
            while ($xpageno > 1 && !isset($this->page_to_offset[$xpageno]))
                --$xpageno;
            $db_offset = $xpageno > 1 ? $this->page_to_offset[$xpageno] : 0;
        }

        $q = "select logId, unix_timestamp(time) timestamp, ipaddr, contactId, destContactId, action, paperId from ActionLog";
        if (!empty($this->wheres))
            $q .= " where " . join(" and ", $this->wheres);
        $q .= " order by logId desc";

        $this->rows = [];
        $this->rows_offset = $offset;
        $n = 0;
        $exhausted = false;
        while ($n < $limit && !$exhausted) {
            $result = $this->conf->qe_raw($q . " limit $db_offset,$limit");
            $first_db_offset = $db_offset;
            while ($result && ($row = $result->fetch_object())) {
                ++$db_offset;
                if (!$this->explode_mail
                    && $this->mail_stash
                    && $this->mail_stash->action === $row->action) {
                    if ($row->destContactId)
                        $this->mail_stash->destContactIdArray[] = $row->destContactId;
                    if ($row->paperId)
                        $this->mail_stash->paperIdArray[] = $row->paperId;
                    continue;
                }
                if (!$this->filter || call_user_func($this->filter, $row)) {
                    $this->rows[] = $row;
                    ++$n;
                    if ($n % $this->page_size === 0)
                        $this->page_to_offset[$pageno + ($n / $this->page_size)] = $db_offset;
                    if (!$this->explode_mail) {
                        if (substr($row->action, 0, 11) === "Sent mail #") {
                            $this->mail_stash = $row;
                            $row->destContactIdArray = $row->paperIdArray = [];
                            if ($row->destContactId) {
                                $row->destContactIdArray[] = $row->destContactId;
                                $row->destContactId = null;
                            }
                            if ($row->paperId) {
                                $row->paperIdArray[] = $row->paperId;
                                $row->paperId = null;
                            }
                        } else
                            $this->mail_stash = null;
                    }
                }
            }
            Dbl::free($result);
            $exhausted = $first_db_offset + $limit !== $db_offset;
        }

        if ($n > 0)
            $this->lower_offset_bound = max($this->lower_offset_bound, $this->rows_offset + $n);
        if ($exhausted)
            $this->upper_offset_bound = min($this->upper_offset_bound, $this->rows_offset + $n);
        $this->rows_max_offset = $exhausted ? INF : $this->rows_offset + $n;
    }

    function has_page($pageno, $load_npages = null) {
        global $nlinks;
        assert(is_int($pageno) && $pageno >= 1);
        $offset = $this->page_offset($pageno);
        if ($offset >= $this->lower_offset_bound && $offset < $this->upper_offset_bound) {
            if ($load_npages)
                $limit = $load_npages * $this->page_size;
            else
                $limit = ($nlinks + 1) * $this->page_size + 30;
            if ($this->filter)
                $limit = max($limit, 2000);
            $this->load_rows($pageno, $limit);
        }
        return $offset < $this->lower_offset_bound;
    }

    function page_after($pageno, $timestamp, $load_npages = null) {
        $rows = $this->page_rows($pageno, $load_npages);
        return !empty($rows) && $rows[count($rows) - 1]->timestamp > $timestamp;
    }

    function page_rows($pageno, $load_npages = null) {
        assert(is_int($pageno) && $pageno >= 1);
        if (!$this->has_page($pageno, $load_npages))
            return [];
        $offset = $this->page_offset($pageno);
        if ($offset < $this->rows_offset || $offset + $this->page_size > $this->rows_max_offset)
            $this->load_rows($pageno, $this->page_size);
        return array_slice($this->rows, $offset - $this->rows_offset, $this->page_size);
    }

    function set_log_url_base($url) {
        $this->log_url_base = $url;
    }

    function page_link_html($pageno, $html) {
        $url = $this->log_url_base;
        if ($pageno !== 1 && $this->delta > 0)
            $url .= "&amp;offset=" . $this->delta;
        return '<a href="' . $url . '&amp;page=' . $pageno . '">' . $html . '</a>';
    }
}

function searchbar(LogRowGenerator $lrg, $page, $count) {
    global $Conf, $Me, $Eclass, $nlinks, $Qreq, $first_timestamp;

    $date = "";
    $dplaceholder = null;
    if (isset($Eclass["date"]))
        $date = $Qreq->date;
    else if ($page === 1)
        $dplaceholder = "now";
    else if (($rows = $lrg->page_rows($page)))
        $dplaceholder = $Conf->unparse_time_short($rows[0]->timestamp);
    else if ($first_timestamp)
        $dplaceholder = $Conf->unparse_time_short($first_timestamp);

    echo Ht::form(hoturl("log"), array("method" => "get"));
    if ($Qreq->forceShow)
        echo Ht::hidden("forceShow", 1);
    echo "<table id=\"searchform\"><tr>
  <td class='lxcaption", get($Eclass, "q", ""), "'>Concerning action(s)</td>
  <td class='lentry", get($Eclass, "q", ""), "'>", Ht::entry("q", $Qreq->q, ["size" => 40]),
        "<span class=\"sep\"></span></td>
  <td rowspan='3'>", Ht::submit("search", "Search"), "</td>
</tr><tr>
  <td class='lxcaption", get($Eclass, "p", ""), "'>Concerning paper(s)</td>
  <td class='lentry", get($Eclass, "p", ""), "'>", Ht::entry("p", $Qreq->p, ["size" => 40]), "</td>
</tr><tr>
  <td class='lxcaption", get($Eclass, "acct", ""), "'>Concerning user(s)</td>
  <td class='lentry", get($Eclass, "acct", ""), "'>", Ht::entry("acct", $Qreq->acct, ["size" => 40]), "</td>
</tr><tr>
  <td class='lxcaption", get($Eclass, "n", ""), "'>Show</td>
  <td class='lentry", get($Eclass, "n", ""), "'>", Ht::entry("n", $count, ["size" => 4]), " &nbsp;records at a time</td>
</tr><tr>
  <td class='lxcaption", get($Eclass, "date"), "'>Starting at</td>
  <td class='lentry", get($Eclass, "date"), "'>", Ht::entry("date", $date, ["size" => 40, "placeholder" => $dplaceholder]), "</td>
</tr>
</table></form>";

    if ($page > 1 || $lrg->has_page(2)) {
        $urls = ["q=" . urlencode($Qreq->q)];
        foreach (array("p", "acct", "n", "forceShow") as $x)
            if ($Qreq[$x])
                $urls[] = "$x=" . urlencode($Qreq[$x]);
        $lrg->set_log_url_base(hoturl("log", join("&amp;", $urls)));
        echo "<table class='lognav'><tr><td><div class='lognavdr'>";
        if ($page > 1)
            echo $lrg->page_link_html(1, "<strong>Newest</strong>"), " &nbsp;|&nbsp;&nbsp;";
        echo "</div></td><td><div class='lognavxr'>";
        if ($page > 1)
            echo $lrg->page_link_html($page - 1, "<strong>" . Icons::ui_linkarrow(3) . "Newer</strong>");
        echo "</div></td><td><div class='lognavdr'>";
        if ($page - $nlinks > 1)
            echo "&nbsp;...";
        for ($p = max($page - $nlinks, 1); $p < $page; ++$p)
            echo "&nbsp;", $lrg->page_link_html($p, $p);
        echo "</div></td><td><div><strong class='thispage'>&nbsp;", $page, "&nbsp;</strong></div></td><td><div class='lognavd'>";
        for ($p = $page + 1; $p <= $page + $nlinks && $lrg->has_page($p); ++$p)
            echo $lrg->page_link_html($p, $p), "&nbsp;";
        if ($lrg->has_page($page + $nlinks + 1))
            echo "...&nbsp;";
        echo "</div></td><td><div class='lognavx'>";
        if ($lrg->has_page($page + 1))
            echo $lrg->page_link_html($page + 1, "<strong>Older" . Icons::ui_linkarrow(1) . "</strong>");
        echo "</div></td><td><div class='lognavd'>";
        if ($lrg->has_page($page + $nlinks + 1))
            echo "&nbsp;&nbsp;|&nbsp; ", $lrg->page_link_html("earliest", "<strong>Oldest</strong>");
        echo "</div></td></tr></table>";
    }
    echo "<div class='g'></div>\n";
}

$lrg = new LogRowGenerator($Conf, $wheres, $count);

$exclude_pids = $Me->hidden_papers ? : [];
if ($Me->privChair && $Conf->has_any_manager()) {
    foreach ($Me->paper_set(["myConflicts" => true]) as $prow)
        if (!$Me->allow_administer($prow))
            $exclude_pids[$prow->paperId] = true;
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
            foreach ($mm[0] as $pid)
                if (isset($pidset[$pid]) === $want) {
                    $pids[] = $pid;
                    $included = $included || isset($includes[$pid]);
                }
            if (empty($pids) || !$included)
                return false;
            else if (count($pids) === 1) {
                $row->action = $m[1];
                $row->paperId = $pids[0];
            } else
                $row->action = $m[1] . " (papers " . join(", ", $pids) . ")";
            return true;
        } else
            return $this->user->privChair;
    }
    function __invoke($row) {
        if ($this->user->hidden_papers !== null
            && !$this->test_pidset($row, $this->user->hidden_papers, false, null))
            return false;
        else if ($row->contactId === $this->user->contactId)
            return true;
        else
            return $this->test_pidset($row, $this->pidset, $this->want, $this->includes);
    }
}

if (!$Me->privChair) {
    $good_pids = [];
    foreach ($Me->paper_set($Conf->check_any_admin_tracks($Me) ? [] : ["myManaged" => true]) as $prow)
        if ($Me->allow_administer($prow))
            $good_pids[$prow->paperId] = true;
    $lrg->set_filter(new LogRowFilter($Me, $good_pids, true, $include_pids));
} else if (!$Qreq->forceShow && !empty($exclude_pids)) {
    $lrg->set_filter(new LogRowFilter($Me, $exclude_pids, false, $include_pids));
}

if ($first_timestamp) {
    $page = 1;
    while ($lrg->page_after($page, $first_timestamp, ceil(2000 / $count)))
        ++$page;
    $delta = 0;
    foreach ($lrg->page_rows($page) as $row)
        if ($row->timestamp > $first_timestamp)
            ++$delta;
    if ($delta) {
        $lrg->set_page_delta($delta);
        ++$page;
    }
} else if ($page === false) { // handle `earliest`
    $page = 1;
    while ($lrg->has_page($page + 1, ceil(2000 / $count)))
        ++$page;
} else if ($Qreq->offset && ($delta = cvtint($Qreq->offset)) >= 0 && $delta < $count)
    $lrg->set_page_delta($delta);


$visible_rows = $lrg->page_rows($page);
$unknown_cids = [];
$users = $Conf->pc_members_and_admins();
foreach ($visible_rows as $row) {
    if ($row->contactId && !isset($users[$row->contactId]))
        $unknown_cids[$row->contactId] = true;
    if ($row->destContactId && !isset($users[$row->destContactId]))
        $unknown_cids[$row->destContactId] = true;
}

// load unknown users
if (!empty($unknown_cids)) {
    $result = $Conf->qe("select contactId, firstName, lastName, email, roles from ContactInfo where contactId?a", array_keys($unknown_cids));
    while (($user = Contact::fetch($result, $Conf))) {
        $users[$user->contactId] = $user;
        unset($unknown_cids[$user->contactId]);
    }
    Dbl::free($result);
    if (!empty($unknown_cids)) {
        foreach ($unknown_cids as $cid => $x) {
            $user = $users[$cid] = new Contact(["contactId" => $cid, "disabled" => true]);
            $user->disabled = "deleted";
        }
        $result = $Conf->qe("select contactId, firstName, lastName, email, 1 disabled from DeletedContactInfo where contactId?a", array_keys($unknown_cids));
        while (($user = Contact::fetch($result, $Conf))) {
            $users[$user->contactId] = $user;
            $user->disabled = "deleted";
        }
        Dbl::free($result);
    }
}

// render rows
function render_users($users) {
    global $Conf, $Me, $count;
    $all_pc = true;
    $ts = [];
    usort($users, "Contact::compare");
    foreach ($users as $user) {
        if ($all_pc && (!isset($user->roles) || !($user->roles & Contact::ROLE_PCLIKE)))
            $all_pc = false;
        if (!$user->email && $user->disabled === "deleted")
            return '<del>[deleted user ' . $user->contactId . ']</del>';
        else {
            $t = $Me->reviewer_html_for($user);
            if ($user->disabled === "deleted")
                $t = "<del>" . $t . " &lt;" . htmlspecialchars($user->email) . "&gt;</del>";
            else {
                $t = '<a href="' . hoturl("log", "q=&amp;acct=" . urlencode($user->email)) . '&amp;n=' . $count . '">' . $t . '</a>';
                if (!isset($user->roles) || !($user->roles & Contact::ROLE_PCLIKE))
                    $t .= ' &lt;' . htmlspecialchars($user->email) . '&gt;';
                if (isset($user->roles) && ($rolet = $user->role_html()))
                    $t .= " $rolet";
            }
            $ts[] = $t;
        }
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

$trs = [];
$has_dest_user = false;
foreach ($visible_rows as $row) {
    $act = $row->action;

    $t = ['<td class="pl pl_logtime">' . $Conf->unparse_time_short($row->timestamp) . '</td>'];

    $xusers = $xdest_users = [];
    if ($row->contactId
        && ($u = get($users, $row->contactId)))
        $xusers[] = $u;
    if ($row->destContactId
        && ($u = get($users, $row->destContactId)))
        $xdest_users[] = $u;
    if (isset($row->destContactIdArray)) {
        foreach ($row->destContactIdArray as $cid) {
            if (($u = get($users, $cid)))
                $xdest_users[] = $u;
        }
    }
    if (empty($xusers) && !empty($xdest_users))
        $xusers[] = $xdest_users[0];

    if ($xdest_users && $xusers != $xdest_users) {
        $t[] = '<td class="pl pl_logname">' . render_users($xusers) . '</td>'
            . '<td class="pl pl_logname">' . render_users($xdest_users) . '</td>';
        $has_dest_user = true;
    } else {
        $t[] = '<td class="pl pl_logname" colspan="2">' . render_users($xusers) . '</td>';
    }

    // XXX users that aren't in contactId slot
    // if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
    //     $t .= htmlspecialchars($m[2]);
    //     $act = $m[1] . $m[3];
    // } else
    //     $t .= "[None]";

    $at = "";
    if (substr($act, 0, 6) === "Review"
        && preg_match('/\AReview (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . hoturl("review", ["p" => $row->paperId, "r" => $m[1]]) . "\">Review " . $m[1] . "</a>";
        $act = $m[2];
    } else if (substr($act, 0, 7) === "Comment"
               && preg_match('/\AComment (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . hoturl("paper", "p=$row->paperId") . "\">Comment " . $m[1] . "</a>";
        $act = $m[2];
    } else if (strpos($act, " mail ") !== false
               && preg_match('/\A(Sending|Sent|Account was sent) mail #(\d+)(.*)\z/s', $act, $m)) {
        $at = $m[1] . " <a href=\"" . hoturl("mail", "fromlog=$m[2]") . "\">mail #$m[2]</a>";
        $act = $m[3];
    } else if (substr($act, 0, 5) === "Tag: ") {
        $at = "Tag: ";
        $act = substr($act, 5);
        while (preg_match('/\A([-+])#([^\s#]*)(#[-+\d.]+ ?| ?)(.*)\z/s', $act, $m)) {
            $at .= $m[1] . "<a href=\"" . hoturl("search", "q=%23" . urlencode($m[2])) . "\">#"
                . htmlspecialchars($m[2]) . "</a>" . htmlspecialchars($m[3]);
            $act = $m[4];
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
    if (isset($row->paperIdArray)) {
        $pids = array_unique($row->paperIdArray);
        if (count($pids) == 1)
            $row->paperId = $pids[0];
        else
            $row->action .= " (papers " . join(", ", $pids) . ")";
    }
    if (preg_match('/\A(.* |)\(papers ([\d, ]+)\)?\z/', $act, $m)) {
        $at .= htmlspecialchars($m[1])
            . " (<a href=\"" . hoturl("search", "t=all&amp;q=" . preg_replace('/[\s,]+/', "+", $m[2]))
            . "\">papers</a> "
            . preg_replace('/(\d+)/', "<a href=\"" . hoturl("paper", "p=\$1") . "\">\$1</a>", $m[2])
            . ")";
    } else
        $at .= htmlspecialchars($act);
    if ($row->paperId)
        $at .= " (<a href=\"" . hoturl("paper", "p=" . urlencode($row->paperId)) . "\">paper " . htmlspecialchars($row->paperId) . "</a>)";
    $t[] = '<td class="pl pl_logaction">' . $at . '</td>';
    $trs[] = '    <tr class="k' . (count($trs) % 2) . '">' . join("", $t) . "</tr>\n";
}

if (!$Me->privChair || !empty($exclude_pids)) {
    echo '<div class="msgs-wide">';
    if (!$Me->privChair)
        $Conf->msg("xinfo", "Only showing your actions and entries for papers you administer.");
    else if (!empty($exclude_pids)
             && (!$include_pids || array_intersect_key($include_pids, $exclude_pids))
             && array_keys($exclude_pids) != array_keys($Me->hidden_papers ? : [])) {
        $req = [];
        foreach (["q", "p", "acct", "n"] as $k)
            if ($Qreq->$k !== "")
                $req[$k] = $Qreq->$k;
        $req["page"] = $page;
        if ($page > 1 && $lrg->page_delta() > 0)
            $req["offset"] = $lrg->page_delta();
        if ($Qreq->forceShow)
            $Conf->msg("xinfo", "Showing all entries. (" . Ht::link("Unprivileged view", selfHref($req + ["forceShow" => null])) . ")");
        else
            $Conf->msg("xinfo", "Not showing entries for " . Ht::link("conflicted administered papers", hoturl("search", "q=" . join("+", array_keys($exclude_pids)))) . ".");
            //" (" . Ht::link("Override conflicts", selfHref($req + ["forceShow" => 1])) . ")");
    }
    echo '</div>';
}

searchbar($lrg, $page, $count);
if (!empty($trs)) {
    echo "<table class=\"pltable pltable_full pltable_log\">\n",
        '  <thead><tr class="pl_headrow">',
        '<th class="pll plh pl_logtime">Time</th>',
        '<th class="pll plh pl_logname">User</th>',
        '<th class="pll plh pl_logname">Affected user</th>',
        '<th class="pll plh pl_logaction">Action</th></tr></thead>',
        "\n  <tbody class=\"pltable\">\n",
        join("", $trs),
        "  </tbody>\n</table>\n";
} else
    echo "No records\n";

$Conf->footer();
