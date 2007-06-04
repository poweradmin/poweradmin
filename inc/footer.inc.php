<?

global $db;
if(is_object($db))
{
	 $db->disconnect();
}

?>
  </div> <!-- /content -->
  <div class="footer">
   <strong>poweradmin</strong> version 1.4.0 - <a href="credits.php"><? echo _('credits'); ?></a>
  </div>
<?
if(file_exists('inc/custom_footer.inc.php')) 
{
	include('inc/custom_footer.inc.php');
}
?>
 </body>
</html>
