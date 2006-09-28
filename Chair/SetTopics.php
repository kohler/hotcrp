<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../');
$Conf->header_head("Edit Topics");
?>
<script type="text/javascript"><!--
function highlightChange(id) {
    highlightUpdate();
    if (id) {
	var chg = document.getElementById("chg" + id);
	if (chg.value == "")
	    chg.value = "chg";
    }
}
function doRemove(id) {
    var chg = document.getElementById("chg" + id);
    var row = document.getElementById("pcrow" + id);
    var rem = !row.className.match(/ removed/);
    chg.value = (rem ? "rem" : "chg");
    if (rem)
	row.className = row.className.replace(/unfolded/, 'folded') + " removed";
    else
	row.className = row.className.replace(/\bfolded/, 'unfolded').replace(/\s*removed/, '');
    highlightChange(id);
}
// -->
</script>

<?php

$Conf->header("Edit Topics");

if (isset($_REQUEST["update"])) {
    // Add new topics
    if (isset($_REQUEST["topics"])) {
	foreach (preg_split('/[\r\n]+/', $_REQUEST["topics"]) as $t) {
	    if (($t = trim($t)) != '')
		$Conf->qe("insert into TopicArea (topicName) values ('" . mysql_real_escape_string($t) . "')", "while adding new topic");
	}
    }


    // Now, update existing members
    foreach ($_REQUEST as $key => $value)
	if ($key[0] == 'c' && $key[1] == 'h' && $key[2] == 'g'
	    && ($id = (int) substr($key, 3)) > 0) {
	    // remove?
	    if ($value == "rem") {
		$result = $Conf->qe("delete from TopicArea where topicId='$id'", "while deleting topic");
		if (!DB::isError($result))
		    $Conf->log("Removed a topic", $_SESSION["Me"]);
		continue;
	    }

	    // remove existing PC roles
	    if (isset($_REQUEST["top$id"])) {
		$top = trim($_REQUEST["top$id"]);
		$result = $Conf->qe("update TopicArea set topicName='" . mysql_real_escape_string($top) . "' where topicId=$id", "while updating topic name");
	    }
	}

    // Finally mark the form
    $Conf->qe("delete from ImportantDates where name='reviewFormUpdate'"); 
    $Conf->qe("insert into ImportantDates (name, start) values ('reviewFormUpdate', current_timestamp)"); 
}

function outrow($id, $name) {
    echo "<tr class='pc unfolded' id='pcrow$id'>\n";
    echo "  <td class='pc_name'><input class='textlite' value=\"", htmlentities($name), "\" name='top$id' id='top$id' size='48' onchange='highlightChange(\"$id\")' /></td>\n";
    echo "  <td class='pc_action'><a class='extension' href=\"javascript:doRemove('$id')\">Remove</a><a class='ellipsis' href=\"javascript:doRemove('$id')\">Do not remove</a>
    <input type='hidden' value='' name='chg$id' id='chg$id' /></td>
</tr>\n";
}

$query = "select topicId, topicName from TopicArea order by topicName";
$result = $Conf->q($query);
if (DB::isError($result))
    $Conf->errorMsg("Database error: " . $result->getMessage());
else {
    echo "<hr class='smgap' />

<form method='post' action='SetTopics.php?post=1' enctype='multipart/form-data'>
<table class='memberlist'>

<tr>
  <th>Topic</th>
  <th>Actions</th>
</tr>\n\n";

    while ($row = $result->fetchRow())
	outrow($row[0], $row[1]);

    echo "<tr>
  <td class='pc_name textarea'><textarea name='topics' cols='48' rows='3' onchange='highlightUpdate()'></textarea></td>
  <td class='pc_action'>Enter new topics here, one per line</td>
</tr>

<tr>
  <td></td>
  <td class='pc_action'><input class='button' type='submit' value='Save Changes' name='update' /></td>
</tr>

</table>\n";
}

echo "</form>\n";

$Conf->footer();
