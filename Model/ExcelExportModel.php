<?php

namespace Kanboard\Plugin\ExcelTemplate\Model;

use Kanboard\Core\Base;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\ExcelTemplate\Library\XlsxTemplate;
use RuntimeException;
use ZipArchive;

/**
 * 선택한 태스크로 엑셀 양식 채우기
 *
 * @property ExcelTemplateModel $excelTemplateModel
 */
class ExcelExportModel extends Base
{
    const OUTPUT_EACH = 'each';
    const OUTPUT_COMBINED = 'combined';
    const MAX_TASKS = 500;

    /**
     * @return array ['filename' => string, 'content' => binary, 'type' => 'xlsx'|'zip']
     */
    public function export(array $template, array $tasks, $output, array $project, array $user)
    {
        if (empty($tasks)) {
            throw new RuntimeException(t('No task selected'));
        }

        $itemMappings = array();
        $commonMappings = array();

        foreach ($template['mappings'] as $mapping) {
            if ($this->excelTemplateModel->getFieldScope($mapping['field']) === 'common') {
                $commonMappings[] = $mapping;
            } else {
                $itemMappings[] = $mapping;
            }
        }

        $common = $this->buildCommonValues($commonMappings, $template, $tasks, $project, $user);
        $items = array();

        foreach (array_values($tasks) as $i => $task) {
            $items[] = $this->buildItemValues($itemMappings, $task, $i + 1);
        }

        $itemKeys = array_map(function ($m) { return $m['placeholder']; }, $itemMappings);
        $templatePath = $this->excelTemplateModel->getTemporaryFile($template);

        try {
            if ($output === self::OUTPUT_EACH && count($items) > 1) {
                return $this->exportEach($template, $templatePath, $common, $items, $itemKeys);
            }

            $content = $this->render($templatePath, $common, $items, $itemKeys, $output === self::OUTPUT_EACH ? 'rows' : $template['combine_mode']);

            return array(
                'filename' => $this->buildFilename($template, array_merge($common, $items[0]), 'xlsx'),
                'content' => $content,
                'type' => 'xlsx',
            );
        } finally {
            @unlink($templatePath);
        }
    }

