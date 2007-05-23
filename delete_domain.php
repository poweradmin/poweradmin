<?php

require_once("inc/toolkit.inc.php");

if (!level(5))
{
        error(ERR_LEVEL_5);
        
}

if ($_GET["id"]) {
        if ($_GET["confirm"] == '0') {
                clean_page("index.php");
        } elseif ($_GET["confirm"] == '1') {
                delete_domain($_GET["id"]);
                clean_page("index.php");
        }
        include_once("inc/header.inc.php");
        $info = get_domain_info_from_id($_GET["id"]);
        ?><h2><? echo _('Delete domain'); ?> "<? echo $info["name"] ?>"</h2>
        <?
	if($info["owner"])
	{
		print (_('Owner') . ": " . $info["owner"] . "<br>"); 
	}
	print (_('Type') . ": " . strtolower($info["type"]) . "<br>");
	print (_('Number of records in zone') . ": " . $info["numrec"] . "<br>");
	if($info["type"] == "SLAVE")
	{
		$slave_master = get_domain_slave_master($_GET["id"]);
		if(supermaster_exists($slave_master))
		{
			print ("<font class=\"warning\">");
			printf(_('You are about to delete a slave domain of which the master nameserver, %s, is a supermaster. Deleting the zone now, will result in temporary removal only. Whenever the supermaster sends a notification for this zone, it will be added again!'), $slave_master);
			print ("</font><br>");
		}
	}
	?>
	<font class="warning"><? echo _('Are you sure?'); ?></font>
	<br><br>
	<input type="button" class="button" OnClick="location.href='<? echo $_SERVER["REQUEST_URI"] ?>&confirm=1'" value="<? echo _('Yes'); ?>">
	<input type="button" class="button" OnClick="location.href='<? echo $_SERVER["REQUEST_URI"] ?>&confirm=0'" value="<? echo _('No'); ?>">
	<?
} elseif ($_GET["edit"]) {
        include_once("inc/header.inc.php");
} else {
        include_once("inc/header.inc.php");
        die("Nothing to do!");
}
include_once("inc/footer.inc.php");
