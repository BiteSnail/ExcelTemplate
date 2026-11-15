<div class="page-header">
    <h2><?= t('Remove Excel template') ?></h2>
</div>

<div class="confirm">
    <p class="alert alert-info">
        <?= t('Do you really want to remove the Excel template "%s"?', $this->text->e($template['name'])) ?>
    </p>

    <?= $this->modal->confirmButtons(
        'ExcelTemplateConfigController',
        'remove',
        array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])
    ) ?>
</div>