    private function exportEach(array $template, $templatePath, array $common, array $items, array $itemKeys)
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'kbxz');
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create zip file');
        }

        $used = array();

        foreach ($items as $item) {
            $name = $this->buildFilename($template, array_merge($common, $item), 'xlsx');
            $base = substr($name, 0, -5);
            $n = 2;

            while (isset($used[mb_strtolower($name)])) {
                $name = $base.' ('.$n++.').xlsx';
            }

            $used[mb_strtolower($name)] = true;
            $zip->addFromString($name, $this->render($templatePath, $common, array($item), $itemKeys, 'rows'));
        }

        $zip->close();
        $content = file_get_contents($zipPath);
        @unlink($zipPath);

        return array(
            'filename' => self::sanitizeFilename($template['name']).'_'.date('Ymd_His').'.zip',
            'content' => $content,
            'type' => 'zip',
        );
    }

    private function render($templatePath, array $common, array $items, array $itemKeys, $mode)
    {
        $outPath = tempnam(sys_get_temp_dir(), 'kbxo');

        try {
            $engine = new XlsxTemplate($templatePath, $outPath);
            $engine->render($common, $items, $itemKeys, $mode);

            return file_get_contents($outPath);
        } finally {
            @unlink($outPath);
        }
    }

    // ------------------------------------------------------------------
    // 값 만들기
    // ------------------------------------------------------------------

    /**
     * 선택 순서를 유지하며 프로젝트 소속 태스크만 가져옴
     */
    public function getTasks(array $taskIds, $project_id)
    {
        $tasks = array();

        foreach (array_slice(array_unique(array_map('intval', $taskIds)), 0, self::MAX_TASKS) as $taskId) {
            $task = $taskId > 0 ? $this->taskFinderModel->getDetails($taskId) : null;

            if ($task && (int) $task['project_id'] === (int) $project_id) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    private function buildCommonValues(array $mappings, array $template, array $tasks, array $project, array $user)
    {
        $dues = array_filter(array_map(function ($t) { return (int) $t['date_due']; }, $tasks));
        $values = array();

        foreach ($mappings as $mapping) {
            switch ($mapping['field']) {
                case '_today':
                    $value = date('Y-m-d');
                    break;
                case '_now':
                    $value = date('Y-m-d H:i');
                    break;
                case '_count':
                    $value = count($tasks);
                    break;
                case '_user':
                    $value = ! empty($user['name']) ? $user['name'] : $user['username'];
                    break;
                case '_project':
                    $value = $project['name'];
                    break;
                case '_due_min':
                    $value = empty($dues) ? '' : date('Y-m-d', min($dues));
                    break;
                case '_due_max':
                    $value = empty($dues) ? '' : date('Y-m-d', max($dues));
                    break;
                case '_template':
                    $value = $template['name'];
                    break;
                default:
                    $value = '';
            }

            $values[$mapping['placeholder']] = $value;
        }

        return $values;
    }

    private function buildItemValues(array $mappings, array $task, $seq)
    {
        $values = array();
        $cache = array();

        foreach ($mappings as $mapping) {
            $values[$mapping['placeholder']] = $this->getTaskValue($mapping['field'], $task, $seq, $cache);
        }

        return $values;
    }

    private function getTaskValue($field, array $task, $seq, array &$cache)
    {
        if (strpos($field, 'meta:') === 0) {
            if (! isset($cache['meta'])) {
                $cache['meta'] = $this->taskMetadataModel->getAll($task['id']);
            }
            $key = substr($field, 5);
            return isset($cache['meta'][$key]) ? (string) $cache['meta'][$key] : '';
        }

        switch ($field) {
            case 'id':
                return (int) $task['id'];
            case 'seq':
                return (int) $seq;
            case 'title':
            case 'description':
            case 'reference':
            case 'project_name':
            case 'column_title':
            case 'swimlane_name':
            case 'category_name':
            case 'assignee_username':
                return (string) $task[$field];
            case 'assignee':
                return (string) ($task['assignee_name'] ?: $task['assignee_username']);
            case 'creator':
                return (string) ($task['creator_name'] ?: $task['creator_username']);
            case 'status':
                return $task['is_active'] == TaskModel::STATUS_OPEN ? t('Open') : t('Closed');
            case 'priority':
            case 'score':
                return (int) $task[$field];
            case 'color':
                $colors = $this->colorModel->getList();
                return isset($colors[$task['color_id']]) ? $colors[$task['color_id']] : (string) $task['color_id'];
            case 'tags':
                return implode(', ', array_values($this->taskTagModel->getList($task['id'])));
            case 'date_due':
            case 'date_started':
            case 'date_creation':
            case 'date_modification':
            case 'date_completed':
                return self::formatDate($task[$field], 'Y-m-d');
            case 'date_due_time':
                return self::formatDate($task['date_due'], 'Y-m-d H:i');
            case 'due_time':
                return self::formatDate($task['date_due'], 'H:i');
            case 'date_due_weekday':
                return empty($task['date_due']) ? '' : $this->weekday((int) $task['date_due']);
            case 'time_estimated':
            case 'time_spent':
                return (float) $task[$field];
            case 'subtasks':
                $lines = array();
                foreach ($this->subtaskModel->getAll($task['id']) as $subtask) {
                    $who = ! empty($subtask['name']) ? $subtask['name'] : (isset($subtask['username']) ? $subtask['username'] : '');
                    $lines[] = '['.t($subtask['status_name']).'] '.$subtask['title'].($who ? ' - '.$who : '');
                }
                return implode("\n", $lines);
            case 'url':
                return $this->helper->url->to('TaskViewController', 'show', array('task_id' => $task['id'], 'project_id' => $task['project_id']), '', true);
        }

        return '';
    }

    private static function formatDate($timestamp, $format)
    {
        return empty($timestamp) ? '' : date($format, (int) $timestamp);
    }

    private function weekday($timestamp)
    {
        $w = (int) date('w', $timestamp);

        if (strpos($this->languageModel->getCurrentLanguage(), 'ko') === 0) {
            $names = array('일', '월', '화', '수', '목', '금', '토');
            return $names[$w];
        }

        return date('D', $timestamp);
    }

    // ------------------------------------------------------------------
    // 파일 이름
    // ------------------------------------------------------------------

    public function buildFilename(array $template, array $values, $extension)
    {
        $pattern = $template['filename_pattern'] !== '' ? $template['filename_pattern'] : $template['name'].'_'.date('Ymd');

        $name = preg_replace_callback(XlsxTemplate::PLACEHOLDER, function ($m) use ($values) {
            return array_key_exists($m[1], $values) ? (string) $values[$m[1]] : '';
        }, $pattern);

        $name = preg_replace('/\.xlsx$/i', '', $name);

        return self::sanitizeFilename($name).'.'.$extension;
    }

    public static function sanitizeFilename($name)
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', (string) $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name), " ._");
        $name = mb_substr($name, 0, 150);

        return $name === '' ? 'export' : $name;
    }
}
