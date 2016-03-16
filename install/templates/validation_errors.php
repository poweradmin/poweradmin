<?php if (isset($validator) && count($validator->errors()) > 0): ?>
    <div class="alert alert-danger">
        <?php foreach ($validator->errors() as $field => $error): ?>
            <?=implode('<br>', $error) ?><br>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

