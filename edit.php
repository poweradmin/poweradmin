<?php

// +--------------------------------------------------------------------+
// | PowerAdmin								|
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team			|
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal	|
// | program PowerAdmin as found on http://poweradmin.sf.net		|
// | The PowerAdmin program falls under the QPL License:		|
// | http://www.trolltech.com/developer/licensing/qpl.html		|
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>	|
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>			|
// +--------------------------------------------------------------------+

//
// $Id: edit.php,v 1.12 2003/05/10 20:10:47 azurazu Exp $
//

require_once("inc/toolkit.inc.php");

// Assigning records to user: Check for records owned by user

if (isset($_POST["action"]) && $_POST["action"]=="record-user") {
   foreach ($_POST["rowid"] as $x_user => $x_value){
      $x_userid = $db->getOne("SELECT id FROM record_owners WHERE user_id = '".$_POST["userid"]."' AND record_id='".$x_value."'");
      if (empty($x_userid)) {
         $db->query("INSERT INTO record_owners SET user_id = '".$_POST["userid"]."',record_id='".$x_value."'");
      }
   }
}

$server_types = array("MASTER", "SLAVE", "NATIVE");

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

include_once("inc/header.inc.php");
?>
<H2><? echo _('Edit domain'); ?> "<?= get_domain_name_from_id($_GET["id"]) ?>"</H2>
<?
$info = get_domain_info_from_id($_GET["id"]);
if(!isset($info["ownerid"]))
{
	?>
	<P CLASS="warning"><? echo _('This domain isn\'t owned by anyone yet, please assign someone.'); ?></P>
	<?
}
?>

<TABLE class="text" cellspacing="0" style="width: 280px">
<? if (level(5)) 
{ ?>	
	<TR>
		<FORM METHOD="post" ACTION="edit.php?id=<?=$_GET['id']?>">
		<TD CLASS="none" VALIGN="middle" style="width: 250px;">
			<B><? echo _('Add an owner'); ?>:</B>
			<INPUT TYPE="hidden" NAME="domain" VALUE="<?= $_GET["id"] ?>">
			<SELECT NAME="newowner">
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
				<OPTION<?= $add ?> VALUE="<?= $u["id"] ?>"><?= $u["fullname"] ?></OPTION><?
			}
			?>
			</SELECT>
		</TD>
		<TD CLASS="none" VALIGN="middle"  align="right">
			<INPUT TYPE="submit" CLASS="sbutton" NAME="co" VALUE="<? echo _('Add'); ?>">
		</TD>
		</FORM>
	</TR>
	<TR>
		<TD CLASS="text" COLSPAN="2">&nbsp;</TD>
	</TR>
<? 

if(isset($info["ownerid"]))
{?>
	<TR>
		<TD CLASS="text" ALIGN="left" COLSPAN="2" style="width:150px;">
			<B><? echo _('Current listed owners'); ?>:</B>
		</TD>
	</TR>
	<?
	$userRes = get_users_from_domain_id($_GET["id"]);
	foreach($userRes as $user)
	{ ?>
		<TR>
			<FORM METHOD="post" ACTION="edit.php?id=<?=$_GET['id']?>">
			<TD CLASS="text" ALIGN="left" style="width:150px;">
				<?=$user["fullname"]?>
			</TD>
			<TD CLASS="text" align="right">
				<INPUT TYPE="hidden" NAME="del_user" VALUE="<?=$user["id"]?>">
				<INPUT TYPE="submit" CLASS="sbutton" NAME="co" VALUE="<? echo _('Delete'); ?>">
			</TD>
			</FORM>
		</TR>
	<? }
}} ?>


<? if(level(5) && $MASTER_SLAVE_FUNCTIONS)
{
    $domain_type=get_domain_type($_GET['id']);
    // Let the user change the domain type.
?>
        <TR>
            <TD CLASS="text">&nbsp;</TD>
        </TR>
		<TR>
			<TD CLASS="text" COLSPAN="2"><B><? echo _('Type of this domain'); ?>: </B><?=$domain_type?></TD>
		</TR>
		<FORM ACTION="<?=$_SERVER['PHP_SELF']?>?&amp;id=<?=$_GET['id']?>" METHOD="post">
		<TR>
            <TD CLASS="text"><B><? echo _('Change type'); ?>: </B>
                <SELECT NAME="newtype">
                <?
                foreach($server_types as $s)
                {
                    unset($add);
				    if ($s == $domain_type)
				    {
					   $add = " SELECTED";
				    }
                    ?><OPTION<?=$add ?> VALUE="<?=$s?>"><?=$s?></OPTION><?
                }
                ?>
                </SELECT>
            </TD>
            <TD CLASS="text">
                <INPUT TYPE="submit" CLASS="sbutton" NAME="type_change" VALUE="<? echo _('Change'); ?>">
            </TD>
        </TR>
        </FORM>
<? } ?>
</TABLE>
<br />
<FONT CLASS="nav">
<A HREF="index.php"><? echo _('DNS Admin'); ?></A> &gt;&gt; <?= get_domain_name_from_id($_GET["id"]) ?>
</FONT>
<br /><br /><small><b><? echo _('Number of records'); ?>:</b> <?= $info["numrec"] ?>

