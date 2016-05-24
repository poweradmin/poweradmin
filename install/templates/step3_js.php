<script type="text/javascript">
    $(function () {
        <?php if (getValue('dbDriver', $config['defaultDatabaseDriver']) !== 'pdo_sqlite'): ?>
        $(".sqlite").addClass("hide");
        $("input[name='dbPort']").val(<?=getDbPortDefault($config['defaultDatabaseDriver']) ?>);
        <?php else: ?>
        $(".mysql_pgsql").addClass("hide");
        <?php endif; ?>
    });
</script>
