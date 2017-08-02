<?php
// log.php -- HotCRP action log
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

list($DEFAULT_COUNT, $MAX_COUNT) = array(50, 200);

$page = req("page");
if ($page === "earliest")
    $page = false;
else {
    $page = cvtint($page, -1);
    if ($page <= 0)
        $page = 1;
}

if (($count = cvtint(@$_REQUEST["n"], -1)) <= 0)
    $count = $DEFAULT_COUNT;
$count = min($count, $MAX_COUNT);

$nlinks = 4;

$Conf->header("Log", "actionlog", actionBar());

$wheres = array();
$Eclass["q"] = $Eclass["pap"] = $Eclass["acct"] = $Eclass["n"] = $Eclass["date"] = "";

$_REQUEST["q"] = trim(defval($_REQUEST, "q", ""));
$_REQUEST["pap"] = trim(defval($_REQUEST, "pap", ""));
$_REQUEST["acct"] = trim(defval($_REQUEST, "acct", ""));
$_REQUEST["n"] = trim(defval($_REQUEST, "n", "$DEFAULT_COUNT"));
$_REQUEST["date"] = trim(defval($_REQUEST, "date", "now"));

if ($_REQUEST["pap"]) {
    $Search = new PaperSearch($Me, array("t" => "all", "q" => $_REQUEST["pap"],
                                         "allow_deleted" => true));
    if (count($Search->warnings))
        $Conf->warnMsg(join("<br />\n", $Search->warnings));
    $pl = $Search->paperList();
    if (count($pl)) {
        $where = array();
        foreach ($pl as $p) {
            $where[] = "paperId=$p";
            $where[] = "action like '%(papers% $p,%'";
            $where[] = "action like '%(papers% $p)%'";
        }
        $wheres[] = "(" . join(" or ", $where) . ")";
    } else {
        if (!count($Search->warnings))
            $Conf->warnMsg("No papers match that search.");
        $wheres[] = "false";
    }
}

if ($_REQUEST["acct"]) {
    $ids = array();
    $accts = $_REQUEST["acct"];
    while (($word = PaperSearch::pop_word($accts, $Conf))) {
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
        $Conf->infoMsg("No accounts match “" . htmlspecialchars($_REQUEST["acct"]) . "”.");
        $wheres[] = "false";
    }
}

if (($str = $_REQUEST["q"])) {
    $where = array();
    while (($str = ltrim($str)) != "") {
        preg_match('/^("[^"]+"?|[^"\s]+)/s', $str, $m);
        $str = substr($str, strlen($m[0]));
        $where[] = "action like " . Dbl::utf8ci("'%" . sqlq_for_like($m[0]) . "%'");
    }
    $wheres[] = "(" . join(" or ", $where) . ")";
}

if (($count = cvtint(@$_REQUEST["n"])) <= 0) {
    Conf::msg_error("\"Show <i>n</i> records\" requires a number greater than 0.");
    $Eclass["n"] = " error";
    $count = $DEFAULT_COUNT;
}

$firstDate = false;
if ($_REQUEST["date"] == "")
    $_REQUEST["date"] = "now";
if ($_REQUEST["date"] != "now" && isset($_REQUEST["search"])) {
    $firstDate = $Conf->parse_time($_REQUEST["date"]);
    if ($firstDate === false) {
        Conf::msg_error("“" . htmlspecialchars($_REQUEST["date"]) . "” is not a valid date.");
        $Eclass["date"] = " error";
    } else if ($firstDate)
        $wheres[] = "time<=from_unixtime($firstDate)";
}

class LogRowGenerator {
    private $conf;
    private $wheres;
    private $page_size;
    private $lower_offset_bound = 0;
    private $upper_offset_bound = INF;
    private $rows_offset;
    private $rows;

    function __construct(Conf $conf, $wheres, $page_size) {
        $this->conf = $conf;
        $this->wheres = $wheres;
        $this->page_size = $page_size;
    }

    private function load_rows($offset, $limit) {
        $q = "select logId, unix_timestamp(time) timestamp, ipaddr, contactId, destContactId, action, paperId from ActionLog";
        if (!empty($this->wheres))
            $q .= " where " . join(" and ", $this->wheres);
        $result = $this->conf->qe_raw($q . " order by logId desc limit $offset,$limit");
        $this->rows = [];
        $this->rows_offset = $offset;
        while ($result && ($row = $result->fetch_object()))
            $this->rows[] = $row;
        if (!empty($this->rows))
            $this->lower_offset_bound = max($this->lower_offset_bound, $this->rows_offset + count($this->rows));
        if (count($this->rows) < $limit)
            $this->upper_offset_bound = min($this->upper_offset_bound, $this->rows_offset + count($this->rows));
        Dbl::free($result);
    }

