<head>
<SCRIPT TYPE="text/javascript">
<!--
function targetopener(mylink, closeme, closeonly)
{
  if (! (window.focus && window.opener))return true;
  window.opener.focus();
  if (! closeonly)window.opener.location.href=mylink.href;
  if (closeme)window.close();
  return false;
}
//-->
</SCRIPT>


<body>
<?php 
echo $popupMessage;
$here=substr($_SERVER[SCRIPT_FILENAME],0,strrpos($_SERVER[SCRIPT_FILENAME],'/'));
if ( file_exists($here . "/Code/header.inc")) {
  print "<p> I am in index </p>\n";
} else if ( file_exists($here . "/../Code/header.inc")) {
  print "<p> I am in subdir </p>\n";
  $here=substr($here,0,strrpos($here,'/'));
}
print "<p> here is $_server[SCRIPT_FILENAME] -> $here ($dot)</p>\n";
?>
<br>
<center>
<form>
<input type=submit
   onClick="return targetopener(this,true,true)"
   value="Close"
>
</center>
</body>
</html>
