<?
global $db;
if(is_object($db))
{
	 $db->disconnect();
}

 $svn_rev = '$LastChangedRevision$ $LastChangedDate$ $Author$';
 $svn_rev = preg_split("/[\s,]+/", $svn_rev);
 $revision = "revision $svn_rev[1] (commited at $svn_rev[4] by $svn_rev[13])";

?>
  </div> <!-- /content -->
  <div class="footer">
   <strong>poweradmin</strong> <? echo $revision;?> - <a href="credits.php"><? echo _('credits'); ?></a>
  </div>
<?
if(file_exists('inc/custom_footer.inc.php')) 
{
	include('inc/custom_footer.inc.php');
}
?>
 </body>
</html>
