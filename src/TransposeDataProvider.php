<?php
/**
 * Created by Eddilbert Macharia (edd.cowan@gmail.com)<http://eddmash.com>
 * Date: 11/4/16.
 */

namespace Eddmash\TransposeDataProvider;

use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\data\ActiveDataProvider;
use yii\data\Pagination;
use yii\db\ActiveQuery;
use yii\db\ActiveQueryInterface;
use yii\db\Query;
use yii\db\QueryInterface;

/**
 * <h4>Transposing Query.</h4>
 *
 * Transposes data returned by a query.
 *
 * Assuming you have a Query that outputs the following  :
 * <pre>
 *
 * student | subject | grade
 * --------------------------
 *  mat    | cre     | 52
 *  mat    | ghc     | 40
 *  mat    | physics | 60
 *  leon   | cre     | 70
 *  leon   | ghc     | 80
 *  leon   | physics | 10
 *
 * </pre>
 *
 * and we need our data to look as below :
 *
 * <pre>
 *
 * student | cre | ghc | physics
 * ------------------------------
 *  mat    | 52  | 40  | 60
 *  leon   | 70  | 80  | 10
 *
 * </pre>
 *
 * We achive this by doing :
 *
 * ``` php
 *
 * use Eddmash\TransposeDataProvider;
 *
 * $dataProvider = new TransposeDataProvider([
 *      'query' => $query,
 *      'columnsField' => 'subject',
 *      'groupField' => 'student',
 *      'valuesField' => 'grade',
 *      'pagination' => [
 *          'pagesize' => $pageSize // in case you want a default pagesize
 *      ]
 * ]);
 *
 * ```
 *
 *
 * By default <strong> TransposeDataProvider::$columnsField</strong> the transposed output contains only the
 * columns found on the query.
 *
 * To get other columns present on the query add them to the <strong>TransposeDataProvider::$extraFields</strong>.
 *
 * <h4>Transposing EAV Data.</h4>
 *
 * The DataProvide also supports EAV setups, assuming we have the following setup.
 *
 *<pre>
 *
 *              Entity
 * ------------------------------
 * id   | name
 * -----------------
 *  1   | cre
 *  2   | ghc
 *  3   | physics
 *  4   | cre
 *  5   | ghc
 *  6   | physics
 *
 *
 *          Value
 * -----------------------------
 *
 * entity_id | attribute_id | value
 * ----------------------------------
 *  1        | 1            | 52
 *  2        | 2            | yes
 *  3        | 3            | 100
 *  4        | 4            | 70
 *  5        | 5            | it all sucks
 *  6        | 6            | 10
 *
 * Attribute
 * ----------------------------------
 *
 * name         | attribute_id
 * --------------------------
 *  maganize    |    1
 *  range       |    2
 *  power       |    3
 *  slogan      |    4
 *  song        |    5
 *  fire mode   |    6
 *
 *
 * <pre>
 *
 * To Get the following output ::
 * </pre>
 *
 * entity | magazine | range | power | slogan | song         | fire mode
 * ------------------------------------------------------------------------
 *   1    |   50     |  yes  | 100   | 70     | it all sucks | 10
 *
 * </pre>
 *
 *
 * Transpose takes another parameter $columnQuery which should return the columns.
 *
 * ``` php
 *
 * use Eddmash\TransposeDataProvider
 *
 * $query = Value::find()->joinWith(['attribute attribute', 'attribute.entity entity'])->where(['entity.id'=>5]);
 *
 * $columnQuery = Attribute::find()->joinWith(['entity entity'])->where(['entity.id'=>5]);
 *
 * $dataProvider = new TransposeDataProvider([
 *      'query' => $query,//
 *      'columnsField' => 'attribute.name',
 *      'groupField' => 'entity_id',
 *      'valuesField' => 'value',
 *      'columnsQuery' => $columnQuery,
 *      'pagination' => [
 *          'pagesize' => 10
 *      ]
 * ]);
 *
 * ```
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class TransposeDataProvider extends ActiveDataProvider
{
    /**
     * @var QueryInterface the query that is used to fetch data models and [[totalCount]]
     * if it is not explicitly set.
     */
    public $query;

    /**
     * This fields is used group together records in the $this->query into actual understandable rows of records.
     * e.g. student in the example above.
     *
     * @var
     */
    public $groupField;

    /**
     * The column in the columnQuery actually contains the records we need to use as column.
     *
     * This should be a string, it also accepts also a relationship separated by "dot notation" e.g user.name.
     *
     * NOTE :: this only accepts one level deep. so using the field user.role.permission will fail.
     * This is to allow the use of columnsQuery.
     *
     * also note that if the columnsQuery is used, the $columnsField should be present in both
     * the $query and the $columnQuery.
     *
     * in cases where the columnsField is a relationship e.g. "user.name" the data provider will look for the
     * end of the relationship in this case "name" on the $columnsQuery
     * and will look for the relation whole "user.name" in the data $query.
     *
     * @var
     */
    public $columnsField;

    /**
     * The column in the $this->query that actually contains the records we need to use as values for our columns.
     *
     * @var
     */
    public $valuesField;

    /**
     * Other columns found on the $this->query that should be added to the transposed output.
     *
     * For relational fields use the dot notation,  [student.role.name] this will add the role name of each student
     * to the transposed data.
     *
     * @var array
     */
    public $extraFields = [];

    /**
     *  The column to be used to get the labels for the column, use this incase the field used for $columnsField does
     * not consist of user friendly labels.
     *
     * @var
     */
    public $labelsField;

    /**
     * cache for columns.
     *
     * @var
     */
    private $_columns;

    /**
     * cache for rows.
     *
     * @var
     */
    private $_rows;

    /**
     * Query from which to get the columnsField. The Query should return atleast the columnsField, labelsField .
     *
     * This will come in handy incases where the dataQuery returns null, this will happend incases where we have
     * columns in one table and the values for those columns in anothe table.
     *
     * in an Entity–attribute–value model(EAV) kind of set up.
     *
     * @var QueryInterface
     */
    public $columnsQuery;

    /**
     * Initializes the DB connection component.
     * This method will initialize the [[db]] property to make sure it refers to a valid DB connection.
     *
     * @throws InvalidConfigException if [[db]] is invalid
     */
    public function init()
    {
        parent::init();

        if (!is_array($this->extraFields)) {
            throw new InvalidParamException('The extraFields should be an array');
        }

        if ($this->columnsQuery !== null && !$this->columnsQuery instanceof ActiveQueryInterface):
            throw new InvalidParamException('The columnsQuery should be an instance if the "ActiveQueryInterface"');
        endif;
    }

    /**
     * Prepares the keys associated with the currently available data models.
     *
     * @param array $models the available data models
     *
     * @return array the keys
     */
    protected function prepareKeys($models)
    {
        if ($this->key !== null) {
            $keys = [];
            foreach ($models as $model) {
                if (is_string($this->key)) {
                    $keys[] = $model[$this->key];
                } else {
                    $keys[] = call_user_func($this->key, $model);
                }
            }

            return $keys;
        } else {
            return array_keys($models);
        }
    }

    /**
     * Prepares the data models that will be made available in the current page.
     *
     * @return array the available data models
     *
     * @throws InvalidConfigException
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function prepareModels()
    {
        if (!$this->query instanceof QueryInterface) {
            throw new InvalidConfigException('The "query" property must be an instance of a class that'.
                ' implements the QueryInterface e.g. yii\db\Query or its subclasses.');
        }

        /** @var $query ActiveQuery */
        $query = clone $this->query;
        $query->orderBy($this->groupField);

        if (($pagination = $this->getPagination()) !== false) {
            $pagination->totalCount = $this->getTotalCount();
            $rows = $this->getDistinctRows();

            // only do a between check if we have an upper range to work with.
            $upperRange = $this->getUpperRow($pagination, $rows);

            if ($upperRange):
                $query->andWhere(['between', $this->groupField,
                    $this->getLowerRow($pagination, $rows),
                    $upperRange,
                ]);
            endif;

        }

        if (($sort = $this->getSort()) !== false) {
            $query->addOrderBy($sort->getOrders());
        }

        return $this->transpose($query->all($this->db));
    }

    /**
     * Gets the row from which to start our data fetch.
     *
     * @param Pagination $pagination
     * @param $rows
     *
     * @return int
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getLowerRow(Pagination $pagination, $rows)
    {
        $offset = $pagination->getOffset();
        if ($offset <= 0):
            return 0;
        endif;

        // the offset is out of range use the last record in the array
        return in_array($offset, $rows) ? $rows[$offset] : end($rows);
    }

    /**
     * Gets the row at which we stop fetching data.
     *
     * @param Pagination $pagination
     * @param $rows
     *
     * @return int
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getUpperRow(Pagination $pagination, $rows)
    {
        if ($pagination->getLimit() <= 0):
            return 0;
        endif;

        $nextRow = $pagination->getLimit() + $pagination->getOffset();

        // array start at zero, meaning the $rows array will start at zero,
        // we adjust for this by reducing the $nextRow by 1
        --$nextRow;

        // the offset is out of range use the last record in the array
        return $nextRow <= count($rows) ? $rows[$nextRow] : end($rows);
    }

    /**
     * In this case we return the number of distinct rows based on the groupField
     * {@inheritdoc}
     */
    protected function prepareTotalCount()
    {
        if (!$this->query instanceof QueryInterface) {
            throw new InvalidConfigException('The "query" property must be an instance of a class that implements the'.
                ' QueryInterface e.g. yii\db\Query or its subclasses.');
        }
        /** @var $query ActiveQuery */
        $query = clone $this->query;

        return (int) $query->select($this->groupField)->distinct()
            ->orderBy($this->groupField)
            ->count('*', $this->db);
    }

    /**
     * Returns all the columns that relate to the data we are handling, this also includes any extra fields
     * that might have been passed.
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getDataColumns()
    {
        $columns = $this->getQueryColumns();
        if (count($columns) === 0):
            return $columns;
        endif;

        return array_merge($this->extraFields, $columns);
    }

    /**
     * Returns columns found in the query.
     *
     * @return array
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getQueryColumns()
    {
        $colsMeta = $this->getDistinctColumns();

        $colNames = [];
        foreach ($colsMeta as $colMeta) :
            $colNames[] = end($colMeta);
        endforeach;

        return $colNames;
    }

    /**
     * Get a column in the query based on a columnLabel provided.
     *
     * @param $columnLabel
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getColumn($columnLabel)
    {
        $column = null;
        $cols = $this->getDistinctColumns();
        foreach ($cols as $col) :

            if (in_array($columnLabel, $col)):
                $column = reset($col);
                break;
            endif;
        endforeach;

        return $column;
    }

    /**
     * gets the rows of data that our data holds.
     *
     * Note, this will not be a direct mapping of the rows of data in a table.
     *
     * we use {see @groupField } to determine the rows.
     *
     * @return array|\yii\db\ActiveRecord[]
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function getDistinctRows()
    {

        if ($this->_rows):
            return $this->_rows;
        endif;

        /** @var $query ActiveQuery */
        $query = clone $this->query;

        // avoid loading relations
        $rows = $query->select($this->groupField)
            ->distinct()
            ->asArray()
            ->orderBy($this->groupField)
            ->createCommand($this->db)
            ->queryAll();

        array_walk($rows, function (&$value, $key) {
            $value = $this->getCleanColumn(reset($value));
        });

        $this->_rows = $rows;

        return $this->_rows;
    }

    /**
     * gets the columns that will be used in our final transposed data.
     *
     * we use {see @columnsField } to determine the rows.
     *
     * @return array|\yii\db\ActiveRecord[]
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function getDistinctColumns()
    {
        if ($this->_columns):
            return $this->_columns;
        endif;

        /* @var $query ActiveQuery */
        if ($this->columnsQuery instanceof ActiveQueryInterface):
            $query = clone $this->columnsQuery;
        else:
            $query = clone $this->query;
        endif;

        $query->distinct()->orderBy($this->stripRelation($this->columnsField))->asArray();

        if ($this->labelsField):
            // this will avoid populating the related fields
            $rows = $query->select([$this->stripRelation($this->columnsField), $this->labelsField])->createCommand()->queryAll($this->db);
            array_walk($rows, function (&$value, $key) {
                $val = $this->getColumnValue($value, $this->stripRelation($this->columnsField));

                $label = $this->getColumnValue($value, $this->labelsField);

                $value = [$val, self::conformColumn($label)];
            });
        else:
            // this will avoid populating the related fields
            $rows = $query->select([$this->stripRelation($this->columnsField)])->createCommand()->queryAll($this->db);

            array_walk($rows, function (&$value, $key) {
                $val = $this->getColumnValue($value, $this->stripRelation($this->columnsField));
                $value = [$val, $val];
            });

        endif;

        $this->_columns = $rows;

        return $this->_columns;
    }

    /**
     * Strip the column name to the last in the relation e.g. user.role this returns role.
     *
     * @internal
     *
     * @param $column
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function stripRelation($column)
    {
        if ($pos = strripos($column, '.')):

            $column = substr($column, $pos + 1);
        endif;

        return $column;
    }

    /**
     * This transposes the models passed in it desired output.
     *
     * The desired output is dictated by :
     * see @groupField
     * see @valuesField
     * see @columnsField
     *
     * @param $models
     *
     * @return array
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function transpose($models)
    {
        $dataRows = [];

        $columns = $this->getDistinctColumns();
        $extraColumns = $this->extraFields;

        foreach ($models as $index => $model) :

            if (is_array($this->groupField)):
                $rowID = $model[$this->groupField[0]].''.$model[$this->groupField[1]];
            else:
                $rowID = $model[$this->groupField];
            endif;

            foreach ($columns as $column) :
                $col = reset($column);

                // get the value of the columnField in the model, if it matches the current column
                // add it to our data rows
                if ($this->getColumnValue($model, $this->columnsField) !== $col):
                    continue;
                endif;

                $dataRows[$rowID][end($column)] = $model[$this->valuesField];

                // add value of any other extra columns.that have been requested
                foreach ($extraColumns as $eColumn => $label) :

                    if (is_numeric($eColumn)):
                        $eColumn = $label;
                    endif;

                    $dataRows[$rowID][$label] = $this->getColumnValue($model, $eColumn);
                endforeach;
            endforeach;

        endforeach;

        ksort($dataRows);

        return $dataRows;
    }

    /**
     * Gets a model and column name and returns the value of the column on the model.
     *
     * @param $model
     * @param null $column
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function getColumnValue($model, $column = null)
    {
        // handle relational columns
        if (strpos($column, '.')):
            $parentModel = $model[substr($column, 0, strpos($column, '.'))];
            $childCol = substr($column, strpos($column, '.') + 1);
            $value = $this->getColumnValue($parentModel, $childCol);
        else:
            $value = $model[$column];
        endif;

        return $value;
    }

    /**
     * Creates the field label.
     *
     * @param $column
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function getCleanColumn($column)
    {
        if (!self::isValidVariableName($column)):
            $column = self::conformColumn($column);
        endif;

        return $column;
    }

    /**
     * Check if a string can be used as a php variable/ class attribute.
     *
     * @param $name
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected static function isValidVariableName($name)
    {
        return 1 === preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name);
    }

    /**
     * ensures column to be a valid column name.
     *
     * @param $name
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected static function conformColumn($name)
    {
        return preg_replace('/[^\w]/', '_', $name);
    }
}
