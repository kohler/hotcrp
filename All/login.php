<?php 
include('../Code/confHeader.inc');
//
// If they're here, they don't know
// this information.
//
if (IsSet($_SESSION['Me'])) {
    $_SESSION['Me'] -> invalidate();
}
?>

<html>
<?php  $Conf->header("Login") ?>
<body>

<?php 
$Conf->infoMsg("
  To use the conference submission system, you must register an 
  account. This same account information will be used to identify 
   you throughout the paper evaluation process, whether you are simply 
   submitting a paper, co-authoring a paper, reviewing papers or 
   are a member of the program committee. 
   <br> <br> 
   To get started with the system, please login or register
"); ?>


  <form method="POST" action="authAccount.php">
  <table border="0" bgcolor=<?php echo $Conf->bgOne?> align=center width=75%>
  <tr> <td>
    <tr><td colspan=2>
          <table border="1" width="83%">
            <tr>
              <td width="14%">Email:</td>
              <td width="86%"><input type="text" name="loginEmail" size="50"></td>
            </tr>
            <tr>
              <td width="14%">Password:</td>
              <td width="86%"><input type="password" name="password" size="50"></td>
            </tr>
          </table>
      </td>
    </tr>
    <tr> <td> <b> Registered? Enter email and password, then click </b> </td>
         <td> <input type="submit" value="Login" name="login"> </td>
    </tr>
    <tr> <td> <b> Don't know your password? Enter email and click </b> </td>
	     <td> <input type="submit" value="Mail me my password" name="forgot"> </td>
    </tr>
  </table>
  </form>
  <br>

  <table border="0" bgcolor=<?php echo $Conf->bgTwo?> align=center width=75%>
  <tr> <td align=center>
     <b> Don't have an account? </b>
     <?php $Conf->textButton("Click here to create an account", "register.php")?>
  </td>
  </tr>
  </table>
</body>
</html>
