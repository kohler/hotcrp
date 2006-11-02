<?php 
require_once('Code/header.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair("index.php");


// download blank review form action
if (isset($_REQUEST['pcconflicts'])) {
    $result = $Conf->qe("select Paper.paperId, title, group_concat(email)
	from Paper
	left join (select paperId, contactId
			from PaperConflict join PCMember using (contactId))
			as PCConflict on (PCConflict.paperId=Paper.paperId)
	left join ContactInfo on (ContactInfo.contactId=PCConflict.contactId)
	where Paper.timeSubmitted>0
	group by Paper.paperId
	order by Paper.paperId", "while getting PC conflicts");
    if (!DB::isError($result)) {
	$text = "#paperId\ttitle\tPC conflicts\n";
	while (($row = $result->fetchRow()))
	    $text .= $row[0] . "\t" . $row[1] . "\t" . ($row[2] ? $row[2] : "-") . "\n";
	downloadText($text, $Opt['downloadPrefix'] . "pcconflicts.txt", "PC conflicts");
	exit;
    }
}


$Conf->header("Text Downloads", 'textdownloads');

$Conf->infoMsg("Download text files with information from the database here.");

echo "<ul>
  <li><a href='minshall.php?pcconflicts=1'>PC conflicts for submitted papers</a></li>
</ul>\n";

$Conf->footer();
