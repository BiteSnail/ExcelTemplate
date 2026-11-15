<li <?= $this->app->checkMenuSelection('ExcelTemplateConfigController') ?>>
    <?= $this->url->link(t('Excel templates'), 'ExcelTemplateConfigController', 'index', array('plugin' => 'ExcelTemplate')) ?>
</li>
