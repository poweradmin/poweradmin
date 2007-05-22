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
        $webip = $_POST["webip"];
        $mailip = $_POST["mailip"];
        $empty = $_POST["empty"];
        $dom_type = isset($_POST["dom_type"]) ? $_POST["dom_type"] : "NATIVE";
        if(!$empty)
        {
                $empty = 0;
                if(!eregi('in-addr.arpa', $domain) && (!is_valid_ip($webip) || !is_valid_ip($mailip)) )
                {
                        $error = "Web or Mail ip is invalid!";
                }
        }
        if (!$error)
        {
                if (!is_valid_domain($domain))
                {
                        $error = "Domain name is invalid!";
                }
                elseif (domain_exists($domain))
                {
                        $error = "Domain already exists!";
                }
                //elseif (isset($mailip) && is_valid_ip(
                else
                {
                        add_domain($domain, $owner, $webip, $mailip, $empty, $dom_type, '');
			$success = _('Successfully added slave zone.');
                }
        }
}

include_once("inc/header.inc.php");

	?>
	<h2>Add master zone</h2>
	<?

	if ($error != "")
	{
	        ?><div class="error"><? echo _('Error'); ?>: <? echo $error; ?></div><?
	}
	elseif ($success != "")
	{
		?><div class="success"><? echo $success; ?></div><?
	}

	// Zone type set to master and native only, slave zones are created
	// on a different page. 
        $zone_types = array("MASTER", "NATIVE");
        $users = show_users();
        ?>
        <form method="post" action="add_zone_master.php">
         <table>
          <tr>
           <td class="n"><? echo _('Domain name'); ?>:</td>
           <td class="n">
            <input type="text" class="input" name="domain" value="<? if ($error) print $_POST["domain"]; ?>">
           </td>
          </tr>
          <tr>
           <td class="n"><? echo _('Web IP'); ?>:</td>
           <td class="n">
            <input type="text" class="input" name="webip" value="<? if ($error) print $_POST["webip"]; ?>">
           </td>
          </tr>
          <tr>
           <td class="n"><? echo _('Mail IP'); ?>:</TD>
           <td class="n">
            <input type="text" class="input" name="mailip" value="<? if ($error) print $_POST["mailip"]; ?>">
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
           <td class="n"><? echo _('Domain type'); ?>:</td>
           <td class="n">
            <select name="dom_type">
        <?
        foreach($zone_types as $s)
        {
           ?><option value="<?=$s?>"><?=$s ?></option><?
        }
        ?>
            </select>
           </td>
          </tr>
          <tr>
           <td class="n"><? echo _('Create zone without applying records-template'); ?>:</td>
	   <td class="n"><input type="checkbox" name="empty" value="1"></td>
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
