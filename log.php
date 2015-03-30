<?php
// log.php -- HotCRP action log
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

list($DEFAULT_COUNT, $MAX_COUNT) = array(50, 200);

if (defval($_REQUEST, "page", "") == "earliest")
    $page = false;
else if (($page = cvtint(@$_REQUEST["page"], -1)) <= 0)
    $page = 1;
if (($count = cvtint(@$_REQUEST["n"], -1)) <= 0)
    $count = $DEFAULT_COUNT;
$count = min($count, $MAX_COUNT);
if (($offset = cvtint(@$_REQUEST["offset"], -1)) < 0 || $offset >= $count)
    $offset = 0;
if ($offset == 0 || $page == 1) {
    $start = ($page - 1) * $count;
    $offset = 0;
} else
    $start = ($page - 2) * $count + $offset;
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
    while (($word = PaperSearch::pop_word($accts))) {
        $flags = ContactSearch::F_TAG;
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
        $result = Dbl::qe("select contactId, email from ContactInfo where contactId ?a", $ids);
        while (($row = edb_row($result))) {
            $where[] = "contactId=$row[0]";
            $where[] = "action like '%" . sqlq_for_like($row[1]) . "%' collate utf8_general_ci";
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
        $where[] = "action like '%" . sqlq_for_like($m[0]) . "%' collate utf8_general_ci";
    }
    $wheres[] = "(" . join(" or ", $where) . ")";
}

if (($count = cvtint(@$_REQUEST["n"])) <= 0) {
    $Conf->errorMsg("\"Show <i>n</i> records\" requires a number greater than 0.");
    $Eclass["n"] = " error";
    $count = $DEFAULT_COUNT;
}

$firstDate = false;
if ($_REQUEST["date"] == "")
    $_REQUEST["date"] = "now";
if ($_REQUEST["date"] != "now" && isset($_REQUEST["search"]))
    if (($firstDate = $Conf->parse_time($_REQUEST["date"])) === false) {
        $Conf->errorMsg("“" . htmlspecialchars($_REQUEST["date"]) . "” is not a valid date.");
        $Eclass["date"] = " error";
    }

function searchbar() {
    global $Conf, $Eclass, $page, $start, $count, $nrows, $maxNrows, $nlinks, $offset;

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

    if ($nrows > $count || $page > 1) {
        $urls = array();
        $_REQUEST["offset"] = $offset;
        foreach (array("q", "pap", "acct", "n", "offset") as $x)
            if ($_REQUEST[$x])
                $urls[] = "$x=" . urlencode($_REQUEST[$x]);
        $url = hoturl("log", join("&amp;", $urls));
        echo "<table class='lognav'><tr><td id='newest'><div>";
        if ($page > 1)
            echo "<a href='$url&amp;page=1'><strong>Newest</strong></a> &nbsp;|&nbsp;&nbsp;";
        echo "</div></td><td id='newer'><div>";
        if ($page > 1)
            echo "<a href='$url&amp;page=", ($page - 1), "'><strong>", Ht::img("_.gif", "<-", array("class" => "prev")), " Newer</strong></a>";
        echo "</div></td><td id='newnum'><div>";
        if ($page - $nlinks > 1)
            echo "&nbsp;...";
        for ($p = max($page - $nlinks - 1, 0); $p + 1 < $page; $p++)
            echo "&nbsp;<a href='$url&amp;page=", ($p + 1), "'>", ($p + 1), "</a>";
        echo "</div></td><td id='thisnum'><div><strong class='thispage'>&nbsp;", $page, "&nbsp;</strong></div></td><td id='oldnum'><div>";
        $o = ($offset ? $offset - $count : 0);
        for ($p = $page; $p * $count + $o < $start + min($nlinks * $count + 1, $nrows); $p++)
            echo "<a href='$url&amp;page=", ($p + 1), "'>", ($p + 1), "</a>&nbsp;";
        if ($nrows == $maxNrows)
            echo "...&nbsp;";
        echo "</div></td><td id='older'><div>";
        if ($nrows > $count)
            echo "<a href='$url&amp;page=", ($page + 1), "'><strong>Older ", Ht::img("_.gif", "->", array("class" => "next")), "</strong></a>";
        echo "</div></td><td id='oldest'><div>";
        if ($nrows > $count)
            echo "&nbsp;&nbsp;|&nbsp; <a href='$url&amp;page=earliest'><strong>Oldest</strong></a>";
        echo "</div></td></tr></table>";
    }
    echo "<div class='g'></div>\n";
}


$query = "select logId, unix_timestamp(time) as timestamp, "
    . " ipaddr, contactId, action, firstName, lastName, email, paperId "
    . " from ActionLog left join ContactInfo using (contactId)";
if (count($wheres))
    $query .= " where " . join(" and ", $wheres);
