<div class="content">
<h2>{$L_LOGIN}</h2>
{if $D_MSG}
	<div class="{$D_TYPE}">{$D_MSG}</div>
{/if}
<form method="post" action="{$S_PHP_SELF}" name="login">
 <table border="0">
  <tr>
   <td class="n">{$L_LOGIN}:</td>
   <td class="n"><input type="text" class="input" name="username" /></td>
  </tr>
  <tr>
   <td class="n">{$L_PASSWORD}:</td>
   <td class="n"><input type="password" class="input" name="password" /></td>
  </tr>
  <tr>
   <td class="n">&nbsp;</td>
   <td class="n">
    <input type="submit" name="authenticate" class="button" value="{$L_LOGIN}" />
   </td>
  </tr>
 </table>
</form>
<script type="text/javascript">
<!--
	document.login.username.focus();
//-->
</script>
</div>