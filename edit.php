<?php

require_once("inc/toolkit.inc.php");

// Assigning records to user: Check for records owned by user

if (isset($_POST["action"]) && $_POST["action"]=="record-user") {
	foreach ($_POST["rowid"] as $x_user => $x_value){
		$x_userid = $db->queryOne("SELECT id FROM record_owners WHERE user_id = '".$_POST["userid"]."' AND record_id='".$x_value."'");
		if (empty($x_userid)) {
			$db->query("INSERT INTO record_owners SET user_id = '".$_POST["userid"]."',record_id='".$x_value."'");
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

if (level(5))
{
	if(!isset($info["ownerid"]))
	{
	?>
	    <div class="error"><? echo _('Error'); ?>: <? echo ('There is no owner for this zone, please assign someone.'); ?></div>
	<?
	}
	$domain_type=get_domain_type($_GET['id']);
	if ($domain_type == "SLAVE" )
	{
		$slave_master=get_domain_slave_master($_GET['id']);
		if ($slave_master == "" )
		{
?>
            <div class="error"><? echo _('Type of this domain is "slave", but there is no IP address for it\'s master given. Please specify!'); ?></div>
<?
		}
	}
}

?>
    <h2><? echo _('Edit domain'); ?> "<? echo get_domain_name_from_id($_GET["id"]) ?>"</h2>
<?

if (level(5)) 
{ ?>	
       <div id="meta">
        <div id="meta-left">
	 <table>
   	  <tr>
  	   <th colspan="2"><? echo _('Owner of zone'); ?></th>
  	  </tr>
<?
	if(isset($info["ownerid"]))
	{
		$userRes = get_users_from_domain_id($_GET["id"]);
		foreach($userRes as $user)
		{ ?>
  	  <tr>
  	   <form method="post" action="edit.php?id=<? echo $_GET['id']?>">
  	    <td>
	     <? echo $user["fullname"]?>
	    </td>
            <td>
  	     <input type="hidden" name="del_user" value="<? echo $user["id"]?>">
             <input type="submit" class="sbutton" name="co" value="<? echo _('Delete'); ?>">
  	    </td>
           </form>
  	  </tr>
<?
		}
	}
	else
	{
?>
	  <tr>
	   <td><? echo _('No owner set or this domain!'); ?></td>
	  </tr>
<?
	}
}
  ?>
          <tr>
  	   <form method="post" action="edit.php?id=<? echo $_GET['id']?>">
  	    <td>
  	     <input type="hidden" name="domain" value="<? echo $_GET["id"] ?>">
  	     <select name="newowner">
  			<?
  			$users = show_users();
  			foreach ($users as $u)
  			{
  				unset($add);
  				if ($u["id"] == $info["ownerid"])
  				{
  					$add = " SELECTED";
  				}
  				?>
  				<option<? echo $add ?> value="<?= $u["id"] ?>"><?= $u["fullname"] ?></option><?
  			}
  			?>
  			</select>
  	    </td>
  	    <td>
     	     <input type="submit" class="sbutton" name="co" value="<? echo _('Add'); ?>">
            </td>
  	   </form>
  	  </tr>
         </table>
	</div> <? // eo div meta-left ?>
 
<?
if (level(5))
{
	$domain_type=get_domain_type($_GET['id']);
?>
        <div id="meta-right">
         <table>
	  <tr>
	   <th colspan="2"><? echo _('Type of zone'); ?></th>
	  </tr>
	  <form action="<? echo $_SERVER['PHP_SELF']?>?id=<? echo $_GET['id']?>" method="post">
	   <input type="hidden" name="domain" value="<? echo $_GET["id"] ?>">
	   <tr>
	    <td>
	     <select name="newtype">
<?
	foreach($server_types as $s)
	{
		unset($add);
		if ($s == $domain_type)
		{
			$add = " SELECTED";
		}
?>
              <option<? echo $add ?> value="<? echo $s?>"><? echo $s?></option><?
	}
?>
             </select>
            </td>
	    <td>
	     <input type="submit" class="sbutton" name="type_change" value="<? echo _('Change'); ?>">
	    </td>
	   </tr>
	  </form>

<?
	if ($domain_type == "SLAVE" ) 
	{ 
		$slave_master=get_domain_slave_master($_GET['id']);
?>
          <tr>
	   <th colspan="2">
	    <? echo _('IP address of master NS'); ?>
	   </th>
	  </tr>
	  <form action="<? echo $_SERVER['PHP_SELF']?>?&amp;id=<? echo $_GET['id']?>" method="post">
	   <input type="hidden" name="domain" value="<? echo $_GET["id"] ?>">
	   <tr>
	    <td>
	     <input type="text" name="slave_master" value="<? echo $slave_master; ?>" class="input">
            </td>
            <td>
	     <input type="submit" class="sbutton" name="change_slave_master" value="<? echo _('Change'); ?>">
            </td>
           </tr>
          </form>
<?
	}
}
?>
         </table>  
        </div> <? // eo div meta-right ?>
       </div> <? // eo div meta ?>
       <div id="meta">
<?
	if ($_SESSION[$_GET["id"]."_ispartial"] != 1 && $domain_type != "SLAVE" )
	{
?>
        <input type="button" class="button" OnClick="location.href='add_record.php?id=<? echo $_GET["id"] ?>'" value="<? echo _('Add record'); ?>">&nbsp;&nbsp;
<?
	}
	if (level(5))
	{
?>
	<input type="button" class="button" OnClick="location.href='delete_domain.php?id=<? echo $_GET["id"] ?>'" value="<? echo _('Delete zone'); ?>">
<?
	}
?>
        </div> <? // eo div meta ?>
       <div class="showmax">
<?
show_pages($info["numrec"],ROWAMOUNT,$_GET["id"]);
?>
        </div> <? // eo div showmax ?>
         <form action="<? echo $_SERVER["PHP_SELF"]?>?id=<? echo $_GET["id"]?>" method="post">
          <input type="hidden" name="action" value="record-user">
          <table>
<?
$countinput=0;
$rec_result = get_records_from_domain_id($_GET["id"],ROWSTART,ROWAMOUNT);
if($rec_result != -1)
{
?>
           <tr>
	    <th>&nbsp;</th>
<? 
	if (level(10) && $domain_type != "SLAVE") 
	{ 
		echo "<th class=\"n\">" . _('Sub-owners') . "</td>"; 
	} 
?>
	    <th><? echo _('Name'); ?></th>
	    <th><? echo _('Type'); ?></th>
	    <th><? echo _('Content'); ?></th>
	    <th><? echo _('Priority'); ?></th>
	    <th><? echo _('TTL'); ?></th>
           </tr>
<?
  	$recs = sort_zone($rec_result);
  	foreach($recs as $r)
  	{
?>
           <tr>
	    <td class="n">
<?
		if ($domain_type != "SLAVE" )
		{	
			if(level(5) || (!($r["type"] == "SOA" && !$GLOBALS["ALLOW_SOA_EDIT"]) && !($r["type"] == "NS" && !$GLOBALS["ALLOW_NS_EDIT"])))
			{
?>
             <a href="edit_record.php?id=<? echo $r['id'] ?>&amp;domain=<? echo $_GET["id"] ?>"><img src="images/edit.gif" alt="[ <? echo _('Edit record'); ?> ]"></a>
             <a href="delete_record.php?id=<? echo $r['id'] ?>&amp;domain=<? echo $_GET["id"] ?>"><img src="images/delete.gif" ALT="[ <? echo _('Delete record'); ?> ]" BORDER="0"></a>
<?
			}
		}
		if(level(10) && $domain_type != "SLAVE") 
		{ 
?>
	     <input type="checkbox" name="rowid[<? echo $countinput++?>]" value="<?=$r['id']?>" />
<? 
		}
?>
            </td>
<? 
		if (level(10) && $domain_type != "SLAVE") 
		{ 
?>
            <td class="n">
<? 
			$x_result = $db->query("SELECT r.user_id,u.username FROM record_owners as r, users as u WHERE r.record_id='".$r['id']."' AND u.id=r.user_id");
			echo "<select>";
			while ($x_r = $x_result->fetchRow()) {
				echo "<option>".$x_r["username"]."</option>";
			}
			echo "</select>";
?>
            </td>
<? 
		} 
?>
	    <td class="y"><? echo $r['name'] ?></td>
	    <td class="y"><? echo $r['type'] ?></td>
	    <td class="y"><? echo $r['content'] ?></td>
<?
		if ($r['prio'] != 0) 
		{
?>
            <td class="y"><? echo $r['prio']; ?></td>
<?
		} else {
?>
            <td class="n"></td><?
		}
?>
            <td class="y"><? echo $r['ttl'] ?></td>
	   </tr>
<?
	}
}
else
{
?>
           <tr>
            <td class="tdbg">
	     <div class="warning"><? echo _('No records for this domain.'); ?></div>
	    </td>
           </tr>
<?
}
?>
          </table>

<?
if ($domain_type != "SLAVE")
{
	if (level(10)) { ?>
	   <img src="images/arrow.png" alt="arrow" class="edit-assign-to-user">
	   <select name="userid">
		<?
		$users = show_users();
		foreach ($users as $user) {
			echo "<option value=\"".$user[id]."\">".$user[fullname]."</option>";
		}
		?>
           </select>
	   <input type="submit" class="button" value="<? echo _('Assign to user'); ?>">
	  </form>
<? 
	} 
}
include_once("inc/footer.inc.php");
?>
