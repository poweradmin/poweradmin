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

$xsid = (isset($_GET['id'])) ? $_GET['id'] : $_POST['zoneid'];
if ((!level(5)) && ((!xs($xsid) || ($_SESSION[$xsid.'_ispartial'])))) {
	error(ERR_RECORD_ACCESS_DENIED);
}

if (isset($_POST["commit"]) && isset($_POST['zoneid']) && isset($_POST['name']) && isset($_POST['type']) && isset($_POST['content']) && isset($_POST['ttl']) && isset($_POST['prio']) ) {
        $ret = add_record($_POST["zoneid"], $_POST["name"], $_POST["type"], $_POST["content"], $_POST["ttl"], $_POST["prio"]);
        if ($ret != '1') {
                die("$ret");
        }
        clean_page("edit.php?id=".$_POST["zoneid"]);
}

include_once("inc/header.inc.php");
?>

    <h2><?php echo _('Add record to zone'); ?> "<?php echo get_domain_name_from_id($_GET["id"]) ?>"</H2>

    <form method="post">
     <input type="hidden" name="zoneid" value="<?php echo $_GET["id"] ?>">
     <table border="0" cellspacing="4">
      <tr>
       <td class="n"><?php echo _('Name'); ?></td>
       <td class="n">&nbsp;</td>
       <td class="n"><?php echo _('Type'); ?></td>
       <td class="n"><?php echo _('Priority'); ?></td>
       <td class="n"><?php echo _('Content'); ?></td>
       <td class="n"><?php echo _('TTL'); ?></td>
      </tr>
      <tr>
       <td class="n"><input type="text" name="name" class="input">.<?php echo get_domain_name_from_id($_GET["id"]) ?></td>
       <td class="n">IN</td>
       <td class="n">
        <select name="type">
<?php
$dname = get_domain_name_from_id($_GET["id"]);
foreach (get_record_types() as $c) {
        if (eregi('in-addr.arpa', $dname) && strtoupper($c) == 'PTR') {
                $add = " SELECTED";
        } elseif (strtoupper($c) == 'A') {
                $add = " SELECTED";
        } else {
                $add = '';
        }
        ?><option<?php echo $add ?> value="<?php echo $c ?>"><?php echo $c ?></option><?php
}
?>
        </select>
       </td>
       <td class="n"><input type="text" name="prio" class="sinput"></td>
       <td class="n"><input type="text" name="content" class="input"></td>
       <td class="n"><input type="text" name="ttl" class="sinput" value="<?php echo $DEFAULT_TTL?>"></td>
      </tr>
     </table>
     <br>
     <input type="submit" name="commit" value="<?php echo _('Add record'); ?>" class="button">
    </form>

<?php include_once("inc/footer.inc.php"); ?>