$query .= " order by logId desc";
if (!$firstDate && $page !== false) {
    $maxNrows = $nlinks * $count + 1;
    $query .= " limit $start,$maxNrows";
}

//$Conf->infoMsg(nl2br(htmlspecialchars($query)));
$result = $Conf->qe($query);
$nrows = edb_nrows($result);
if ($firstDate || $page === false)
    $maxNrows = $nrows;

$n = 0;
$trs = array();
while (($row = edb_orow($result)) && ($n < $count || $page === false)) {
    if ($firstDate && $row->timestamp > $firstDate) {
        $start++;
        $nrows--;
        continue;
    } else if ($page === false && ($n % $count != 0 || $n + $count < $nrows)) {
        $n++;
        continue;
    } else if ($page === false) {
        $start = $n;
        $page = ($n / $count) + 1;
        $nrows -= $n;
        $maxNrows -= $n - 1;
        $n = 0;
    }

    $n++;
    if ($n == 1) {
        if ($start != 0 && !$firstDate)
            $_REQUEST["date"] = $Conf->printableTimeShort($row->timestamp);
        else if ($firstDate) {
            $offset = $start % $count;
            $page = (int) ($start / $count) + ($offset ? 2 : 1);
            $nrows = min($nlinks * $count + 1, $nrows);
            $maxNrows = min($nlinks * $count + 1, $maxNrows);
        }
    }

    $act = $row->action;
    $t = "<td class='pl_id'>" . htmlspecialchars($row->logId) . "</td>"
        . "<td class='al_time'>" . $Conf->printableTimeShort($row->timestamp) . "</td>"
        . "<td class='al_ip'>" . htmlspecialchars($row->ipaddr) . "</td>"
        . "<td class='pl_name'>";
    if ($row->email) {
        $t .= "<a href=\"" . hoturl("profile", "u=" . urlencode($row->email)) . "\">"
            . Text::user_html_nolink($row) . "</a>";
        if ($row->contactId !== $Me->contactId)
            $t .= "&nbsp;" . actas_link($row);
    } else if ($row->firstName || $row->lastName)
        $t .= Text::user_html_nolink($row);
    else if ($row->contactId)
        $t .= "[Deleted account $row->contactId]";
    else {
        if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
            $t .= htmlspecialchars($m[2]);
            $act = $m[1] . $m[3];
        } else
            $t .= "[None]";
    }
    $t .= "</td><td class=\"al_act\">";

    if (preg_match('/^Review (\d+)/', $act, $m)) {
        $t .= "<a href=\"" . hoturl("review", "r=$m[1]") . "\">Review " . $m[1] . "</a>";
        $act = substr($act, strlen($m[0]));
    } else if (preg_match('/^Comment (\d+)/', $act, $m)) {
        $t .= "<a href=\"" . hoturl("paper", "p=$row->paperId#comment$m[1]") . "\">Comment " . $m[1] . "</a>";
        $act = substr($act, strlen($m[0]));
    } else if (preg_match('/^(Sending|Account was sent) mail #(\d+)/', $act, $m)) {
        $t .= $m[1] . " <a href=\"" . hoturl("mail", "fromlog=$m[2]") . "\">mail #$m[2]</a>";
        $act = substr($act, strlen($m[0]));
    }
    if (preg_match('/ \(papers ([\d, ]+)\)?$/', $act, $m)) {
        $t .= htmlspecialchars(substr($act, 0, strlen($act) - strlen($m[0])))
            . " (<a href=\"" . hoturl("search", "t=all&amp;q=" . preg_replace('/[\s,]+/', "+", $m[1]))
            . "\">papers</a> "
            . preg_replace('/(\d+)/', "<a href=\"" . hoturl("paper", "p=\$1") . "\">\$1</a>", $m[1])
            . ")";
    } else
        $t .= htmlspecialchars($act);

    if ($row->paperId)
        $t .= " (paper <a href=\"" . hoturl("paper", "p=" . urlencode($row->paperId)) . "\">" . htmlspecialchars($row->paperId) . "</a>)";
    $trs[] = $t . "</td>";
}

searchbar();
if (count($trs)) {
    echo "<table class='altable'>
  <col width='0*' /><col width='0*' /><col width='0*' /><col width='0*' />
  <thead><tr class='al_headrow'>
    <th class='pl_id'>#</th>
    <th class='al_time'>Time</th>
    <th class='al_ip'>IP</th>
    <th class='pl_name'>Account</th>
    <th class='al_act'>Action</th>
  </tr></thead>
  <tbody>\n";
    for ($i = 0; $i < count($trs); ++$i)
        echo "    <tr class='k", $i % 2, " al'>", $trs[$i], "</tr>\n";
    echo "</tbody></table>\n";
} else
    echo "No records\n";

$Conf->footer();
