<?php

require_once("inc/toolkit.inc.php");

if (!level(5))
{
        error(ERR_LEVEL_5);
        
}

if ($_GET["master_ip"]) {
        if ($_GET["confirm"] == '0') {
                clean_page("index.php");
        } elseif ($_GET["confirm"] == '1') {
                delete_supermaster($_GET["master_ip"]);
                clean_page("index.php");
        }
        include_once("inc/header.inc.php");
	$info = get_supermaster_info_from_ip($_GET["master_ip"]);
        ?><H2><? echo _('Delete supermaster'); ?> "<?= $_GET["master_ip"] ?>"</H2>
	<? echo _('My hostname in NS record'); ?>: <?= $info["ns_name"] ?><BR>
	<? echo _('Account'); ?>: <?= $info["account"] ?><BR><BR>
        <FONT CLASS="warning"><? echo _('Are you sure?'); ?></FONT><BR><BR>
        <INPUT TYPE="button" CLASS="button" OnClick="location.href='<?= $_SERVER["REQUEST_URI"] ?>&confirm=1'" VALUE="<? echo _('Yes'); ?>"> <INPUT TYPE="button" CLASS="button" OnClick="location.href='<?= $_SERVER["REQUEST_URI"] ?>&confirm=0'" VALUE="<? echo _('No'); ?>">
        <?
} else {
        include_once("inc/header.inc.php");
        die("Nothing to do!");
}
include_once("inc/footer.inc.php");
