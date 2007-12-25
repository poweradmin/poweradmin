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
        echo _('Nothing to do!');
}
include_once("inc/footer.inc.php");
