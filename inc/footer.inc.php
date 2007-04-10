<?

// +--------------------------------------------------------------------+
// | PowerAdmin                                                         |
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team                        |
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal   |
// | program PowerAdmin as found on http://poweradmin.sf.net            |
// | The PowerAdmin program falls under the QPL License:                |
// | http://www.trolltech.com/developer/licensing/qpl.html              |
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>       |
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>                      |
// +--------------------------------------------------------------------+

// Filename: footer.inc.php
// Startdate: beginning.
// Description: simple page footer
// Closes the database connection.
//
// $Id: footer.inc.php,v 1.12 2003/05/14 22:49:56 azurazu Exp $
//


global $db;
if(is_object($db))
{
	 $db->disconnect();
}

?>
<BR><BR>
<FONT CLASS="footer">[ <A HREF="index.php?logout">logout</A> ]&nbsp;<B>PowerAdmin v1.2.7</B> :: Copyright &copy;
<?= date("Y") ?> The PowerAdmin Team :: <a href="http://poweradmin.org" target="_blank">http://poweradmin.org</a>
</FONT>
</BODY>
</HTML>

