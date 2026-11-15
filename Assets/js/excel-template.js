(function () {
    'use strict';

    // 1) 목록 탭: "Apply action" 드롭다운에 "엑셀 양식으로 내보내기" 추가
    //    (data-list-item-action="modal" 이면 Kanboard 가 선택된 task_ids 를 붙여 모달을 열어 줌)
    document.addEventListener('DOMContentLoaded', function () {
        var config = document.getElementById('excel-template-list-action');
        var menu = document.querySelector('.list-item-actions .dropdown ul');

        if (! config || ! menu) {
            return;
        }

        var item = document.createElement('li');
        var link = document.createElement('a');
        link.href = config.getAttribute('data-url');
        link.setAttribute('data-list-item-action', 'modal');
        link.textContent = config.getAttribute('data-label');
        item.appendChild(link);
        menu.appendChild(item);
    });

    function closest(element, selector) {
        while (element && element.nodeType === 1) {
            if (element.matches(selector)) {
                return element;
            }
            element = element.parentNode;
        }
        return null;
    }

    // 2) 모달의 다운로드 버튼: 모달의 AJAX 제출 대신 일반 제출(파일 다운로드)
    document.addEventListener('click', function (event) {
        var button = closest(event.target, '.js-excel-template-export');
        if (! button) {
            return;
        }

        event.preventDefault();
        var form = closest(button, 'form');
        if (form) {
            form.submit();
            window.setTimeout(function () {
                if (window.KB && KB.trigger) {
                    KB.trigger('modal.close');
                }
            }, 800);
        }
    });

    // 3) 설정 화면: 매핑 행 추가 / 삭제 / 메타데이터 입력칸
    function addRow(placeholder) {
        var template = document.getElementById('excel-mapping-row-template');
        var body = document.getElementById('excel-mapping-rows');
        if (! template || ! body) {
            return null;
        }

        var holder = document.createElement('tbody');
        holder.innerHTML = template.innerHTML;
        var row = holder.querySelector('tr');
        body.appendChild(row);

        if (placeholder) {
            row.querySelector('.excel-mapping-placeholder').value = '{{' + placeholder + '}}';
            row.querySelector('.excel-mapping-field').focus();
        }

        return row;
    }

    document.addEventListener('click', function (event) {
        var target = event.target;

        if (closest(target, '.js-excel-mapping-add')) {
            event.preventDefault();
            addRow('');
            return;
        }

        var chip = closest(target, '.js-excel-mapping-add-placeholder');
        if (chip) {
            event.preventDefault();
            addRow(chip.getAttribute('data-placeholder'));
            chip.classList.add('excel-template-chip-pending');
            chip.disabled = true;
            return;
        }

        var remove = closest(target, '.js-excel-mapping-remove');
        if (remove) {
            event.preventDefault();
            var row = closest(remove, 'tr');
            var body = document.getElementById('excel-mapping-rows');
            if (row && body) {
                if (body.querySelectorAll('tr').length > 1) {
                    row.parentNode.removeChild(row);
                } else {
                    row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                    row.querySelector('select').value = '';
                    row.querySelector('.excel-mapping-meta').style.display = 'none';
                }
            }
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('.excel-mapping-field')) {
            var meta = closest(event.target, 'td').querySelector('.excel-mapping-meta');
            if (meta) {
                meta.style.display = event.target.value === 'meta' ? '' : 'none';
                if (event.target.value === 'meta') {
                    meta.focus();
                }
            }
        }
    });
})();
