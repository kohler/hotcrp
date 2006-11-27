<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../');

function olink($key, $string) {
    if (isset($_REQUEST["sort"]) && $_REQUEST["sort"] == $key) {
	if (isset($_REQUEST["dir"]))
	    $dir = ($_REQUEST["dir"] == "desc" ? "asc" : "desc");
	else
	    $dir = "desc";
	return "<a href=\"ViewActionLog.php?sort=$key&amp;dir=$dir\">$string</a>";
    } else
	return "<a href=\"ViewActionLog.php?sort=$key\">$string</a>";
}

function navigationBar() {
    global $start, $count;
    echo "<table align='center' border='1'>
 <tr><td><form method=\"get\" action=\"ViewActionLog.php\">
       <input type='hidden' name='start' value=\"", $start - $count, "\" />
       <input type='submit' name='prev' value='Prev' />
       <input type='text' name='count' value='$count' />
       </form></td>

       <td><big>Records #", $start, " to #", $start + $count - 1, "</big></td>
       <td><form method='get' action='ViewActionLog.php'>
       <input type='hidden' name='start' value='", $start + $count, "' />
       <input type='submit' name='next' value='Next' />
       <input type='text' name='count' value='$count' />
       </form></td></tr></table>\n";
}


if (isset($_REQUEST["sort"])) {
    $okorder = array("ActionLog.logId", "ActionLog.paperId", "ActionLog.ipaddr", "ContactInfo.email");
    if (isset($okorder[$_REQUEST["sort"]]))
	$ORDER = $_REQUEST["sort"];
}
if (!isset($ORDER))
    $ORDER = "order by ActionLog.logId desc";
if (isset($_REQUEST["dir"]) && ($_REQUEST["dir"] == "asc" || $_REQUEST["dir"] == "desc"))
    $ORDER .= " " . $_REQUEST["dir"];

if (($start = cvtint($_REQUEST["start"], -1)) < 0)
    $start = 0;
if (($count = cvtint($_REQUEST["count"], -1)) <= 0)
    $count = 25;

$Conf->header("Conference Log");

navigationBar();

echo "<table border='1'><tr>
  <th width='5%'>", olink("ActionLog.logId", "#"), "</th>
  <th width='5%'>", olink("ActionLog.paperId", "Paper"), "</th>
  <th width='10%'>Time</th>
  <th width='10%'>", olink("ActionLog.ipaddr", "IP"), "</th>
  <th>", olink("ContactInfo.email", "Email"), "<br/>Action</th>
</tr>\n";

$query="select ActionLog.logId, unix_timestamp(ActionLog.time), "
. " ActionLog.ipaddr, ActionLog.contactId, ActionLog.action, "
. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ActionLog.paperId "
. " from ActionLog join ContactInfo using (contactId) $ORDER limit $start,$count";

$result = $Conf->q($query);

if (MDB2::isError($result)) {
    $Conf->errorMsg("Query failed" . $result->getMessage());
    $Conf->errorMsg("Query is $query");
} else {
    while($row = $result->fetchRow()) {
	print "<tr>";
	echo "<td>", htmlspecialchars($row[0]), "</td>";
	echo "<td>", htmlspecialchars($row[8] ? $row[8] : ""), "</td>";
	echo "<td>",  date("D M j G:i:s Y", $row[1]), "</td>";
	echo "<td>", htmlspecialchars($row[2]), "</td>";
	echo "<td>", contactHtml($row[5], $row[6], $row[7]), "<br/>", htmlspecialchars($row[4]), "</td>";
	echo "</tr>\n";
    }
}

echo "</table>\n";

navigationBar();

$Conf->footer();

