<?php

/*  PowerAdmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007, 2008  Rejo Zenger <rejo@zenger.nl>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once("inc/toolkit.inc.php");

// Assigning records to user: Check for records owned by user
$recordOwnerError = '';
if (isset($_POST["action"]) && $_POST["action"]=="record-user") {
	if (!is_array($_POST['rowid'])) {
		$recordOwnerError = 'No records where selected to assign an sub-owner.';
	} else {
		foreach ($_POST["rowid"] as $x_user => $recordid){
			$x_userid = $db->queryOne("SELECT id FROM record_owners WHERE user_id = ".$db->quote($_POST["userid"])." AND record_id=".$db->quote($recordid));
			if (empty($x_userid)) {
				add_record_owner($_GET["id"],$_POST["userid"],$recordid);
			}
		}
	}
}
if(isset($_POST['change_slave_master']) && is_numeric($_POST["domain"]) && level(5))
{
	change_domain_slave_master($_POST['domain'], $_POST['slave_master']);
}
if(isset($_POST['type_change']) && in_array($_POST['newtype'], $server_types))
{
	change_domain_type($_POST['newtype'], $_GET['id']);
}
if(isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"]))
{
	add_owner($_POST["domain"], $_POST["newowner"]);
}
if(isset($_POST["del_user"]) && is_numeric($_POST["del_user"]) && level(5))
{
	delete_owner($_GET["id"], $_POST["del_user"]);
}
$info = get_domain_info_from_id($_GET["id"]);
include_once("inc/header.inc.php");
	
$domain_type=get_domain_type($_GET['id']);
if ($domain_type == "SLAVE" ) { $slave_master=get_domain_slave_master($_GET['id']); };

if (strlen($recordOwnerError)) {
?>
  <div class="error"><?php echo _('Error'); ?>: <?php echo _($recordOwnerError); ?></div>
<?php
}

if(!isset($info["ownerid"]) && $domain_type != "SLAVE")
{
?>
    <div class="error"><?php echo _('Error'); ?>: <?php echo ('There is no owner for this zone, please assign someone.'); ?></div>
<?php
}
if ($domain_type == "SLAVE" && ! $slave_master )
{
?>
    <div class="error"><?php echo _('Error'); ?>: <?php echo _('Type of this zone is "slave", but there is no IP address for it\'s master given.'); ?></div>
<?php
}
?>
    <h2><?php echo _('Edit zone'); ?> "<?php echo get_domain_name_from_id($_GET["id"]) ?>"</h2>
<?php
if (level(5)) 
{ ?>	
       <div id="meta">
        <div id="meta-left">
	 <table>
   	  <tr>
  	   <th colspan="2"><?php echo _('Owner of zone'); ?></th>
  	  </tr>
<?php
	if(isset($info["ownerid"]))
	{
		$userRes = get_users_from_domain_id($_GET["id"]);
		foreach($userRes as $user)
		{ ?>
  	  <tr>
  	   <form method="post" action="edit.php?id=<?php echo $_GET['id']?>">
  	    <td>
	     <?php echo $user["fullname"]?>
	    </td>
            <td>
  	     <input type="hidden" name="del_user" value="<?php echo $user["id"]?>">
             <input type="submit" class="sbutton" name="co" value="<?php echo _('Delete'); ?>">
  	    </td>
           </form>
  	  </tr>
<?php
		}
	}
	else
	{
?>
	  <tr>
	   <td><?php echo _('No owner set or this zone!'); ?></td>
	  </tr>
<?php
	}
  ?>
          <tr>
  	   <form method="post" action="edit.php?id=<?php echo $_GET['id']?>">
  	    <td>
  	     <input type="hidden" name="domain" value="<?php echo $_GET["id"] ?>">
  	     <select name="newowner">
  			<?php
  			$users = show_users();
  			foreach ($users as $u)
  			{
  				$add = '';
  				if ($u["id"] == $info["ownerid"])
  				{
  					$add = " SELECTED";
  				}
  				?>
  				<option<?php echo $add ?> value="<?php echo $u["id"] ?>"><?php echo $u["fullname"] ?></option><?php
  			}
  			?>
  			</select>
  	    </td>
  	    <td>
     	     <input type="submit" class="sbutton" name="co" value="<?php echo _('Add'); ?>">
            </td>
  	   </form>
  	  </tr>
         </table>
	</div> <?php // eo div meta-left ?>
        <div id="meta-right">
         <table>
	  <tr>
	   <th colspan="2"><?php echo _('Type of zone'); ?></th>
	  </tr>
	  <form action="<?php echo $_SERVER['PHP_SELF']?>?id=<?php echo $_GET['id']?>" method="post">
	   <input type="hidden" name="domain" value="<?php echo $_GET["id"] ?>">
	   <tr>
	    <td>
	     <select name="newtype">
<?php
	foreach($server_types as $s)
	{
		$add = '';
		if ($s == $domain_type)
		{
			$add = " SELECTED";
		}
?>
              <option<?php echo $add ?> value="<?php echo $s?>"><?php echo $s?></option><?php
	}
?>
             </select>
            </td>
	    <td>
	     <input type="submit" class="sbutton" name="type_change" value="<?php echo _('Change'); ?>">
	    </td>
	   </tr>
	  </form>

<?php
	if ($domain_type == "SLAVE" ) 
	{ 
		$slave_master=get_domain_slave_master($_GET['id']);
?>
          <tr>
	   <th colspan="2">
	    <?php echo _('IP address of master NS'); ?>
	   </th>
	  </tr>
	  <form action="<?php echo $_SERVER['PHP_SELF']?>?&amp;id=<?php echo $_GET['id']?>" method="post">
	   <input type="hidden" name="domain" value="<?php echo $_GET["id"] ?>">
	   <tr>
	    <td>
	     <input type="text" name="slave_master" value="<?php echo $slave_master; ?>" class="input">
            </td>
            <td>
	     <input type="submit" class="sbutton" name="change_slave_master" value="<?php echo _('Change'); ?>">
            </td>
           </tr>
          </form>
<?php
	}
?>
         </table>  
        </div> <?php // eo div meta-right ?>
       </div> <?php // eo div meta 
}
else
{
?>
       <div id="meta">
        <div id="meta-right">
         <table>
 	  <tr>
 	   <th><?php echo _('Type of zone'); ?></th><td class="y"><?php echo $domain_type; ?></td>
	  </tr>
<?php
	if ($domain_type == "SLAVE" &&  $slave_master )
	{
?>
	  <tr>
	   <th><?php echo _('IP address of master NS'); ?></th><td class="y"><?php echo $slave_master; ?></td>
	  </tr>
<?php
	}
?>
         </table>
        </div> <?php //eo div meta-right ?>
        </div> <?php // eo div meta
}
?>
       <div id="meta">
<?php
	if ($_SESSION[$_GET["id"]."_ispartial"] != 1 && $domain_type != "SLAVE" )
	{
?>
        <input type="button" class="button" OnClick="location.href='add_record.php?id=<?php echo $_GET["id"] ?>'" value="<?php echo _('Add record'); ?>">&nbsp;&nbsp;
<?php
	}
	if (level(5))
	{
?>
	<input type="button" class="button" OnClick="location.href='delete_domain.php?id=<?php echo $_GET["id"] ?>'" value="<?php echo _('Delete zone'); ?>">
<?php
	}
?>
        </div> <?php // eo div meta ?>
       <div class="showmax">
<?php
show_pages($info["numrec"],ROWAMOUNT,$_GET["id"]);
?>
        </div> <?php // eo div showmax ?>
         <form action="<?php echo $_SERVER["PHP_SELF"]?>?id=<?php echo $_GET["id"]?>" method="post">
          <input type="hidden" name="action" value="record-user">
          <table>
<?php
$countinput=0;
$rec_result = get_records_from_domain_id($_GET["id"],ROWSTART,ROWAMOUNT);
if($rec_result != -1)
{
?>
           <tr>
	    <th>&nbsp;</th>
<?php 
	if (level(10) && $domain_type != "SLAVE") 
	{ 
		echo "<th class=\"n\">" . _('Sub-owners') . "</td>"; 
	} 
?>
	    <th><?php echo _('Name'); ?></th>
	    <th><?php echo _('Type'); ?></th>
	    <th><?php echo _('Content'); ?></th>
	    <th><?php echo _('Priority'); ?></th>
	    <th><?php echo _('TTL'); ?></th>
           </tr>
<?php
  	$recs = sort_zone($rec_result);
  	foreach($recs as $r)
  	{
?>
           <tr>
	    <td class="n">
<?php
		if ($domain_type != "SLAVE" )
		{	
			if(level(5) || (!($r["type"] == "SOA" && !$GLOBALS["ALLOW_SOA_EDIT"]) && !($r["type"] == "NS" && !$GLOBALS["ALLOW_NS_EDIT"])))
			{
?>
			     <a href="edit_record.php?id=<?php echo $r['id'] ?>&amp;domain=<?php echo $_GET["id"] ?>"><img src="images/edit.gif" alt="[ <?php echo _('Edit record'); ?> ]"></a>
			     <a href="delete_record.php?id=<?php echo $r['id'] ?>&amp;domain=<?php echo $_GET["id"] ?>"><img src="images/delete.gif" ALT="[ <?php echo _('Delete record'); ?> ]" BORDER="0"></a>
<?php
			}
		}
		if(level(10) && $domain_type != "SLAVE") 
		{ 
?>
		     <input type="checkbox" name="rowid[<?php echo $countinput++?>]" value="<?php echo $r['id']?>" />
<?php 
		}
?>
            </td>
<?php 
		if (level(10) && $domain_type != "SLAVE") 
		{ 
?>
            <td class="n">
<?php 
			$x_result = $db->query("SELECT r.user_id,u.username,u.fullname FROM record_owners as r, users as u WHERE r.record_id=".$db->quote($r['id'])." AND u.id=r.user_id");
			echo "<select style=\"width:120px;\">";
			while ($x_r = $x_result->fetchRow()) {
				echo "<option value=\"".$x_r["username"]."\">".$x_r["fullname"]."</option>";
			}
			echo "</select>";
?>
            </td>
<?php 
		} 
?>
	    <td class="y"><?php echo $r['name'] ?></td>
	    <td class="y"><?php echo $r['type'] ?></td>
	    <td class="y"><?php echo $r['content'] ?></td>
<?php
		if ($r['prio'] != 0) 
		{
?>
            <td class="y"><?php echo $r['prio']; ?></td>
<?php
		} else {
?>
            <td class="n"></td><?php
		}
?>
            <td class="y"><?php echo $r['ttl'] ?></td>
	   </tr>
<?php
	}
}
else
{
?>
           <tr>
            <td class="n">
	     <div class="warning"><?php echo _('No records for this zone.'); ?></div>
	    </td>
           </tr>
<?php
}
?>
          </table>

<?php
if ($domain_type != "SLAVE")
{
	if (level(10)) { ?>
	   <img src="images/arrow.png" alt="arrow" class="edit-assign-to-user">
	   <select name="userid">
		<?php
		$users = show_users();
		foreach ($users as $user) {
			echo "<option value=\"".$user[id]."\">".$user[fullname]."</option>";
		}
		?>
           </select>
	   <input type="submit" class="button" value="<?php echo _('Assign to user'); ?>">
	  </form>
<?php 
	} 
}
include_once("inc/footer.inc.php");
?>
