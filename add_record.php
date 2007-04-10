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
// $Id: add_record.php,v 1.6 2003/02/05 15:25:01 azurazu Exp $
//

require_once("inc/toolkit.inc.php");
if ($_POST["commit"]) {
        $ret = add_record($_POST["zoneid"], $_POST["name"], $_POST["type"], $_POST["content"], $_POST["ttl"], $_POST["prio"]);
        if ($ret != '1') {
                die("$ret");
        }
        clean_page("edit.php?id=".$_POST["zoneid"]);
}
include_once("inc/header.inc.php");
?>
<H2>Add record to zone "<?= get_domain_name_from_id($_GET["id"]) ?>"</H2>
<FONT CLASS="nav"><BR><A HREF="index.php">DNS Admin</A> &gt;&gt; <A HREF="edit.php?id=<?= $_GET["id"] ?>"><?= get_domain_name_from_id($_GET["id"]) ?></A> &gt;&gt; Add record<BR><BR></FONT>

<FORM METHOD="post">
<INPUT TYPE="hidden" NAME="zoneid" VALUE="<?= $_GET["id"] ?>">
<TABLE BORDER="0" CELLSPACING="4">
<TR STYLE="font-weight: Bold"><TD CLASS="tdbg">Name</TD><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg">Type</TD><TD CLASS="tdbg">Priority</TD><TD CLASS="tdbg">Content</TD><TD CLASS="tdbg">TimeToLive</TD></TR>
<TR><TD CLASS="tdbg"><INPUT TYPE="text" NAME="name" CLASS="input">.<?= get_domain_name_from_id($_GET["id"]) ?></TD><TD CLASS="tdbg">IN</TD><TD CLASS="tdbg"><SELECT NAME="type">
<?
$dname = get_domain_name_from_id($_GET["id"]);
foreach (get_record_types() as $c) {
        if (eregi('in-addr.arpa', $dname) && strtoupper($c) == 'PTR') {
                $add = " SELECTED";
        } elseif (strtoupper($c) == 'A') {
                $add = " SELECTED";
        } else {
                unset($add);
        }
        ?><OPTION<?= $add ?> VALUE="<?= $c ?>"><?= $c ?></OPTION><?
}
?></SELECT></TD><TD CLASS="tdbg"><INPUT TYPE="text" NAME="prio" CLASS="sinput"></TD><TD CLASS="tdbg"><INPUT TYPE="text" NAME="content" CLASS="input"></TD><TD CLASS="tdbg"><INPUT TYPE="text" NAME="ttl" CLASS="sinput" VALUE="<?=$DEFAULT_TTL?>"></TD></TR>
</TABLE>
<BR><INPUT TYPE="submit" NAME="commit" VALUE="Add record" CLASS="button">
</FORM>

<? include_once("inc/footer.inc.php"); ?>
