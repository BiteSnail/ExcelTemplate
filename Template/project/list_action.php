<?php if ($this->app->getRouterController() === 'TaskListController'): ?>
<div id="excel-template-list-action" hidden
     data-url="<?= $this->url->href('ExcelExportController', 'show', array('plugin' => 'ExcelTemplate', 'project_id' => $project['id'])) ?>"
     data-label="<?= $this->text->e(t('Export selected tasks to an Excel template')) ?>"></div>
<?php endif ?>
