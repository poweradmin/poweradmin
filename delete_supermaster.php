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
        ?>
	<h2><? echo _('Delete supermaster'); ?> "<? echo $_GET["master_ip"] ?>"</h2>
	<? echo _('Hostname in NS record'); ?>: <? echo $info["ns_name"] ?><br>
	<? echo _('Account'); ?>: <? echo $info["account"] ?><br><br>
        <font class="warning"><? echo _('Are you sure?'); ?></font><br><br>
        <input type="button" class="button" OnClick="location.href='<? echo $_SERVER["REQUEST_URI"] ?>&confirm=1'" value="<? echo _('Yes'); ?>"> 
	<input type="button" class="button" OnClick="location.href='<? echo $_SERVER["REQUEST_URI"] ?>&confirm=0'" value="<? echo _('No'); ?>">
        <?
} else {
        include_once("inc/header.inc.php");
        die("Nothing to do!");
}
include_once("inc/footer.inc.php");
