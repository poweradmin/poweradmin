<?
global $db;
if(is_object($db))
{
	 $db->disconnect();
}

?>
  </div> <!-- /content -->
  <div class="footer">
   <a href="https://rejo.zenger.nl/poweradmin/">a complete(r) <strong>poweradmin</strong></a> - <a href="credits.php">credits</a>
  </div>
<?
if(file_exists('inc/custom_footer.inc.php')) 
{
	include('inc/custom_footer.inc.php');
}
?>
 </body>
</html>