<?
show_pages($info["numrec"],ROWAMOUNT,$_GET["id"]);
?>

<br /><br />

<form action="<?=$_SERVER["PHP_SELF"]?>?id=<?=$_GET["id"]?>" method="post">
<input type="hidden" name="action" value="record-user" />

<TABLE BORDER="0" CELLSPACING="4">
<?

$countinput=0;

$rec_result = get_records_from_domain_id($_GET["id"],ROWSTART,ROWAMOUNT);

if($rec_result != -1)
{
	?>
	<TR STYLE="font-weight: Bold;">
	<TD CLASS="tdbg">&nbsp;</TD>
	<? if (level(10)) { echo "<TD CLASS=\"tdbg\">" . _('Sub-owners') . "</TD>"; } ?>
	<TD CLASS="tdbg"><? echo _('Name'); ?></TD>
	<TD CLASS="tdbg"><? echo _('Type'); ?></TD>
	<TD CLASS="tdbg"><? echo _('Content'); ?></TD>
	<TD CLASS="tdbg"><? echo _('Priority'); ?></TD>
	<TD CLASS="tdbg"><? echo _('TTL'); ?></TD>
	</TR>
	<?
	$recs = sort_zone($rec_result);
	foreach($recs as $r)
	{
	        ?><TR><TD CLASS="tdbg"><?

	        if(level(5) || (!($r["type"] == "SOA" && !$GLOBALS["ALLOW_SOA_EDIT"]) && !($r["type"] == "NS" && !$GLOBALS["ALLOW_NS_EDIT"])))
	        {
                // get_name_from_record_id($r["id"]) != get_domain_name_from_id(recid_to_domid($r["id"])) <-- hmm..
                ?>
	            <A HREF="edit_record.php?id=<?= $r['id'] ?>&amp;domain=<?= $_GET["id"] ?>"><IMG SRC="images/edit.gif" ALT="[ <? echo _('Edit record'); ?> ]" BORDER="0"></A>
	            <A HREF="delete_record.php?id=<?= $r['id'] ?>&amp;domain=<?= $_GET["id"] ?>"><IMG SRC="images/delete.gif" ALT="[ <? echo _('Delete record'); ?> ]" BORDER="0"></A>
	            <?
	        }

if(level(10)) { ?>

<input type="checkbox" name="rowid[<?=$countinput++?>]" value="<?=$r['id']?>" />

<? }
		
	        ?></TD>
		
<? if (level(10)) { ?>
		<TD STYLE="border: 1px solid #000;width:120px">
<?
$x_result = $db->query("SELECT r.user_id,u.username FROM record_owners as r, users as u WHERE r.record_id='".$r['id']."' AND u.id=r.user_id");
echo "<select style=\"width:120px;font-size:9px\">";
while ($x_r = $x_result->fetchRow()) {
   echo "<option>".$x_r["username"]."</option>";
}
echo "</select>";
?>
		</TD>
<? } ?>
		<TD STYLE="border: 1px solid #000000;"><?= $r['name'] ?></TD><TD STYLE="border: 1px solid #000000;"><?= $r['type'] ?></TD><TD STYLE="border: 1px solid #000000;"><?= $r['content'] ?></TD><?
	        if ($r['prio'] != 0) {
	                ?><TD STYLE="border: 1px solid #000000;"><?= $r['prio']; ?></TD><?
	        } else {
	                ?><TD CLASS="tdbg"></TD><?
	        }
	        ?><TD STYLE="border: 1px solid #000000;"><?= $r['ttl'] ?></TD></TR>
	        <?
	}
}
else
{
	?>
	<TR>
	<TD CLASS="tdbg"><DIV CLASS="warning"><? echo _('No records for this domain.'); ?></DIV></TD>
	</TR>
	<?
}
?>

</TABLE>

<? if (level(10)) { ?>
<br>

<img src="images/arrow.png" alt="arrow" style="margin-left:47px"/>
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
<? } ?>

<BR><BR>

<?
if ($_SESSION[$_GET["id"]."_ispartial"] != 1)  {
?>
<INPUT TYPE="button" CLASS="button" OnClick="location.href='add_record.php?id=<?= $_GET["id"] ?>'" VALUE="<? echo _('Add record'); ?>">
<?
}
?>

<? if (level(5)) { ?>&nbsp;&nbsp;<INPUT TYPE="button" CLASS="button" OnClick="location.href='delete_domain.php?id=<?= $_GET["id"] ?>'" VALUE="<? echo _('Delete zone'); ?>"><?
}
include_once("inc/footer.inc.php");
?>
