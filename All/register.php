<?php 
include('../Code/confHeader.inc');
//
// If they're here, they don't know
// this information.
//
$_SESSION["Me"] -> invalidate();

?>

<html>
<?php  $Conf->header("Login / Registration") ?>
<body>
<?php $Conf->infoMsg(" If you haven't registered before, please
 enter your contact information below and press &quot;Register&quot;. You
        will have the opportunity to update this information later, but you must
        enter a valid email address since that will be used as your login.
        <br> <br>
        Your password will be send to that address.
");
?>

<form method="POST" action="registerAccount.php">
<b>
<table border="1" align=center bgcolor=<?php echo $Conf->bgTwo?>>
<tr>
                <td width="35%">First Name</td>
                <td width="65%"><input type="text" name="firstName" size="44"></td>
</tr>
<tr>
                <td width="35%">Last Name</td>
                <td width="65%"><input type="text" name="lastName" size="44"></td>
</tr>
<tr>
                <td width="35%">Email</td>
                <td width="65%"><input type="text" name="firstEmail" size="44"></td>
</tr>
<tr>
                <td width="35%">Confirm Email</td>
                <td width="65%"><input type="text" name="secondEmail" size="44"></td>
</tr>
<tr>
                <td width="35%">Affiliation</td>
                <td width="65%"><input type="text" name="affiliation" size="44"></td>
</tr>
<tr>
                <td width="35%">Voice Phone</td>
                <td width="65%"><input type="text" name="phone" size="44"></td>
</tr>
<tr>
                <td width="35%">Fax</td>
                <td width="65%"><input type="text" name="fax" size="44"></td>
</tr>
<tr> <td colspan=2 align=center>
<input type="submit" value="Register" name="register">
</td> </tr>
</table>
</b>
</form>

</body>
<?php  $Conf->footer() ?>
</html>
