<h1>Poweradmin</h1>
{if $S_DISPLAY_MENU}
	<div class="menu">
		<span class="menuitem"><a href="index.php">{$L_INDEX}</a></span>
		{if $PERM_SEARCH==1}
			<span class="menuitem"><a href="search.php">{$L_SEARCH}</a></span>			
		{/if} 
		{if $PERM_VIEW_ZONE_OWN==1 || $PERM_VIEW_ZONE_OTHER==1}
			<span class="menuitem"><a href="list_zones.php">{$L_LIST_ZONES}</a></span>
		{/if}
		{if $PERM_ZONE_MASTER_ADD==1}
			<span class="menuitem"><a href="list_zone_templ.php">{$L_LIST_ZONE_TEMPLATES}</a></span>
		{/if}
		{if $PERM_SUPERMASTER_VIEW==1}
			<span class="menuitem"><a href="list_supermasters.php">{$L_LIST_SUPERMASTERS}</a></span>
		{/if}
		{if $PERM_ZONE_MASER_ADD==1}
			<span class="menuitem"><a href="add_zone_master.php">{$L_ADD_MASTER_ZONE}</a></span>
		{/if}
		{if $PERM_ZONE_SLAVE_ADD==1}
			<span class="menuitem"><a href="add_zone_slave.php">{$L_ADD_SLAVE_ZONE}</a></span>
		{/if}
		{if $PERM_SUPERMASTER_ADD==1}
			<span class="menuitem"><a href="add_supermaster.php">{$L_ADD_SUPERMASTER}</a></span>
		{/if}
		<span class="menuitem"><a href="change_password.php">{$L_CHANGE_PASSWORD}</a></span>
		<span class="menuitem"><a href="users.php">{$L_USER_ADMINISTRATION}</a></span>
		<span class="menuitem"><a href="index.php?logout">{$L_LOGOUT}</a></span>
	</div>
	<!-- /menu -->	
{/if}