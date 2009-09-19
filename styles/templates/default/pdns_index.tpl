<h3>{$L_WELCOME} {$D_NAME}</h3>
<ul>
	<li><a href="index.php">{$L_INDEX}</a></li>
	{if $PERM_SEARCH==1}
		<li><a href="search.php">{$L_SEARCH}</a></li>
	{/if}
	{if $PERM_VIEW_ZONE_OWN==1 || $PERM_VIEW_ZONE_OTHER==1}
		<li><a href="list_zones.php">{$L_LIST_ZONES}</a></li>
	{/if}
	{if $PERM_ZONE_MASTER_ADD}
		<li><a href="list_zone_templ.php">{$L_LIST_ZONE_TEMPLATES}</a></li>
	{/if}
	{if $PERM_SUPERMASTER_VIEW}
		<li><a href="list_supermasters.php">{$L_LIST_SUPERMASTERS}</a></li>
	{/if}
	{if $PERM_ZONE_MASTER_ADD}
		<li><a href="add_zone_master.php">{$L_ADD_MASTER_ZONE}</a></li>
	{/if}
	{if $PERM_ZONE_SLAVE_ADD}
		<li><a href="add_zone_slave.php">{$L_ADD_SLAVE_ZONE}</a></li>
	{/if}
	{if $PERM_SUPERMASTER_ADD}
		<li><a href="add_supermaster.php">{$L_ADD_SUPERMASTER}</a></li>
	{/if}
	<li><a href="change_password.php">{$L_CHANGE_PASSWORD}</a></li>
	<li><a href="users.php">{$L_USER_ADMINISTRATION}</a></li>
	<li><a href="index.php?logout">{$L_LOGOUT}</a></li>
</ul>