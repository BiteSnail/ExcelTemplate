<?php

namespace Kanboard\Plugin\ExcelTemplate\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ExcelTemplate\Library\XlsxTemplate;

/**
 * 엑셀 양식 저장소: 양식 파일 + "Kanboard 항목 → {{치환값}}" 매핑
 */
class ExcelTemplateModel extends Base
{
    const TABLE = 'excel_templates';
    const STORAGE_PREFIX = 'excel_templates/';

    const MODE_ROWS = 'rows';
    const MODE_SHEETS = 'sheets';

    /**
     * 매핑에 쓸 수 있는 Kanboard 항목
     *
     * @return array [ key => [label, scope(item|common)] ]
     */
    public function getFieldCatalog()
    {
        return array(
            // 태스크 항목 (태스크마다 다른 값 → 통합 출력 시 반복 기준)
            'id' => array(t('Task ID'), 'item'),
            'seq' => array(t('Sequence (No.)'), 'item'),
            'title' => array(t('Title'), 'item'),
            'description' => array(t('Description'), 'item'),
            'reference' => array(t('Reference'), 'item'),
            'project_name' => array(t('Project'), 'item'),
            'column_title' => array(t('Column'), 'item'),
            'swimlane_name' => array(t('Swimlane'), 'item'),
            'category_name' => array(t('Category'), 'item'),
            'assignee' => array(t('Assignee'), 'item'),
            'assignee_username' => array(t('Assignee username'), 'item'),
            'creator' => array(t('Creator'), 'item'),
            'status' => array(t('Status'), 'item'),
            'priority' => array(t('Priority'), 'item'),
            'score' => array(t('Complexity'), 'item'),
            'color' => array(t('Color'), 'item'),
            'tags' => array(t('Tags'), 'item'),
            'date_due' => array(t('Due date'), 'item'),
            'date_due_time' => array(t('Due date and time'), 'item'),
            'due_time' => array(t('Due time (HH:MM)'), 'item'),
            'date_due_weekday' => array(t('Due weekday'), 'item'),
            'date_started' => array(t('Start date'), 'item'),
            'date_creation' => array(t('Creation date'), 'item'),
            'date_modification' => array(t('Modification date'), 'item'),
            'date_completed' => array(t('Completion date'), 'item'),
            'time_estimated' => array(t('Time estimated'), 'item'),
            'time_spent' => array(t('Time spent'), 'item'),
            'subtasks' => array(t('Subtasks (one per line)'), 'item'),
            'url' => array(t('Task link'), 'item'),
            'meta' => array(t('Task metadata (enter key)'), 'item'),

            // 문서 공통 (한 번만 쓰는 값)
            '_today' => array(t('Today'), 'common'),
            '_now' => array(t('Current date and time'), 'common'),
            '_count' => array(t('Number of selected tasks'), 'common'),
            '_user' => array(t('Exported by'), 'common'),
            '_project' => array(t('Current project'), 'common'),
            '_due_min' => array(t('Earliest due date'), 'common'),
            '_due_max' => array(t('Latest due date'), 'common'),
            '_template' => array(t('Template name'), 'common'),
        );
    }

    public function getModes()
    {
        return array(
            self::MODE_ROWS => t('Repeat rows (one table)'),
            self::MODE_SHEETS => t('Repeat sheets (one sheet per task)'),
        );
    }

    public function getFieldScope($field)
    {
        if (strpos($field, 'meta:') === 0) {
            return 'item';
        }

        $catalog = $this->getFieldCatalog();

        return isset($catalog[$field]) ? $catalog[$field][1] : null;
    }

    public function getFieldLabel($field)
    {
        if (strpos($field, 'meta:') === 0) {
            return t('Metadata').': '.substr($field, 5);
        }

        $catalog = $this->getFieldCatalog();

        return isset($catalog[$field]) ? $catalog[$field][0] : $field;
    }

    /**
     * "{{ 배포 항목 }}" / "배포 항목" -> "배포 항목"
     */
    public static function normalizePlaceholder($value)
    {
        $value = trim((string) $value);

        if (preg_match('/^\{\{\s*(.*?)\s*\}\}$/u', $value, $m)) {
            $value = $m[1];
        }

        return trim(str_replace(array('{', '}'), '', $value));
    }

    // ------------------------------------------------------------------
    // 조회
    // ------------------------------------------------------------------

    public function getAll()
    {
        return array_map(array($this, 'decode'), $this->db->table(self::TABLE)->asc('name')->findAll());
    }

    public function getById($id)
    {
        $row = $this->db->table(self::TABLE)->eq('id', $id)->findOne();

        return $row ? $this->decode($row) : null;
    }

    /**
     * 프로젝트에서 쓸 수 있는 양식 (적용 프로젝트를 비워두면 모든 프로젝트)
     */
    public function getForProject($project_id)
    {
        return array_values(array_filter($this->getAll(), function ($template) use ($project_id) {
            return empty($template['project_ids']) || in_array((int) $project_id, $template['project_ids'], true);
        }));
    }

    private function decode(array $row)
    {
        $mappings = json_decode((string) $row['mappings'], true);
        $row['mappings'] = is_array($mappings) ? $mappings : array();
        $row['project_ids'] = $row['project_ids'] === null || $row['project_ids'] === ''
            ? array()
            : array_map('intval', explode(',', $row['project_ids']));

        return $row;
    }

    // ------------------------------------------------------------------
    // 입력값 정리 / 검증
    // ------------------------------------------------------------------

