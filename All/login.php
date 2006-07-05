<?php 
require_once('../Code/confHeader.inc');
//
// If they're here, they don't know
// this information.
//
if (isset($_SESSION['Me']))
    $_SESSION['Me']->invalidate();

$Conf->header(IsSet($LoginType) ? $LoginType : "Login");

$Conf->infoMsg("Log in to the conference management system here.
You'll use the same account information
throughout the paper evaluation process, whether you are 
submitting a paper, co-authoring a paper, reviewing papers, or 
a member of the program committee."); ?>

<form class='login' method='post' action='authAccount.php'>
<table class='form'>
<tr>
  <td class='form_caption'>Email:</td>
  <td class='form_entry'><input type='text' name='loginEmail' size='50'
    <?php if (isset($_REQUEST["loginEmail"])) echo "value=\"", htmlspecialchars($_REQUEST["loginEmail"]), "\" "; ?>
  /></td>
</tr>

<tr>
  <td class='form_caption'>Password:</td>
  <td class='form_entry'><input type='password' name='password' size='50' /></td>
</tr>

<tr><td></td>
  <td><input class='button_default' type='submit' value='Login' name='login' /></td>
</tr>
  
<tr><td></td>
  <td>
    <input class='button' type='submit' value='Mail me my password' name='forgot' />
    <input class='button' type='submit' value='Create new account' name='register' />
  </td>
</tr>

</table>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
