<?php
require_once("inc/i18n.inc.php");
require_once("inc/toolkit.inc.php");

if (!level(5))
{
        error(ERR_LEVEL_5);

}

if ($_POST["submit"])
{
     $domain = trim($_POST["domain"]);
     $owner = $_POST["owner"];
     $slave_master = $_POST["slave_master"];
     $dom_type = "SLAVE";
     if (!$error)
     {
             if (!is_valid_domain($domain))
             {
                     $error = "Zone name is invalid!";
             }
             elseif (domain_exists($domain))
             {
                     $error = "Zone already exists!";
             }
             elseif (!is_valid_ip($slave_master))
             {
                     $error = "IP of master NS for slave zone is not valid!";
             }
             else
             {
                     if(add_domain($domain, $owner, $webip, $mailip, $empty, $dom_type, $slave_master))
		     {
                                $success = _('Successfully added slave zone.');
		     }
             }
     }
}

include_once("inc/header.inc.php");

	if ($error != "")
	{
	        ?><div class="error"><? echo _('Error'); ?>: <? echo $error; ?></div><?
	}
	elseif ($success != "")
	{
		?><div class="success"><? echo $success; ?></div><?
	}
	
	$users = show_users();
	
	?>
	    <h2><? echo _('Add new slave zone'); ?></h2>
	    <form method="post" action="add_zone_slave.php">
	     <table>
	      <tr>
	       <td class="n"><? echo _('Domain name'); ?>:</td>
	       <td class="n">
	        <input type="text" class="input" name="domain" value="<? if ($error) print $_POST["domain"]; ?>">
	       </td>
	      </tr>
	      <tr>
	       <td class="n"><? echo _('IP of master NS'); ?>:</td>
	       <td class="n">
	        <input type="text" class="input" name="slave_master" value="<? if ($error) print $_POST["slave_master"]; ?>">
	       </td>
	      </tr>
	      <tr>
	       <td class="n"><? echo _('Owner'); ?>:</td>
	       <td class="n">
	        <select name="owner">
	         <? 
	         foreach ($users as $u)
	         {
	           ?><option value="<?= $u['id'] ?>"><?= $u['fullname'] ?></option><?
	         } 
	        ?>
	        </select>
	       </td>
	      </tr>
	      <tr>
	       <td class="n">&nbsp;</td>
	       <td class="n">
	        <input type="submit" class="button" name="submit" value="<? echo _('Add domain'); ?>">
	       </td>
	      </tr>
	     </table>
	    </form>
<?
include_once("inc/footer.inc.php");
?>