    function has_page($pageno, $load_npages = null) {
        global $nlinks;
        assert(is_int($pageno) && $pageno >= 1);
        $offset = ($pageno - 1) * $this->page_size;
        if ($offset >= $this->lower_offset_bound && $offset < $this->upper_offset_bound) {
            $limit = $load_npages ? $load_npages * $this->page_size : ($nlinks + 1) * $this->page_size + 1;
            $this->load_rows($offset, $limit);
        }
        return $offset < $this->lower_offset_bound;
    }

    function page_rows($pageno) {
        if ($this->has_page($pageno)) {
            $offset = ($pageno - 1) * $this->page_size;
            return array_slice($this->rows, $offset - $this->rows_offset, $this->page_size);
        } else
            return [];
    }
}

function searchbar(LogRowGenerator $lrg, $page, $count) {
    global $Conf, $Eclass, $nlinks;

    echo Ht::form_div(hoturl("log"), array("method" => "get")), "<table id='searchform'><tr>
  <td class='lxcaption", $Eclass['q'], "'>With <b>any</b> of the words</td>
  <td class='lentry", $Eclass['q'], "'><input type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" /><span class='sep'></span></td>
  <td rowspan='3'>", Ht::submit("search", "Search"), "</td>
</tr><tr>
  <td class='lxcaption", $Eclass['pap'], "'>Concerning paper(s)</td>
  <td class='lentry", $Eclass['pap'], "'><input type='text' size='40' name='pap' value=\"", htmlspecialchars(defval($_REQUEST, "pap", "")), "\" /></td>
</tr><tr>
  <td class='lxcaption", $Eclass['acct'], "'>Concerning account(s)</td>
  <td class='lentry'><input type='text' size='40' name='acct' value=\"", htmlspecialchars(defval($_REQUEST, "acct", "")), "\" /></td>
</tr><tr>
  <td class='lxcaption", $Eclass['n'], "'>Show</td>
  <td class='lentry", $Eclass['n'], "'><input type='text' size='4' name='n' value=\"", htmlspecialchars($_REQUEST["n"]), "\" /> &nbsp;records at a time</td>
</tr><tr>
  <td class='lxcaption", $Eclass['date'], "'>Starting at</td>
  <td class='lentry", $Eclass['date'], "'><input type='text' size='40' name='date' value=\"", htmlspecialchars($_REQUEST["date"]), "\" /></td>
</tr></table></div></form>";

    if ($page > 1 || $lrg->has_page(2)) {
        $urls = array();
        foreach (array("q", "pap", "acct", "n") as $x)
            if ($_REQUEST[$x])
                $urls[] = "$x=" . urlencode($_REQUEST[$x]);
        $url = hoturl("log", join("&amp;", $urls));
        echo "<table class='lognav'><tr><td><div class='lognavdr'>";
        if ($page > 1)
            echo "<a href='$url&amp;page=1'><strong>Newest</strong></a> &nbsp;|&nbsp;&nbsp;";
        echo "</div></td><td><div class='lognavxr'>";
        if ($page > 1)
            echo "<a href='$url&amp;page=", ($page - 1), "'><strong>", Ht::img("_.gif", "<-", array("class" => "prev")), " Newer</strong></a>";
        echo "</div></td><td><div class='lognavdr'>";
        if ($page - $nlinks > 1)
            echo "&nbsp;...";
        for ($p = max($page - $nlinks, 1); $p < $page; ++$p)
            echo "&nbsp;<a href='$url&amp;page=", $p, "'>", $p, "</a>";
        echo "</div></td><td><div><strong class='thispage'>&nbsp;", $page, "&nbsp;</strong></div></td><td><div class='lognavd'>";
        for ($p = $page + 1; $p <= $page + $nlinks && $lrg->has_page($p); ++$p)
            echo "<a href='$url&amp;page=", $p, "'>", $p, "</a>&nbsp;";
        if ($lrg->has_page($page + $nlinks + 1))
            echo "...&nbsp;";
        echo "</div></td><td><div class='lognavx'>";
        if ($lrg->has_page($page + 1))
            echo "<a href='$url&amp;page=", ($page + 1), "'><strong>Older ", Ht::img("_.gif", "->", array("class" => "next")), "</strong></a>";
        echo "</div></td><td><div class='lognavd'>";
        if ($lrg->has_page($page + $nlinks + 1))
            echo "&nbsp;&nbsp;|&nbsp; <a href='$url&amp;page=earliest'><strong>Oldest</strong></a>";
        echo "</div></td></tr></table>";
    }
    echo "<div class='g'></div>\n";
}

