<h3>{$L_Welcome} {$D_Name}</h3>
<ul>
	<li><a href="index.php">{$L_Index}</a></li>
	{if $perm_search == "1"}
	<li><a href="search.php">{$L_SearchZonesAndRecords}</a></li>
	{/if}
	{if $perm_view_zone_own == "1" or $perm_view_zone_other == "1"}
	<li><a href="list_zones.php">{$L_ListZones}</a></li>
	{/if}
	{if $perm_zone_master_add}
	<li><a href="list_zone_templ.php">{$L_ListZoneTemplates}</a></li>
	{/if}
	{if $perm_supermaster_view=="1"}
	<li><a href="list_supermasters.php">{$L_ListSupermasters}</a></li>
	{/if}
	{if $perm_zone_master_add=="1"}
	<li><a href="add_zone_master.php">{$L_AddMasterZone}</a></li>
	{/if}
	{if $perm_zone_slave_add=="1"}
	<li><a href="add_zone_slave.php">{$L_AddSlaveZone}</a></li>
	{/if}
	{if $perm_supermaster_add=="1"}
	<li><a href="add_supermaster.php">{$L_AddSupermaster}</a></li>
	{/if}
	<li><a href="change_password.php">{$L_ChangePassword}</a></li>
	<li><a href="users.php">{$L_UserAdministration}</a></li>
	<li><a href="index.php?logout">{$L_Logout}</a></li>
</ul>
