<!DOCTYPE html>
<html lang="<?= substr($parameters['locale'], 0, 2); ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?=_('Installation Step') . ' ' . $parameters['step']; ?>/5 - Poweradmin</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css"
          integrity="sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/install.css">

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
</head>
<body>

<?php if (defined('DEBUG')): ?>
    <div class="debug"><?=_('Debug-Mode is enabled!') ?></div>
<?php endif; ?>

<div class="container">
    <header class="row">
        <div class="col-xs-12 col-md-6"><img class="img-responsive" src="../images/logo_full.png"
                                             alt="Poweradmin Logo - Full"></div>
        <div class="clearfix visible-xs-block"></div>
        <div class="col-xs-12 col-md-6 text-right"><h3>Step <?= $parameters['step']; ?>/5</h3></div>
    </header>

    <section class="row content">