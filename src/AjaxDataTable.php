<?php

namespace CodeMina\AjaxTable;

use function Html\div;
use function Html\span;
use function Html\ul;
use function Html\li;
use function Html\a;
use function Html\button;
use function Html\raw;

/**
 * Class AjaxDataTable
 *
 * @author Ibrahim Haj <e.ibh2011@gmail.com>
 *
 * @property \CPagination $_pages
 * @property \CDbCriteria $_criteria
 * @property integer $_count
 * @property integer $_draw
 * @property integer $_filtered
 * @property integer $_length
 * @property integer $_start
 * @property array $_searchFields
 * @property array $_actions
 * @property stdClass $_search
 * @property stdClass $_data
 * @property array $_columns
 * @property array $_sort
 * @property mixed|\CActiveRecord $_className
 * @property \CHttpRequest $_request
 */
class AjaxDataTable
{
    private $_criteria;
    private $_pages;
    private $_draw;
    private $_request;
    private $_filtered;
    private $_count;
    private $_order = 'id DESC';
    private $_length;
    private $_start;
    private $_className;
    private $_data;
    private $_search;
    private $_searchFields = [];
    private $_actions = [];
    private $_columns = [];
    private $_sort;
    private $_with = [];

    /**
     * AjaxDataTable constructor.
     * @param mixed|\CActiveRecord $className
     * @throws \CException
     */
    public function __construct(string $className)
    {
        if ((new $className) instanceof \CActiveRecord) {
            $this->init();

            $this->_className = $className;
            $this->_count = $className::model()->count($this->_criteria);
            $this->_pages = new \CPagination($this->_count);
        } else {
            throw new \CException('Model is not instance of CActiveRecord', 500);
        }
    }

    private function init()
    {
        $this->_request = \Yii::app()->request;

        $this->_length = (int)$this->_request->getQuery('length');
        $this->_start = (int)$this->_request->getQuery('start');
        $this->_draw = (int)$this->_request->getQuery('draw');
        $this->_search = (object)$this->_request->getQuery('search');
        $this->_columns = $this->_request->getQuery('columns');
        $this->_sort = $this->_request->getQuery('order');

        $this->_criteria = new \CDbCriteria();
        $this->_data = new \stdClass();
    }

    /**
     * @param array $with
     */
    public function setWith(array $with): void
    {
        $this->_with = $with;
    }

    /**
     * @param mixed ...$fields
     */
    public function setSearchFields(...$fields)
    {
        $this->_searchFields = $fields;
    }

    /**
     * @param mixed $order
     */
    public function setOrder($order): void
    {
        $this->_order = $order;
    }

    /**
     * @throws CException
     */
    public function getResult()
    {
        $this->setupPage();

        $rs = $this->_className::model()->cache(1000)->with($this->_with)->findAll($this->_criteria);
        $this->setData();

        foreach ($rs as $key => $record) {
            if (!method_exists($record, 'getColumns')) {
                break;
            }

            $data = $record->getColumns();
            $data['actions'] = $this->buildActions($record);

            $this->_data->data[] = $data;
        }

        echo json_encode($this->_data);
    }

    private function setupPage()
    {
        if (is_object($this->_search) && !empty($this->_search->value) && !empty($this->_searchFields)) {
            foreach ($this->_searchFields as $field) {
                if (is_array($field)) {
                    $items = \Yii::app()->evaluateExpression($field[1]);
                    $indexes = [];
                    foreach ($items as $key => $item) {
                        if (mb_strpos($item, $this->_search->value) !== false) {
                            $indexes[] = $key;
                        }
                    }
                    if (!empty($indexes)) {
                        foreach ($indexes as $index) {
                            $this->_criteria->addSearchCondition($field[0], $index, true, 'OR');
                        }
                    }
                } else {
                    $this->_criteria->addSearchCondition($field, $this->_search->value, true, 'OR');
                }
            }
            $this->_filtered = (int) $this->_className::model()->with($this->_with)->count($this->_criteria);
        } else {
            $this->_filtered = $this->_count;
        }

        $column = $this->_columns[(int)$this->_sort[0]['column']]['name'];
        $dir = $this->_sort[0]['dir'];

        if (!empty($column) && !empty($dir)) {
            $this->_criteria->order = "{$column} {$dir}";
        }

        $this->_pages->setPageSize($this->getPageSize());
        $this->_pages->setCurrentPage($this->getCurrentPage());
        $this->_pages->applyLimit($this->_criteria);
    }

    /**
     * @return int
     */
    private function getPageSize()
    {
        return $this->_length < 0 ? $this->_count : $this->_length;
    }

    /**
     * @return float|int
     */
    private function getCurrentPage()
    {
        return $this->_length < 0 ? 0 : $this->_start / $this->_length;
    }

    private function setData()
    {
        $this->_data->data = [];
        $this->_data->draw = $this->_draw;
        $this->_data->recordsTotal = $this->_count;
        $this->_data->recordsFiltered = $this->_filtered;
    }

    /**
     * @param CActiveRecord $model
     * @return false|string
     */
    private function buildActions($model)
    {
        ob_start();

        echo div(
            ['class' => 'dropdown'],
            button(
                [
                    'class' => 'btn btn-secondary dropdown-toggle btn-sm',
                    'type' => 'button',
                    'aria-haspopup' => 'true',
                    'aria-expanded' => 'false'
                ],
                'Actions'
            )->data('toggle', 'dropdown'),
            div(
                [
                    'class' => 'dropdown-menu',
                    'aria-labelledby' => 'dropdownMenuButton'
                ],
                ...$this->actions($model)
            )
        );

        $actions = ob_get_contents();
        ob_end_clean();

        return $actions;
    }

    /**
     * @param CActiveRecord $model
     * @return array
     */
    private function actions($model)
    {
        $actions = [];
        foreach ($this->_actions as $action) {
            $a = a(
                [
                    'class' => 'dropdown-item client-action',
                    'href' => 'javascript:void(0)'
                ],
                raw($action['text'])
            );

            // Append id data property
            if (!isset($action['data']['id'])) {
                $action['data']['id'] = ['value' => 'id'];
            }

            foreach ($action['data'] as $key => $datum) {
                if (is_array($datum)) { // For dynamic data
                    $datum = (object)$datum;
                    $a->data($key, $model->handleDatum($datum->value));
                } else {
                    $a->data($key, $datum);
                }
            }

            // Setup rules
            if (isset($action['expression'])) {
                if (\Yii::app()->evaluateExpression($action['expression'], ['model' => $model])) {
                    $actions[] = $a;
                } else {
                    continue;
                }
            } else {
                $actions[] = $a;
            }
        }

        return $actions;
    }

    /**
     * @param array $actions
     */
    public function setActions(array $actions)
    {
        $this->_actions = $actions;
    }
}