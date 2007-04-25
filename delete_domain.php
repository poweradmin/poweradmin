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
// $Id: delete_domain.php,v 1.6 2002/12/18 01:13:10 azurazu Exp $
//

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
        ?><H2><? echo _('Delete domain'); ?> "<?= $info["name"] ?>"</H2>
        <?
	if($info["owner"])
	{
		print (_('Owner') . ": " . $info["owner"] . "<BR>"); 
	}
	print (_('Type') . ": " . strtolower($info["type"]) . "<br><br>");
	print (_('Number of records in zone') . ": " . $info["numrec"] . "<BR><BR>");
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
	<FONT CLASS="warning"><? echo _('Are you sure?'); ?></FONT>
	<BR><BR>
	<INPUT TYPE="button" CLASS="button" OnClick="location.href='<?= $_SERVER["REQUEST_URI"] ?>&confirm=1'" VALUE="<? echo _('Yes'); ?>">
	<INPUT TYPE="button" CLASS="button" OnClick="location.href='<?= $_SERVER["REQUEST_URI"] ?>&confirm=0'" VALUE="<? echo _('No'); ?>">
	<?
} elseif ($_GET["edit"]) {
        include_once("inc/header.inc.php");
} else {
        include_once("inc/header.inc.php");
        die("Nothing to do!");
}
include_once("inc/footer.inc.php");
