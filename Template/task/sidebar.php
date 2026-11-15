<li>
    <?= $this->modal->medium('file-excel-o', t('Export to Excel template'), 'ExcelExportController', 'show', array('plugin' => 'ExcelTemplate', 'project_id' => $task['project_id'], 'task_ids' => $task['id'])) ?>
</li>