    /**
     * 폼 값(mappings[field][], mappings[meta][], mappings[placeholder][])을 매핑 목록으로
     */
    public function parseMappings(array $values)
    {
        $result = array();
        $fields = isset($values['mappings']['field']) ? (array) $values['mappings']['field'] : array();
        $metas = isset($values['mappings']['meta']) ? (array) $values['mappings']['meta'] : array();
        $placeholders = isset($values['mappings']['placeholder']) ? (array) $values['mappings']['placeholder'] : array();

        foreach ($fields as $i => $field) {
            $field = trim((string) $field);
            $placeholder = self::normalizePlaceholder(isset($placeholders[$i]) ? $placeholders[$i] : '');

            if ($field === '' && $placeholder === '') {
                continue;
            }

            if ($field === 'meta') {
                $field = 'meta:'.trim(isset($metas[$i]) ? (string) $metas[$i] : '');
            }

            $result[] = array('field' => $field, 'placeholder' => $placeholder);
        }

        return $result;
    }

    public function validate(array $values, array $mappings, $isNew, array $file)
    {
        $errors = array();

        if (trim(isset($values['name']) ? $values['name'] : '') === '') {
            $errors['name'][] = t('The name is required');
        }

        $uploaded = ! empty($file['tmp_name']) && isset($file['error']) && $file['error'] === UPLOAD_ERR_OK;

        if ($isNew && ! $uploaded) {
            $errors['template_file'][] = t('Please upload an .xlsx file');
        } elseif ($uploaded) {
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                $errors['template_file'][] = t('Only .xlsx files are supported');
            } elseif (! XlsxTemplate::isValid($file['tmp_name'])) {
                $errors['template_file'][] = t('This file is not a valid Excel workbook');
            }
        } elseif (! empty($file['name']) && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors['template_file'][] = t('Upload failed');
        }

        $seen = array();
        foreach ($mappings as $mapping) {
            if ($mapping['field'] === '' || $this->getFieldScope($mapping['field']) === null || $mapping['field'] === 'meta:') {
                $errors['mappings'][] = t('Choose a Kanboard field for "%s"', $mapping['placeholder'] ?: '?');
            }

            if ($mapping['placeholder'] === '') {
                $errors['mappings'][] = t('Enter the Excel placeholder for "%s"', $this->getFieldLabel($mapping['field']));
            } elseif (isset($seen[$mapping['placeholder']])) {
                $errors['mappings'][] = t('The placeholder {{%s}} is mapped more than once', $mapping['placeholder']);
            }

            $seen[$mapping['placeholder']] = true;
        }

        if (isset($values['combine_mode']) && ! array_key_exists($values['combine_mode'], $this->getModes())) {
            $errors['combine_mode'][] = t('Invalid value');
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // 저장
    // ------------------------------------------------------------------

    public function save(array $values, array $mappings, array $file, $id = 0)
    {
        $existing = $id ? $this->getById($id) : null;

        $projectIds = array();
        if (! empty($values['project_ids'])) {
            foreach ((array) $values['project_ids'] as $pid) {
                if ((int) $pid > 0) {
                    $projectIds[] = (int) $pid;
                }
            }
        }

        $row = array(
            'name' => trim($values['name']),
            'description' => trim(isset($values['description']) ? $values['description'] : ''),
            'filename_pattern' => trim(isset($values['filename_pattern']) ? $values['filename_pattern'] : ''),
            'combine_mode' => isset($values['combine_mode']) && $values['combine_mode'] === self::MODE_SHEETS ? self::MODE_SHEETS : self::MODE_ROWS,
            'project_ids' => implode(',', array_unique($projectIds)),
            'mappings' => json_encode(array_values($mappings), JSON_UNESCAPED_UNICODE),
            'updated_at' => time(),
        );

        if (! empty($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
            $key = self::STORAGE_PREFIX.bin2hex(random_bytes(16)).'.xlsx';
            $blob = file_get_contents($file['tmp_name']);
            $this->objectStorage->put($key, $blob);
            $row['file_key'] = $key;
            $row['file_name'] = basename($file['name']);

            if ($existing) {
                $this->removeFile($existing['file_key']);
            }
        }

        if ($existing) {
            return $this->db->table(self::TABLE)->eq('id', $id)->update($row) ? (int) $id : false;
        }

        $row['created_at'] = time();

        return $this->db->table(self::TABLE)->persist($row);
    }

    public function remove($id)
    {
        $template = $this->getById($id);

        if (! $template) {
            return false;
        }

        $this->removeFile($template['file_key']);

        return $this->db->table(self::TABLE)->eq('id', $id)->remove();
    }

    private function removeFile($key)
    {
        try {
            $this->objectStorage->remove($key);
        } catch (\Exception $e) {
            $this->logger->error('ExcelTemplate: unable to remove '.$key.': '.$e->getMessage());
        }
    }

    public function getFileContent(array $template)
    {
        return $this->objectStorage->get($template['file_key']);
    }

    /**
     * 양식 파일을 임시 파일로 꺼냄 (호출한 쪽에서 삭제)
     */
    public function getTemporaryFile(array $template)
    {
        $path = tempnam(sys_get_temp_dir(), 'kbxt');
        file_put_contents($path, $this->getFileContent($template));

        return $path;
    }

    /**
     * 양식 안의 {{치환값}} 목록
     */
    public function scanPlaceholders(array $template)
    {
        $path = $this->getTemporaryFile($template);

        try {
            return XlsxTemplate::scan($path);
        } catch (\Exception $e) {
            return array();
        } finally {
            @unlink($path);
        }
    }
}
