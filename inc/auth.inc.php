<?

session_start();

if (isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout")
{
	logout();
}

// If a user had just entered his/her login && password, store them in our session.
if(isset($_POST["authenticate"]))
{
    	$_SESSION["userpwd"] = $_POST["password"];
    	$_SESSION["userlogin"] = $_POST["username"];
}

// Check if the session hasnt expired yet.
if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $EXPIRE))
{
	logout( _('Session expired, please login again.'),"error");
}

// If the session hasn't expired yet, give our session a fresh new timestamp.
$_SESSION["lastmod"] = time();

if(isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"]))
{
    //Username and password are set, lets try to authenticate.
	$result = $db->query("SELECT id, fullname, level FROM users WHERE username='". $_SESSION["userlogin"]  ."' AND password='". md5($_SESSION["userpwd"])  ."' AND active=1");
	if($result->numRows() == 1)
	{
        	$rowObj = $result->fetchRow();
		$_SESSION["userid"] = $rowObj["id"];
		$_SESSION["name"] = $rowObj["fullname"];
		$_SESSION["level"] = $rowObj["level"];
        	if($_POST["authenticate"])
        	{
            		//If a user has just authenticated, redirect him to index with timestamp, so post-data gets lost.
            		session_write_close();
            		clean_page("index.php");
            		exit;
        	}
    	}
    	else
    	{
        	//Authentication failed, retry.
	        auth( _('Authentication failed!'),"error");
	}
}
else
{
	//No username and password set, show auth form (again).
	auth();
}

/*
 * Print the login form.
 */

function auth($msg="",$type="success")
{
	include_once('inc/header.inc.php');
	if ( $msg )
	{
		print "<div class=\"$type\">$msg</div>\n";
	}
	?>
	<h2><? echo _('Login'); ?></h2>
	<?
	?>
	<form method="post" action="<? echo $_SERVER["PHP_SELF"] ?>">
	 <table border="0">
	  <tr>
	   <td class="n"><? echo _('Login'); ?>:</td>
	   <td class="n"><input type="text" class="input" name="username"></td>
	  </tr>
	  <tr>
	   <td class="n"><? echo _('Password'); ?>:</td>
	   <td class="n"><input type="password" class="input" name="password"></td>
	  </tr>
	  <tr>
	   <td class="n">&nbsp;</td>
	   <td class="n">
	    <input type="submit" name="authenticate" class="button" value=" <? echo _('Login'); ?> ">
	   </td>
	  </tr>
	 </table>
	</form>
	<?
	include_once('inc/footer.inc.php');
	exit;
}


/*
 * Logout the user and kickback to login form.
 */

function logout($msg="")
{
	if ( $msg == "" ) {
		$msg = _('You have logged out.');
		$type = "success";
	};
	session_destroy();
	session_write_close();
	auth($msg, $type);
	exit;
}

?>
