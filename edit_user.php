<?php

require_once("inc/toolkit.inc.php");

if($_POST["commit"])
{
	if($_POST["username"] && $_POST["level"] && $_POST["fullname"])
	{
		if(!isset($_POST["active"]))
		{
			$active = 0;
		}
		else
		{
			$active = 1;
		}
		if(edit_user($_POST["number"], $_POST["username"], $_POST["fullname"], $_POST["email"], $_POST["level"], $_POST["description"], $active, $_POST["password"]))
		{
			clean_page($BASE_URL . $BASE_PATH . "users.php");
		}
		else
		{
			error("Error editting user!");
		}
	}
}

include_once("inc/header.inc.php");

if (!level(10))
{
	error("You do not have the required access level.");
}
?>
    <h2><? echo _('Edit user'); ?> "<? echo get_fullname_from_userid($_GET["id"]) ?>"</h2>
<?
$r = array();
$r = get_user_info($_GET["id"]);
?>
    <form method="post">
     <input type="HIDDEN" name="number" value="<? echo $_GET["id"] ?>">
     <table>
      <tr>
       <td class="n"><? echo _('User name'); ?>:</td>
       <td class="n"><input type="text" class="input" name="username" value="<? echo $r["username"]?>"></td>
      </tr>
      <tr>
       <td class="n"><? echo _('Full name'); ?>:</td>
       <td class="n"><input type="text" class="input" name="fullname" value="<? echo $r["fullname"]?>"></td>
      </tr>
      <tr>
       <td class="n"><? echo _('Password'); ?>:</td>
       <td class="n"><input type="password" class="input" name="password" value=""></td>
      </tr>
      <tr>
       <td class="n"><? echo _('E-mail'); ?>:</td>
       <td class="n"><input type="text" class="input" name="email" value="<? echo $r["email"]?>"></td>
      </tr>
      <tr>
       <td class="n"><? echo _('User level'); ?>:</td>
       <td class="n">
        <select name="level">
	 <option value="1" <? if($r["level"] == 1) { echo "selectED"; } ?>>1 (<? echo _('Normal user'); ?>)</option>
	 <option value="5" <? if($r["level"] == 5) { echo "selectED"; } ?>>5 (<? echo _('Administrator'); ?>)</option>
	 <option value="10" <? if($r["level"] == 10) { echo "selectED"; } ?>>10 (<? echo _('Administrator w/ user admin rights'); ?>)</option>
	</select>
       </td>
      </tr>
      <tr>
       <td class="n"><? echo _('Description'); ?>:</td>
       <td class="n">
        <textarea rows="6" cols="30" class="inputarea" name="description"><? echo $r["description"]?></textarea>
       </td>
      </tr>
      <tr>
       <td class="n"><? echo _('Active'); ?>:</td>
       <td class="n"><input type="checkbox" name="active" value="1" <? if($r["active"]) { ?>CHECKED<? } ?>></td>
      </tr>
      <tr>
       <td class="n">&nbsp;</td>
       <td class="n"><input type="submit" class="button" name="commit" value="<? echo _('Commit changes'); ?>"></td>
      </tr>
     </table>
    </form>
<?

include_once("inc/footer.inc.php");

?>