$lrg = new LogRowGenerator($Conf, $wheres, $count);

if ($page === false) { // handle `earliest`
    $page = 1;
    while ($lrg->has_page($page + 1, ceil(2000 / $count)))
        ++$page;
}

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
function render_user(Contact $user = null) {
    global $Me;
    if (!$user)
        return "";
    else if (!$user->email && $user->disabled === "deleted")
        return '<del>' . $user->contactId . '</del>';
    else {
        $t = $Me->reviewer_html_for($user);
        if ($user->disabled === "deleted")
            $t = "<del>" . $t . " &lt;" . htmlspecialchars($user->email) . "&gt;</del>";
        else {
            $t = '<a href="' . hoturl("profile", "u=" . urlencode($user->email)) . '">' . $t . '</a>';
            if (!isset($user->roles) || !($user->roles & Contact::ROLE_PCLIKE))
                $t .= ' &lt;' . htmlspecialchars($user->email) . '&gt;';
            if (isset($user->roles) && ($rolet = $user->role_html()))
                $t .= " $rolet";
        }
        return $t;
    }
}

$trs = [];
$has_dest_user = false;
foreach ($visible_rows as $row) {
    $act = $row->action;

    $t = ['<td class="pl pl_time">' . $Conf->unparse_time_short($row->timestamp) . '</td>'];
    $t[] = '<td class="pl pl_ip">' . htmlspecialchars($row->ipaddr) . '</td>';

    $user = $row->contactId ? get($users, $row->contactId) : null;
    $dest_user = $row->destContactId ? get($users, $row->destContactId) : null;
    if (!$user && $dest_user)
        $user = $dest_user;

    $t[] = '<td class="pl pl_name">' . render_user($user) . '</td>';
    if ($dest_user && $user !== $dest_user) {
        $t[] = '<td class="pl pl_name">' . render_user($dest_user) . '</td>';
        $has_dest_user = true;
    } else
        $t[] = '<td></td>';

    // XXX users that aren't in contactId slot
    // if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
    //     $t .= htmlspecialchars($m[2]);
    //     $act = $m[1] . $m[3];
    // } else
    //     $t .= "[None]";

    if (preg_match('/\AReview (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . hoturl("review", "r=$m[1]") . "\">Review " . $m[1] . "</a>";
        $act = $m[2];
    } else if (preg_match('/\AComment (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . hoturl("paper", "p=$row->paperId") . "\">Comment " . $m[1] . "</a>";
        $act = $m[2];
    } else if (preg_match('/\A(Sending|Sent|Account was sent) mail #(\d+)(.*)\z/s', $act, $m)) {
        $at = $m[1] . " <a href=\"" . hoturl("mail", "fromlog=$m[2]") . "\">mail #$m[2]</a>";
        $act = $m[3];
    } else
        $at = "";
    if (preg_match('/\A(.*) \(papers ([\d, ]+)\)?\z/', $act, $m)) {
        $at .= htmlspecialchars($m[1])
            . " (<a href=\"" . hoturl("search", "t=all&amp;q=" . preg_replace('/[\s,]+/', "+", $m[2]))
            . "\">papers</a> "
            . preg_replace('/(\d+)/', "<a href=\"" . hoturl("paper", "p=\$1") . "\">\$1</a>", $m[2])
            . ")";
    } else
        $at .= htmlspecialchars($act);
    if ($row->paperId)
        $at .= " (paper <a href=\"" . hoturl("paper", "p=" . urlencode($row->paperId)) . "\">" . htmlspecialchars($row->paperId) . "</a>)";
    $t[] = '<td class="pl pl_act">' . $at . '</td>';
    $trs[] = '    <tr class="k' . (count($trs) % 2) . '">' . join("", $t) . "</tr>\n";
}

searchbar($lrg, $page, $count);
if (!empty($trs)) {
    echo '<table class="pltable pltable_full">
  <thead><tr class="pl_headrow"><th class="pll pl_time">Time</th><th class="pll pl_ip">IP</th><th class="pll pl_name">User</th>';
    if ($has_dest_user)
        echo '<th class="pll pl_name">Affected user</th>';
    else
        echo '<th></th>';
    echo '<th class="pll pl_act">Action</th></tr></thead>',
        "\n  <tbody class=\"pltable\">\n",
        join("", $trs),
        "  </tbody>\n</table>\n";
} else
    echo "No records\n";

$Conf->footer();
