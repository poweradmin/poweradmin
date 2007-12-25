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

$id = ($_POST["id"]) ? $_POST["id"] : $_GET["id"];

if(isset($id)) 
{
	if($_POST["confirm"] == '1') 
	{                
                $domain = is_array($_POST["domain"]) ? $_POST["domain"] : $domain = array();
                $delete = is_array($_POST["delete"]) ? $_POST["delete"] : $delete = array();
                
		if(count($domain) > 0) 
		{
			foreach ($domain as $dom => $newowner) 
			{
				if (!in_array($dom, $delete)) 
				{
					add_owner($dom, $newowner);
                                }
                        }
                }
                if(count($delete) > 0) 
                {
                	foreach ($delete as $del) 
                	{
                		delete_domain($del);
			}
		}
		
                delete_user($id);
                clean_page($BASE_URL . $BASE_PATH . "users.php");
        }
        include_once("inc/header.inc.php");
        ?>
	
    <h3><? echo _('Delete user'); ?> "<? echo get_fullname_from_userid($id) ?>"</h3>
     <form method="post">
        <?
        $domains = get_domains_from_userid($id);
        if (count($domains) > 0) 
        {
        	echo _('This user has access to the following zone(s)'); ?> :<BR><?
                $users = show_users($id);
                if(count($users) < 1) 
                {
                        $add = " CHECKED DISABLED";
                        $no_users = 1;
                }
                ?>
                <table>
                 <tr>
		  <td class="n">Delete</td>
		  <td class="n">Name</td>
		<? if (!$no_users) { ?>
		  <td class="n">New owner</td>
		<? } ?>
		 </tr>
                <?
                foreach ($domains as $d) 
                {
                        ?>
                 <tr>
		  <td class="n" align="center"><?
                        if ($no_users) 
                     	{ 
                     		?><input type="hidden" name="delete[]" value="<? echo $d["id"] ?>"><?
                        } 
                        ?><input type="checkbox"<? echo $add ?> name="delete[]" value="<? echo $d["id"] ?>"></td><td class="n"><? echo $d["name"] ?></td><td class="n"><? 
                        if (!$no_users) 
                        { 
                        	?><select name="domain[<? echo $d["id"] ?>]"><?
                        	foreach($users as $u) 
                        	{
                        	        ?><option value="<? echo $u["id"] ?>"><? echo $u["fullname"] ?></option><?
                        	}
                        	?></select></td><? 
                        } 
                        ?></tr><?
                }
                ?></table><?
        }
        
        $message = _('You are going to delete this user, are you sure?');
        if(($numrows = $db->queryOne("select count(id) from zones where owner=$id")) != 0)
        {
        	$message .= " " . _('This user has access to ') . $numrows . _(' zones, by deleting him you will also delete these zones.');
        }

        ?>
        <font class="warning"><? echo $message ?></font><br>
        <input type="hidden" name="id" value="<? echo $id ?>">
        <input type="hidden" name="confirm" value="1">
        <input type="submit" class="button" value="<? echo _('Yes'); ?>"> <input type="button" class="button" OnClick="location.href='users.php'" value="<? echo _('No'); ?>"></FORM>
        <?
        include_once("inc/footer.inc.php");
} 
else 
{
        message("Nothing to do!");
}

