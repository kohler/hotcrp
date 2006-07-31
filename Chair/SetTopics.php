<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$_SESSION["Me"]->goIfNotChair('../');
include('Code.inc');
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
    var but = document.getElementById("rem" + id);
    var chg = document.getElementById("chg" + id);
    var row = document.getElementById("pcrow" + id);
    chg.value = (but.value == "Remove" ? "rem" : "chg");
    var x = row.className.replace(/ *removed/, '');
    row.className = x + (but.value == "Remove" ? " removed" : "");
    but.value = (but.value == "Remove" ? "Do not remove" : "Remove");
    highlightChange(id);
}
// -->
</script>

<?php $Conf->header("Edit Topics") ?>

<?php
if (isset($_REQUEST["update"])) {
    // Add new topics
    if (isset($_REQUEST["topics"])) {
	foreach (preg_split('/[\r\n]+/', $_REQUEST["topics"]) as $t) {
	    if (($t = trim($t)) != '')
		$Conf->qe("insert into TopicArea set topicName='" . mysql_real_escape_string($t) . "'", "while adding new topic");
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
}
?>

<?php
function outrow($id, $name) {
    echo "<tr class='pc' id='pcrow$id'>\n";
    echo "  <td class='pc_name'><input class='textlite' value=\"", htmlentities($name), "\" name='top$id' id='top$id' size='48' onchange='highlightChange(\"$id\")' /></td>\n";
    echo "  <td class='pc_action'><input class='button' type='button' value='Remove' name='rem$id' id='rem$id' onclick='doRemove(\"$id\")' />
    <input type='hidden' value='' name='chg$id' id='chg$id' /></td>
</tr>\n";
}

$query = "select topicId, topicName from TopicArea order by topicName";
$result = $Conf->q($query);
if (DB::isError($result))
    $Conf->errorMsg("Database error: " . $result->getMessage());
 else { ?>
<form method="post" action="<?php echo $_SERVER["PHP_SELF"] ?>" >
<table class="memberlist">

<tr>
  <th>Topic</th>
  <th>Actions</th>
</tr>

<?php
    while ($row = $result->fetchRow())
	outrow($row[0], $row[1]);
?>

<tr>
  <td class='pc_name textarea'><textarea name="topics" cols="48" rows="3" onchange='highlightUpdate()'></textarea></td>
  <td class='pc_action'>Enter new topics here, one per line</td>
</tr>

<tr>
  <td></td>
  <td class='pc_action'><input class='button' type="submit" value="Save Changes" name='update' /></td>
</tr>

</table>
<?php } ?>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
