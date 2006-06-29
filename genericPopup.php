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
echo $_REQUEST[popupMessage];
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
