<?php

namespace CodeMina\AjaxTable;

use CActiveRecord;
use CController;
use CDbCriteria;
use CException;
use CHttpRequest;
use CPagination;
use stdClass;
use Yii;
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
 * @property CPagination $_pages
 * @property CDbCriteria $_criteria
 * @property integer $_count
 * @property integer $_draw
 * @property integer $_filtered
 * @property integer $_length
 * @property integer $_start
 * @property array $_actions
 * @property stdClass $_search
 * @property stdClass $_data
 * @property array $_columns
 * @property array $_sort
 * @property mixed|CActiveRecord $_className
 * @property CHttpRequest $_request
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
    private $_columns = [];
    private $_sort;
    private $_with = [];
    private $_controller;
    private $_actionView;
    private $_table = null;

    /**
     * AjaxDataTable constructor.
     * @param mixed|CActiveRecord $className
     * @param CController $controller
     * @param string|null $actionView
     * @param array $with
     * @param CDbCriteria|null $criteria
     * @throws CException
     */
    public function __construct(string $className, CController $controller, string $actionView = null, $with = [], CDbCriteria $criteria = null)
    {
        $this->_controller = $controller;
        $this->_actionView = $actionView;

        if ((new $className) instanceof CActiveRecord) {
            $this->init($criteria);

            $this->_className = $className;
            $this->_count = $className::model()->with($with)->count($this->_criteria);
            $this->_pages = new CPagination($this->_count);
        } else {
            throw new CException('Model is not instance of CActiveRecord', 500);
        }
    }

    /**
     * @param CDbCriteria|null $criteria
     */
    private function init(?CDbCriteria $criteria)
    {
        $this->_request = Yii::app()->request;

        $this->_length = (int)$this->_request->getQuery('length');
        $this->_start = (int)$this->_request->getQuery('start');
        $this->_draw = (int)$this->_request->getQuery('draw');
        $this->_search = (object)$this->_request->getQuery('search');
        $this->_columns = $this->_request->getQuery('columns');
        $this->_sort = $this->_request->getQuery('order');

        $this->_criteria = $criteria ?? new CDbCriteria();
        $this->_data = new stdClass();
    }

    /**
     * @return CDbCriteria
     */
    public function getCriteria(): CDbCriteria
    {
        return $this->_criteria;
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
        if (is_object($this->_search) && !empty($this->_search->value)) {
            foreach ($fields as $field) {
                if (is_array($field)) {
                    $items = Yii::app()->evaluateExpression($field[1]);
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
        }
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

            $data = $this->_table ? $record->getColumns($this->_table) : $record->getColumns();

            if (!is_null($this->_actionView)) {
                $data['actions'] = $this->buildActionsView($record);
            }

            $this->_data->data[] = $data;
        }

        echo json_encode($this->_data);
    }

    private function setupPage()
    {
        $this->_filtered = (int)$this->_className::model()->with($this->_with)->count($this->_criteria);

        if (!is_null($this->_sort)) {
            $column = $this->_columns[(int)$this->_sort[0]['column']]['name'];
            $dir = $this->_sort[0]['dir'];

            if (!empty($column) && !empty($dir)) {
                $this->_criteria->order = "{$column} {$dir}";
            }
        }

        $this->_pages->setPageSize($this->getPageSize());
        $this->_pages->setCurrentPage($this->getCurrentPage());
        $this->_pages->applyLimit($this->_criteria);
    }

    /**
     * @return int
     */
    private function getPageSize(): int
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
        $this->_data->recordsTotal = (int) $this->_count;
        $this->_data->recordsFiltered = (int) $this->_filtered;
    }

    /**
     * @param CActiveRecord $model
     * @return string|null
     * @throws CException
     */
    private function buildActionsView(CActiveRecord $model): ?string
    {
        return $this->_controller->renderPartial($this->_actionView, [
            'model' => $model
        ], true);
    }

    /**
     * @param mixed $table
     */
    public function setTable($table): void
    {
        $this->_table = $table;
    }
}