<script type="text/javascript">
    $(function () {
        <?php if (getValue('dbDriver', 'pdo_mysql') !== 'pdo_sqlite'): ?>
        $(".sqlite").addClass("hide");
        <?php else: ?>
        $(".mysql_pgsql").addClass("hide");
        <?php endif; ?>
    });
</script>
