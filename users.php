<?php

require_once("inc/toolkit.inc.php");

if($_POST["submit"]
&& $_POST["username"] != ""
&& $_POST["password"] != "" 
&& $_POST["fullname"] != ""
&& $_POST["email"] != ""
&& $_POST["level"] > 0)
{
	if(substr_count($_POST["username"], " ") == 0)
	{
		add_user($_POST["username"], $_POST["password"], $_POST["fullname"], $_POST["email"], $_POST["level"], $_POST["description"], $_POST["active"]);
        	clean_page($BASE_URL . $BASE_PATH . "users.php");
        }
        else
        {
        	$error = _('Usernames can\'t contain spaces');
        }
}
elseif($_POST["submit"])
{
	$error = _('Please fill in all fields');
}

// Dirty hack, maybe revise?
include_once("inc/header.inc.php");
?>
    <h2><? echo _('User admin'); ?></h2>
<?
if (!level(10)) 
{
	error(ERR_LEVEL_10);
}

if ($error != "") 
{
?>
	<H3><FONT COLOR="red"><? echo _('Error'); ?>: <?= $error ?></FONT></H3><?
}
?>
     <h3><? echo _('Current users (click to edit)'); ?></h3>
<?
$users = show_users('');
?>  
      <p><? echo _('Number of users') ;?>: <? echo count($users); ?>.</p>
      <div class="showmax">
<?
show_pages(count($users),ROWAMOUNT);
?>
      </div> <? // eo div showmax ?>

      <table>
       <tr>
        <td class="n">&nbsp;</td>
        <td class="n"><? echo _('Name'); ?></td>
        <td class="n"><? echo _('Domains'); ?></td>
        <td class="n"><? echo _('Domain list'); ?></td>
        <td class="n"><? echo _('Level'); ?></td>
        <td class="n"><? echo _('Status'); ?></td>
       </tr>
<?
$users = show_users('',ROWSTART,ROWAMOUNT);
foreach ($users as $c)
{
?>
       <tr>
        <td class="n"><a href="delete_user.php?id=<?= $c["id"] ?>"><img src="images/delete.gif" alt="[ <? echo _('Delete user'); ?> ]"></a></td>
        <td class="n"><a href="edit_user.php?id=<?= $c["id"] ?>"><?= $c["fullname"] ?></A> (<?= $c["username"] ?>)</td>
        <td class="n"><?= $c["numdomains"] ?></td>
        <td class="n">
        <?
        $domains = get_domains_from_userid($c["id"]);
        foreach ($domains as $d)
        {
                ?><a href="delete_domain.php?id=<?= $d["id"] ?>"><img src="images/delete.gif" alt="[ <? echo _('Delete domain'); ?> ]"></a>&nbsp;<a href="edit.php?id=<?= $d["id"] ?>"><?= $d["name"] ?></a><br><?
        }
        ?></td>
	<td class="n"><?= $c["level"] ?></td>
	<td class="n"><?= get_status($c["active"]) ?></td>
       </tr><?
        print "\n";
}
?>
      </table>

      <h3><? echo _('Create new user'); ?></h3>
      <form method="post" action="users.php">
       <table>
        <tr>
         <td class="n"><? echo _('User name'); ?>:</td>
         <td class="n"><input type="text" class="input" name="username" value="<? if ($error) print $_POST["username"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><? echo _('Full name'); ?>:</td>
	 <td class="n"><input type="text" class="input" NAME="fullname" VALUE="<? if ($error) print $_POST["fullname"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><? echo _('Password'); ?>:</td>
	 <td class="n"><input type="text" class="input" NAME="password" VALUE="<? if ($error) print $_POST["password"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><? echo _('E-mail'); ?>:</td>
	 <td class="n"><input type="text" class="input" NAME="email" VALUE="<? if ($error) print $_POST["email"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><? echo _('User level'); ?>:</td>
	 <td class="n">
	  <select name="level">
	   <option value="1">1 (<?= leveldescription(1) ?>)</option>
	   <option value="5">5 (<?= leveldescription(5) ?>)</option>
	   <option value="10">10 (<?= leveldescription(10) ?>)</option>
	  </select>
	 </td>
	</tr>
        <tr>
	 <td class="n"><? echo _('Description'); ?>:</td>
	 <td class="n"><textarea rows="6" cols="30" class="inputarea" name="description"><? if ($error) print $_POST["description"]; ?></textarea></td>
	</tr>
	<tr>
	 <td class="n"><? echo _('Active'); ?>:</td>
	 <td class="n"><input type="checkbox" name="active" value="1" checked></td>
	</tr>
	<tr>
	 <td class="n">&nbsp;</td>
	 <td class="n"><input type="submit" class="button" name="submit" value="<? echo _('Add user'); ?>"></td>
	</tr>
       </table>
      </form>
<?
include_once("inc/footer.inc.php");
?>
