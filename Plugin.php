<?php

namespace Kanboard\Plugin\ExcelTemplate;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Core\Translator;

/**
 * ExcelTemplate - 태스크를 등록된 엑셀 양식(xlsx)에 채워서 내려받기
 */
class Plugin extends Base
{
    public function initialize()
    {
        // 설정 > 엑셀 양식 (관리자)
        $this->template->hook->attach('template:config:sidebar', 'ExcelTemplate:config/sidebar');

        // 목록 탭 "Apply action" 메뉴에 항목 추가 (JS 가 data-* 를 읽어 메뉴에 넣음)
        $this->template->hook->attach('template:project:header:after', 'ExcelTemplate:project/list_action');

        // 태스크 상세 사이드바: 이 태스크만 내보내기
        $this->template->hook->attach('template:task:sidebar:after-basic-actions', 'ExcelTemplate:task/sidebar');

        $this->hook->on('template:layout:js', array('template' => 'plugins/ExcelTemplate/Assets/js/excel-template.js'));
        $this->hook->on('template:layout:css', array('template' => 'plugins/ExcelTemplate/Assets/css/excel-template.css'));

        $this->applicationAccessMap->add('ExcelTemplateConfigController', '*', Role::APP_ADMIN);
        $this->projectAccessMap->add('ExcelExportController', '*', Role::PROJECT_VIEWER);
    }

    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
    }

    public function getClasses()
    {
        return array(
            'Plugin\ExcelTemplate\Model' => array(
                'ExcelTemplateModel',
                'ExcelExportModel',
            ),
        );
    }

    public function getPluginName()
    {
        return 'ExcelTemplate';
    }

    public function getPluginDescription()
    {
        return t('Fill registered Excel templates with selected tasks');
    }

    public function getPluginAuthor()
    {
        return 'Claude';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return '';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.20';
    }
}
