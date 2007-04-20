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
// $Id: delete_user.php,v 1.9 2003/01/01 22:33:46 azurazu Exp $
//

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
        ?><H2><? echo _('Delete user'); ?> "<?= get_fullname_from_userid($id) ?>"</H2>
        <FORM METHOD="post">
        <?
        $domains = get_domains_from_userid($id);
        if (count($domains) > 0) 
        {
        	echo _('This user has access to the following domain(s)'); ?> :<BR><?
                $users = show_users($id);
                if(count($users) < 1) 
                {
                        $add = " CHECKED DISABLED";
                        $no_users = 1;
                }
                ?>
                <TABLE BORDER="0" CELLSPACING="4">
                <TR STYLE="font-weight: Bold"><TD WIDTH="50" CLASS="tdbg">Delete</TD><TD CLASS="tdbg">Name</TD><? if (!$no_users) { ?><TD CLASS="tdbg">New owner</TD><? } ?></TR>
                <?
                foreach ($domains as $d) 
                {
                        ?><TR><TD CLASS="tdbg" ALIGN="center"><?
                        if ($no_users) 
                     	{ 
                     		?><INPUT TYPE="hidden" NAME="delete[]" VALUE="<?= $d["id"] ?>"><?
                        } 
                        ?><INPUT TYPE="checkbox"<?= $add ?> NAME="delete[]" VALUE="<?= $d["id"] ?>"></TD><TD CLASS="tdbg"><?= $d["name"] ?></TD><TD CLASS="tdbg"><? 
                        if (!$no_users) 
                        { 
                        	?><SELECT NAME="domain[<?= $d["id"] ?>]"><?
                        	foreach($users as $u) 
                        	{
                        	        ?><OPTION VALUE="<?= $u["id"] ?>"><?= $u["fullname"] ?></OPTION><?
                        	}
                        	?></SELECT></TD><? 
                        } 
                        ?></TR><?
                }
                ?></TABLE><?
        }
        
        $message = _('You are going to delete this user, are you sure?');
        if(($numrows = $db->queryOne("select count(id) from zones where owner=$id")) != 0)
        {
        	$message .= " " . _('This user has access to ') . $numrows . _('domain(s), by deleting him you will also delete these domains');
        }

        ?>
        <BR><FONT CLASS="warning"><?= $message ?></FONT><BR><BR>
        <INPUT TYPE="hidden" NAME="id" VALUE="<?=$id ?>">
        <INPUT TYPE="hidden" NAME="confirm" VALUE="1">
        <INPUT TYPE="submit" CLASS="button" VALUE="<? echo _('Yes'); ?>"> <INPUT TYPE="button" CLASS="button" OnClick="location.href='users.php'" VALUE="<? echo _('No'); ?>"></FORM>
        <?
        include_once("inc/footer.inc.php");
} 
else 
{
        message("Nothing to do!");
}

