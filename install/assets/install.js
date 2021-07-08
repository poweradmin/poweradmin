$(function () {
    $("select[name='locale']").change(function () {
        $("form[name='change_locale']").submit();
    });

    if ($("form[name='db_settings']").length) {
        $("select[name='dbDriver']").change(function () {
            switch (this.value) {
                case 'pdo_mysql':
                    $(".sqlite").addClass("hide");
                    $(".mysql_pgsql").removeClass("hide");
                    $("input[name='dbPort']").val("3306");
                    break;

                case 'pdo_pgsql':
                    $(".sqlite").addClass("hide");
                    $(".mysql_pgsql").removeClass("hide");
                    $("input[name='dbPort']").val("5432");
                    break;

                case 'pdo_sqlite':
                    $(".mysql_pgsql").addClass("hide");
                    $(".sqlite").removeClass("hide");
                    break;
            }
        });
    }
});