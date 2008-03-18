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


// TODO rewrite

require_once("inc/toolkit.inc.php");

if($_POST["submit"])
{
	if(strlen($_POST["newpass"]) < 8)
	{
		error('Password length should be at least 8 characters.');
	}
	else
	{
		change_user_pass($_POST["currentpass"], $_POST["newpass"], $_POST["newpass2"]);
	}
}

include_once("inc/header.inc.php");
?>
    <h2><?php echo _('Change password'); ?></h2>
    <form method="post" action="change_password.php">
     <table border="0" CELLSPACING="4">
      <tr>
       <td class="n"><?php echo _('Current password'); ?>:</td>
       <td class="n"><input type="password" class="input" NAME="currentpass" value=""></td>
      </tr>
      <tr>
       <td class="n"><?php echo _('New password'); ?>:</td>
       <td class="n"><input type="password" class="input" NAME="newpass" value=""></td>
      </tr>
      <tr>
       <td class="n"><?php echo _('New password'); ?>:</td>
       <td class="n"><input type="password" class="input" NAME="newpass2" value=""></td>
      </tr>
      <tr>
       <td class="n">&nbsp;</td>
       <td class="n">
        <input type="submit" class="button" NAME="submit" value="<?php echo _('Change password'); ?>">
       </td>
      </tr>
     </table>
    </form>

<?php
include_once("inc/footer.inc.php");
?>
