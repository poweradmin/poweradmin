<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">
<html>
	<head>
		<title>{$iface_title}</title>
		<link rel="stylesheet" href="style/{$iface_style}.css" type="text/css">
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	</head>
	<body>
		<h1>{$iface_title}</h1>
	{if $login}
		
		<div class="menu">
			<span class="menuitem"><a href="index.php">{$L_Index}</a></span>
			{if $perm_search=="1"}
			<span class="menuitem"><a href="search.php">{$L_SearchZonesAndRecords}</a></span>
			{/if} 
			{if $perm_view_zone_own=="1" or $perm_view_zone_other=="1"}
			<span class="menuitem"><a href="list_zones.php">{$L_ListZones}</a></span>
			{/if}
			{if $perm_zone_master_add=="1"}
			<span class="menuitem"><a href="list_zone_templ.php">{$L_ListZoneTemplates}</a></span>
			{/if}
			{if $perm_supermaster_view=="1"}
			<span class="menuitem"><a href="list_supermasters.php">{$L_ListSupermasters}</a></span>
			{/if}
			{if $perm_zone_master_add=="1"} 
			<span class="menuitem"><a href="add_zone_master.php">{$L_AddMasterZone}</a></span>
			{/if}
			{if $perm_zone_slave_add=="1"} 
			<span class="menuitem"><a href="add_zone_slave.php">{$L_AddSlaveZone}</a></span>
			{/if}
			{if $perm_suptermaster_add=="1"} 
			<span class="menuitem"><a href="add_supermaster.php">{$L_AddSupermaster}</a></span>
			{/if} 
			<span class="menuitem"><a href="change_password.php">{$L_ChangePassword}</a></span>
			<span class="menuitem"><a href="users.php">{$L_UserAdministration}</a></span>
			<span class="menuitem"><a href="index.php?logout">{$L_Logout}</a></span>
		</div>
		<!-- /menu -->
	{/if}
	<div class="content">