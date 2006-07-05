<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$Me = $_SESSION["Me"];

$Conf->header("List Papers");

$pq = "select Paper.* from Paper";
if ($Me->amAssistant()) {
} else if ($Me->isPC) {
    $pq_where[] = "acknowledged>0";
} else {
    $pq .= ", Roles";
    $pq_where[] = "(Roles.contactId=$Me->contactId and Roles.role=" . ROLE_AUTHOR . " and Paper.paperId=Roles.paperId)";
}

if (isset($pq_where))
    $pq .= " where " . join(" and ", $pq_where);
$result = $Conf->qe($pq, "while listing papers");
if (DB::isError($result))
    /* do nothing */;
else if ($result->numRows() == 0)
    $Conf->infoMsg("No papers to list.");
else {
    echo "<table>\n";
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
	echo "<tr>\n";
	echo "  <td>[#", $row['paperId'], "] ", htmlspecialchars($row['title']), "</td>\n";
	echo "  <td>", paperStatus($row['paperId'], $row), "</td>\n";
	if ($row['acknowledged'] > 0)
	    echo "  <td>", paperDownload($row['paperId'], $row), "</td>\n";
	echo "</tr>\n";
    }
    echo "</table>\n";
 }
?>


</div>
<?php $Conf->footer() ?>
</body>
</html>
