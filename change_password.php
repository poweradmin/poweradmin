<?php
require_once("inc/i18n.inc.php");
require_once("inc/toolkit.inc.php");

if($_POST["submit"])
{
	if(strlen($_POST["newpass"]) < 8)
	{
		error('Length of the pass should be at least 8 characters.');
	}
	else
	{
		change_user_pass($_POST["currentpass"], $_POST["newpass"], $_POST["newpass2"]);
	}
}

include_once("inc/header.inc.php");
?>
    <h2>Change password</h2>
    <form method="post" action="change_password.php">
     <table border="0" CELLSPACING="4">
      <tr>
       <td class="n"><? echo _('Current password'); ?>:</td>
       <td class="n"><input type="password" class="input" NAME="currentpass" value=""></td>
      </tr>
      <tr>
       <td class="n"><? echo _('New password'); ?>:</td>
       <td class="n"><input type="password" class="input" NAME="newpass" value=""></td>
      </tr>
      <tr>
       <td class="n"><? echo _('New password'); ?>:</td>
       <td class="n"><input type="password" class="input" NAME="newpass2" value=""></td>
      </tr>
      <tr>
       <td class="n">&nbsp;</td>
       <td class="n">
        <input type="submit" class="button" NAME="submit" value="<? echo _('Change password'); ?>">
       </td>
      </tr>
     </table>
    </form>

<?
include_once("inc/footer.inc.php");
?>
