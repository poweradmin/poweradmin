<?php

require_once("inc/toolkit.inc.php");

if ($_GET["id"]) {
	// check if we have access to the given id
	$zoneId = recid_to_domid($_GET['id']);
	if ((!level(5)) && (!xs($zoneId))) {
    		error(ERR_RECORD_ACCESS_DENIED);
	}
	if ((!level(5)) && ($_SESSION[$zoneId.'_ispartial'] == 1)) {
		$checkPartial = $db->queryOne("SELECT id FROM record_owners WHERE record_id='".$_GET["id"]."' AND user_id='".$_SESSION["userid"]."' LIMIT 1");
		if (empty($checkPartial)) {
			error(ERR_RECORD_ACCESS_DENIED);
		}
	}
        if ($_GET["confirm"] == '0') {
                clean_page("edit.php?id=".$_GET["domain"]);
        } elseif ($_GET["confirm"] == '1') {
                delete_record($_GET["id"]);
                clean_page("edit.php?id=".$_GET["domain"]);
        }
        include_once("inc/header.inc.php");
        ?>
	
	<h2><? echo _('Delete record'); ?> "<?
        $data = get_record_from_id($_GET["id"]);
        print $data["name"]." IN ".$data["type"]." ".$data["content"];
        ?>"</h2><?
        if (($data["type"] == "NS" && $data["name"] == get_domain_name_from_id($_GET["domain"])) || $data["type"] == "SOA") {
                print "<font class=\"warning\">" . _('You are trying to delete a record that is needed for this zone to work.') . "</font><br>";
        }
        ?><br><font class="warning"><? echo _('Are you sure?'); ?></font><br><br>
        <input type="button" class="button" OnClick="location.href='<? echo $_SERVER["REQUEST_URI"] ?>&confirm=1'" value="<? echo _('Yes'); ?>"> 
	<input type="button" class="button" OnClick="location.href='<? echo $_SERVER["REQUEST_URI"] ?>&confirm=0'" value="<? echo _('No'); ?>">
        <?
} else {
        include_once("inc/header.inc.php");
        echo _("Nothing to do!");
}
include_once("inc/footer.inc.php");
