<?php
$testCookieStatus = 0;
if (isset($myTstCky) && ($myTstCky == "ChocChip")) {
     $testCookieStatus = 1;
}
if (!isset($_GET[CCHK])) {
  setcookie("myTstCky", "ChocChip");
  header("Location: " . $_SERVER[PHP_SELF] . "?CCHK=1");
  exit;
}
?>

<SCRIPT TYPE="text/javascript">
<!--
function popup(mylink, windowname)
{
  if (! window.focus)return true;
  var href;
  if (typeof(mylink) == 'string')
  href=mylink;
else
  href=mylink.href;
  window.open(href, windowname, 'width=400,height=200,left=200,top=200,scrollbars=no');
  return false;

}
//-->
</SCRIPT>

<html>
<head><title>Cookie Check</title></head>
<body bgcolor="#FFFFFF" text="#000000">
Cookie Check TestCookieStatus: 
<?php 
	printf ('<font color="#%s">%s</font>;', 
		$testCookieStatus ? "00FF00" : "FF0000",
		$testCookieStatus ? "PASSED!" : "FAILED!"); 
?>
<script type="text/javascript">
<?php
$message=urlencode(
		   "<center> <h1> <big> Warning </big> </h1> </center>"
		   . "<p> You appear to be using netscape 4.7."
		   . "With this browser, resizing the window discards "
		   . "existing form contents. Make certain you click on "
		   . "the \"save review\" button before resizing."
		   . "</p>"
		   );
?>
popup("target.php?popupMessage=<?php echo $message?>", "foo");
</script>
</body>
</html>
