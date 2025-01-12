<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\sphinx;

use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\base\BaseObject;
use yii\db\Exception;
use yii\db\Expression;

/**
 * QueryBuilder builds a SELECT SQL statement based on the specification given as a [[Query]] object.
 *
 * QueryBuilder can also be used to build SQL statements such as INSERT, REPLACE, UPDATE, DELETE,
 * from a [[Query]] object.
 *
 * @property MatchBuilder $matchBuilder Match builder. This property is read-only.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
class QueryBuilder extends BaseObject
{
    /**
     * The prefix for automatically generated query binding parameters.
     */
    const PARAM_PREFIX = ':qp';

    /**
     * @var Connection the Sphinx connection.
     */
    public $db;
    /**
     * @var string the separator between different fragments of a SQL statement.
     * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
     */
    public $separator = " ";
    /**
     * @var string separator between different SQL queries.
     * This is mainly used by [[build()]] when generating a SQL statement.
     */
    public $querySeparator = "; ";

    /**
     * @var array map of query condition to builder methods.
     * These methods are used by [[buildCondition]] to build SQL conditions from array syntax.
     */
    protected $conditionBuilders = [
        'AND' => 'buildAndCondition',
        'OR' => 'buildAndCondition',
        'BETWEEN' => 'buildBetweenCondition',
        'NOT BETWEEN' => 'buildBetweenCondition',
        'IN' => 'buildInCondition',
        'NOT IN' => 'buildInCondition',
        'LIKE' => 'buildLikeCondition',
        'NOT LIKE' => 'buildLikeCondition',
        'OR LIKE' => 'buildLikeCondition',
        'OR NOT LIKE' => 'buildLikeCondition',
        'NOT' => 'buildNotCondition',
    ];

    /**
     * @var MatchBuilder match builder
     * @since 2.0.6
     */
    private $_matchBuilder;


    /**
     * Constructor.
     * @param Connection $connection the Sphinx connection.
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($connection, $config = [])
    {
        $this->db = $connection;
        parent::__construct($config);
    }

    /**
     * @return MatchBuilder match builder.
     * @since 2.0.6
     */
    public function getMatchBuilder()
    {
        if ($this->_matchBuilder === null) {
            $this->_matchBuilder = new MatchBuilder($this->db);
        }
        return $this->_matchBuilder;
    }

    /**
     * Generates a SELECT SQL statement from a [[Query]] object.
     * @param Query $query the [[Query]] object from which the SQL statement will be generated
     * @param array $params the parameters to be bound to the generated SQL statement. These parameters will
     * be included in the result with the additional parameters generated during the query building process.
     * @throws NotSupportedException if query contains 'join' option.
     * @return array the generated SQL statement (the first array element) and the corresponding
     * parameters to be bound to the SQL statement (the second array element). The parameters returned
     * include those provided in `$params`.
     */
    public function build($query, $params = [])
    {
        $query = $query->prepare($this);

        if (!empty($query->join)) {
            throw new NotSupportedException('Build of "' . get_class($query) . '::join" is not supported.');
        }

        $params = empty($params) ? $query->params : array_merge($params, $query->params);

        $from = $query->from;
        if ($from === null && $query instanceof ActiveQuery) {
            /* @var $modelClass ActiveRecord */
            $modelClass = $query->modelClass;
            $from = [$modelClass::indexName()];
        }

        $clauses = [
            $this->buildSelect($query->select, $params, $query->distinct, $query->selectOption),
            $this->buildFrom($from, $params),
            $this->buildWhere($from, $query->where, $params, $query->match),
            $this->buildGroupBy($query->groupBy, $query->groupLimit),
            $this->buildWithin($query->within),
            $this->buildHaving($query->from, $query->having, $params),
            $this->buildOrderBy($query->orderBy),
            $this->buildLimit($query->limit, $query->offset),
            $this->buildOption($query->options, $params),
            $this->buildFacets($query->facets, $params),
        ];

        $sql = implode($this->separator, array_filter($clauses));

        $showMetaSql = $this->buildShowMeta($query->showMeta, $params);
        if (!empty($showMetaSql)) {
            $sql .= $this->querySeparator . $showMetaSql;
        }

        return [$sql, $params];
    }

    /**
     * Creates an INSERT SQL statement.
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->insert('idx_user', [
     *     'name' => 'Sam',
     *     'age' => 30,
     *     'id' => 10,
     * ], $params);
     * ```
     *
     * The method will properly escape the index and column names.
     *
     * @param string $index the index that new rows will be inserted into.
     * @param array $columns the column data (name => value) to be inserted into the index.
     * @param array $params the binding parameters that will be generated by this method.
     * They should be bound to the Sphinx command later.
     * @return string the INSERT SQL
     */
    public function insert($index, $columns, &$params)
    {
        return $this->generateInsertReplace('INSERT', $index, $columns, $params);
    }

    /**
     * Creates an REPLACE SQL statement.
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->replace('idx_user', [
     *     'name' => 'Sam',
     *     'age' => 30,
     *     'id' => 10,
     * ], $params);
     * ```
     *
     * The method will properly escape the index and column names.
     *
     * @param string $index the index that new rows will be replaced.
     * @param array $columns the column data (name => value) to be replaced in the index.
     * @param array $params the binding parameters that will be generated by this method.
     * They should be bound to the Sphinx command later.
     * @return string the INSERT SQL
     */
    public function replace($index, $columns, &$params)
    {
        return $this->generateInsertReplace('REPLACE', $index, $columns, $params);
    }

    /**
     * Generates INSERT/REPLACE SQL statement.
     * @param string $statement statement ot be generated.
     * @param string $index the affected index name.
     * @param array $columns the column data (name => value).
     * @param array $params the binding parameters that will be generated by this method.
     * @return string generated SQL
     */
    protected function generateInsertReplace($statement, $index, $columns, &$params)
    {
        if (($indexSchema = $this->db->getIndexSchema($index)) !== null) {
            $indexSchemas = [$indexSchema];
        } else {
            $indexSchemas = [];
        }
        $names = [];
        $placeholders = [];
        foreach ($columns as $name => $value) {
            if ($value === null) {
                // Sphinx does not allows inserting `null`, column should be skipped instead
                continue;
            }
            $names[] = $this->db->quoteColumnName($name);
            $placeholders[] = $this->composeColumnValue($indexSchemas, $name, $value, $params);
        }

        return $statement . ' INTO ' . $this->db->quoteIndexName($index)
            . ' (' . implode(', ', $names) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
    }

    /**
     * Generates a batch INSERT SQL statement.
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->batchInsert('idx_user', ['id', 'name', 'age'], [
     *     [1, 'Tom', 30],
     *     [2, 'Jane', 20],
     *     [3, 'Linda', 25],
     * ], $params);
     * ```
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * @param string $index the index that new rows will be inserted into.
     * @param array $columns the column names
     * @param array $rows the rows to be batch inserted into the index
     * @param array $params the binding parameters that will be generated by this method.
     * They should be bound to the Sphinx command later.
     * @return string the batch INSERT SQL statement
     */
    public function batchInsert($index, $columns, $rows, &$params)
    {
        return $this->generateBatchInsertReplace('INSERT', $index, $columns, $rows, $params);
    }

    /**
     * Generates a batch REPLACE SQL statement.
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->batchReplace('idx_user', ['id', 'name', 'age'], [
     *     [1, 'Tom', 30],
     *     [2, 'Jane', 20],
     *     [3, 'Linda', 25],
     * ], $params);
     * ```
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * @param string $index the index that new rows will be replaced.
     * @param array $columns the column names
     * @param array $rows the rows to be batch replaced in the index
     * @param array $params the binding parameters that will be generated by this method.
     * They should be bound to the Sphinx command later.
     * @return string the batch INSERT SQL statement
     */
    public function batchReplace($index, $columns, $rows, &$params)
    {
        return $this->generateBatchInsertReplace('REPLACE', $index, $columns, $rows, $params);
    }

    /**
     * Generates a batch INSERT/REPLACE SQL statement.
     * @param string $statement statement ot be generated.
     * @param string $index the affected index name.
     * @param array $columns the column data (name => value).
     * @param array $rows the rows to be batch inserted into the index
     * @param array $params the binding parameters that will be generated by this method.
     * @return string generated SQL
     */
    protected function generateBatchInsertReplace($statement, $index, $columns, $rows, &$params)
    {
        if (($indexSchema = $this->db->getIndexSchema($index)) !== null) {
            $indexSchemas = [$indexSchema];
        } else {
            $indexSchemas = [];
        }

        $notNullColumns = [];
        $values = [];
        foreach ($rows as $row) {
            $vs = [];
            foreach ($row as $i => $value) {
                if ($value === null) {
                    continue;
                } elseif (!in_array($columns[$i], $notNullColumns)) {
                    $notNullColumns[] = $columns[$i];
                }
                $vs[] = $this->composeColumnValue($indexSchemas, $columns[$i], $value, $params);
            }
            $values[] = '(' . implode(', ', $vs) . ')';
        }

        foreach ($notNullColumns as $i => $name) {
            $notNullColumns[$i] = $this->db->quoteColumnName($name);
        }

        return $statement . ' INTO ' . $this->db->quoteIndexName($index)
            . ' (' . implode(', ', $notNullColumns) . ') VALUES ' . implode(', ', $values);
    }

    /**
     * Creates an UPDATE SQL statement.
     * For example,
     *
     * ```php
     * $params = [];
     * $sql = $queryBuilder->update('idx_user', ['status' => 1], 'age > 30', $params);
     * ```
     *
     * The method will properly escape the index and column names.
     *
     * @param string $index the index to be updated.
     * @param array $columns the column data (name => value) to be updated.
     * @param array|string $condition the condition that will be put in the WHERE part. Please
     * refer to [[Query::where()]] on how to specify condition.
     * @param array $params the binding parameters that will be modified by this method
     * so that they can be bound to the Sphinx command later.
     * @param array $options list of options in format: optionName => optionValue
     * @return string the UPDATE SQL
     */
    public function update($index, $columns, $condition, &$params, $options)
    {
        if (($indexSchema = $this->db->getIndexSchema($index)) !== null) {
            $indexSchemas = [$indexSchema];
        } else {
            $indexSchemas = [];
        }

        $lines = [];
        foreach ($columns as $name => $value) {
            $lines[] = $this->db->quoteColumnName($name) . '=' . $this->composeColumnValue($indexSchemas, $name, $value, $params);
        }

        $sql = 'UPDATE ' . $this->db->quoteIndexName($index) . ' SET ' . implode(', ', $lines);
        $where = $this->buildWhere([$index], $condition, $params);
        if ($where !== '') {
            $sql = $sql . ' ' . $where;
        }
        $option = $this->buildOption($options, $params);
        if ($option !== '') {
            $sql = $sql . ' ' . $option;
        }

        return $sql;
    }

    /**
     * Creates a DELETE SQL statement.
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->delete('idx_user', 'status = 0');
     * ```
     *
     * The method will properly escape the index and column names.
     *
     * @param string $index the index where the data will be deleted from.
     * @param array|string $condition the condition that will be put in the WHERE part. Please
     * refer to [[Query::where()]] on how to specify condition.
     * @param array $params the binding parameters that will be modified by this method
     * so that they can be bound to the Sphinx command later.
     * @return string the DELETE SQL
     */
    public function delete($index, $condition, &$params)
    {
        $sql = 'DELETE FROM ' . $this->db->quoteIndexName($index);
        $where = $this->buildWhere([$index], $condition, $params);

        return $where === '' ? $sql : $sql . ' ' . $where;
    }

    /**
     * Builds a SQL statement for truncating an index.
     * @param string $index the index to be truncated. The name will be properly quoted by the method.
     * @return string the SQL statement for truncating an index.
     */
    public function truncateIndex($index)
    {
        return 'TRUNCATE RTINDEX ' . $this->db->quoteIndexName($index);
    }

    /**
     * Builds a SQL statement for call snippet from provided data and query, using specified index settings.
     * @param string $index name of the index, from which to take the text processing settings.
     * @param string|array $source is the source data to extract a snippet from.
     * It could be either a single string or array of strings.
     * @param string $match the full-text query to build snippets for.
     * @param array $options list of options in format: optionName => optionValue
     * @param array $params the binding parameters that will be modified by this method
     * so that they can be bound to the Sphinx command later.
     * @return string the SQL statement for call snippets.
     */
    public function callSnippets($index, $source, $match, $options, &$params)
    {
        if (is_array($source)) {
            $dataSqlParts = [];
            foreach ($source as $sourceRow) {
                $phName = self::PARAM_PREFIX . count($params);
                $params[$phName] = (string)$sourceRow;
                $dataSqlParts[] = $phName;
            }
            $dataSql = '(' . implode(',', $dataSqlParts) . ')';
        } else {
            $phName = self::PARAM_PREFIX . count($params);
            $params[$phName] = $source;
            $dataSql = $phName;
        }
        $indexParamName = self::PARAM_PREFIX . count($params);
        $params[$indexParamName] = $index;

        $matchSql = $this->buildMatch($match, $params);

        if (!empty($options)) {
            $optionParts = [];
            foreach ($options as $name => $value) {
                if ($value instanceof Expression) {
                    $actualValue = $value->expression;
                } else {
                    $actualValue = self::PARAM_PREFIX . count($params);
                    $params[$actualValue] = $value;
                }
                $optionParts[] = $actualValue . ' AS ' . $name;
            }
            $optionSql = ', ' . implode(', ', $optionParts);
        } else {
            $optionSql = '';
        }

        return 'CALL SNIPPETS(' . $dataSql. ', ' . $indexParamName . ', ' . $matchSql . $optionSql. ')';
    }

    /**
     * Builds a SQL statement for returning tokenized and normalized forms of the keywords, and,
     * optionally, keyword statistics.
     * @param string $index the name of the index from which to take the text processing settings
     * @param string $text the text to break down to keywords.
     * @param bool $fetchStatistic whether to return document and hit occurrence statistics
     * @param array $params the binding parameters that will be modified by this method
     * so that they can be bound to the Sphinx command later.
     * @return string the SQL statement for call keywords.
     */
    public function callKeywords($index, $text, $fetchStatistic, &$params)
    {
        $indexParamName = self::PARAM_PREFIX . count($params);
        $params[$indexParamName] = $index;
        $textParamName = self::PARAM_PREFIX . count($params);
        $params[$textParamName] = $text;

        return 'CALL KEYWORDS(' . $textParamName . ', ' . $indexParamName . ($fetchStatistic ? ', 1' : '') . ')';
    }

    /**
     * @param array $columns
     * @param array $params the binding parameters to be populated
     * @param bool $distinct
     * @param string $selectOption
     * @return string the SELECT clause built from [[query]].
     */
    public function buildSelect($columns, &$params, $distinct = false, $selectOption = null)
    {
        $select = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        if ($selectOption !== null) {
            $select .= ' ' . $selectOption;
        }
        return $select . ' ' . $this->buildSelectFields($columns, $params);
    }

    /**
     * @param array $columns
     * @param array $params
     * @return string fields list for SELECT clause
     */
    private function buildSelectFields($columns, &$params)
    {
        if (empty($columns)) {
            return '*';
        }
        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                if (is_int($i)) {
                    $columns[$i] = $column->expression;
                } else {
                    $columns[$i] = $column->expression . ' AS ' . $this->db->quoteColumnName($i);
                }
                $params = array_merge($params, $column->params);
            } elseif (is_string($i) && $i !== $column) {
                if (strpos($column, '(') === false) {
                    $column = $this->db->quoteColumnName($column);
                }
                $columns[$i] = "$column AS " . $this->db->quoteColumnName($i);
            } elseif (strpos($column, '(') === false) {
                if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $column, $matches)) {
                    $columns[$i] = $this->db->quoteColumnName($matches[1]) . ' AS ' . $this->db->quoteColumnName($matches[2]);
                } else {
                    $columns[$i] = $this->db->quoteColumnName($column);
                }
            }
        }
        return implode(', ', $columns);
    }

    /**
     * @param array $indexes
     * @param array $params the binding parameters to be populated
     * @return string the FROM clause built from [[query]].
     */
    public function buildFrom($indexes, &$params)
    {
        if (empty($indexes)) {
            return '';
        }

        foreach ($indexes as $i => $index) {
            if ($index instanceof Query) {
                list($sql, $params) = $this->build($index, $params);
                $indexes[$i] = "($sql) " . $this->db->quoteIndexName($i);
            } elseif (is_string($i)) {
                if (strpos($index, '(') === false) {
                    $index = $this->db->quoteIndexName($index);
                }
                $indexes[$i] = "$index " . $this->db->quoteIndexName($i);
            } elseif (strpos($index, '(') === false) {
                if (preg_match('/^(.*?)(?i:\s+as|)\s+([^ ]+)$/', $index, $matches)) { // with alias
                    $indexes[$i] = $this->db->quoteIndexName($matches[1]) . ' ' . $this->db->quoteIndexName($matches[2]);
                } else {
                    $indexes[$i] = $this->db->quoteIndexName($index);
                }
            }
        }

        if (is_array($indexes)) {
            $indexes = implode(', ', $indexes);
        }

        return 'FROM ' . $indexes;
    }

    /**
     * @param string|Expression|MatchExpression $match match condition
     * @param array $params the binding parameters to be populated
     * @return string generated MATCH expression
     */
    public function buildMatch($match, &$params)
    {
        if ($match instanceof Expression) {
            $params = array_merge($params, $match->params);
            return $match->expression;
        }

        if ($match instanceof MatchExpression) {
            $phName = self::PARAM_PREFIX . count($params);
            $params[$phName] = $this->getMatchBuilder()->build($match);
            return $phName;
        }

        $phName = self::PARAM_PREFIX . count($params);
        $params[$phName] = $this->db->escapeMatchValue($match);
        return $phName;
    }

    /**
     * @param string[] $indexes list of index names, which affected by query
     * @param string|array $condition
     * @param array $params the binding parameters to be populated
     * @param string|Expression|null $match
     * @return string the WHERE clause built from [[query]].
     */
    public function buildWhere($indexes, $condition, &$params, $match = null)
    {
        if ($match !== null) {
            $matchWhere = 'MATCH(' . $this->buildMatch($match, $params) . ')';
            if ($condition === null) {
                $condition = $matchWhere;
            } else {
                $condition = ['and', $matchWhere, $condition];
            }
        }

        if (empty($condition)) {
            return '';
        }
        $indexSchemas = $this->getIndexSchemas($indexes);
        $where = $this->buildCondition($indexSchemas, $condition, $params);

        return $where === '' ? '' : 'WHERE ' . $where;
    }

    /**
     * @param array $columns group columns
     * @param int $limit group limit
     * @return string the GROUP BY clause
     */
    public function buildGroupBy($columns, $limit)
    {
        if (empty($columns)) {
            return '';
        }

        if (is_string($limit) && ctype_digit($limit) || is_int($limit) && $limit >= 0) {
            $limitSql = ' ' . $limit;
        } else {
            $limitSql = '';
        }

        return 'GROUP' . $limitSql . ' BY ' . $this->buildColumns($columns);
    }

    /**
     * @param string[] $indexes list of index names, which affected by query
     * @param string|array $condition
     * @param array $params the binding parameters to be populated
     * @return string the HAVING clause built from [[Query::$having]].
     */
    public function buildHaving($indexes, $condition, &$params)
    {
        if (empty($condition)) {
            return '';
        }

        $indexSchemas = $this->getIndexSchemas($indexes);
        $having = $this->buildCondition($indexSchemas, $condition, $params);

        return $having === '' ? '' : 'HAVING ' . $having;
    }

    /**
     * Builds the ORDER BY and LIMIT/OFFSET clauses and appends them to the given SQL.
     * @param string $sql the existing SQL (without ORDER BY/LIMIT/OFFSET)
     * @param array $orderBy the order by columns. See [[Query::orderBy]] for more details on how to specify this parameter.
     * @param int $limit the limit number. See [[Query::limit]] for more details.
     * @param int $offset the offset number. See [[Query::offset]] for more details.
     * @return string the SQL completed with ORDER BY/LIMIT/OFFSET (if any)
     */
    public function buildOrderByAndLimit($sql, $orderBy, $limit, $offset)
    {
        $orderBy = $this->buildOrderBy($orderBy);
        if ($orderBy !== '') {
            $sql .= $this->separator . $orderBy;
        }
        $limit = $this->buildLimit($limit, $offset);
        if ($limit !== '') {
            $sql .= $this->separator . $limit;
        }
        return $sql;
    }

    /**
     * @param array $columns
     * @return string the ORDER BY clause built from [[query]].
     */
    public function buildOrderBy($columns)
    {
        if (empty($columns)) {
            return '';
        }
        $orders = [];
        foreach ($columns as $name => $direction) {
            if ($direction instanceof Expression) {
                $orders[] = $direction->expression;
            } else {
                $orders[] = $this->db->quoteColumnName($name) . ($direction === SORT_DESC ? ' DESC' : ' ASC');
            }
        }

        return 'ORDER BY ' . implode(', ', $orders);
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return string the LIMIT and OFFSET clauses built from [[query]].
     */
    public function buildLimit($limit, $offset)
    {
        $sql = '';
        if (is_int($offset) && $offset > 0 || is_string($offset) && ctype_digit($offset) && $offset !== '0') {
            $sql = 'LIMIT ' . $offset;
        }
        if (is_string($limit) && ctype_digit($limit) || is_int($limit) && $limit >= 0) {
            $sql = $sql === '' ? "LIMIT $limit" : "$sql,$limit";
        } elseif ($sql !== '') {
            $sql .= ',1000';  // this is the default limit by sphinx
        }

        return $sql;
    }

    /**
     * Processes columns and properly quote them if necessary.
     * It will join all columns into a string with comma as separators.
     * @param string|array $columns the columns to be processed
     * @return string the processing result
     */
    public function buildColumns($columns)
    {
        if (!is_array($columns)) {
            if (strpos($columns, '(') !== false) {
                return $columns;
            } else {
                $columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
            }
        }
        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                $columns[$i] = $column->expression;
            } elseif (strpos($column, '(') === false) {
                $columns[$i] = $this->db->quoteColumnName($column);
            }
        }

        return is_array($columns) ? implode(', ', $columns) : $columns;
    }

    /**
     * Parses the condition specification and generates the corresponding SQL expression.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string|array $condition the condition specification. Please refer to [[Query::where()]]
     * on how to specify a condition.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws \yii\db\Exception if the condition is in bad format
     */
    public function buildCondition($indexes, $condition, &$params)
    {
        if ($condition instanceof Expression) {
            foreach ($condition->params as $n => $v) {
                $params[$n] = $v;
            }
            return $condition->expression;
        } elseif (!is_array($condition)) {
            return (string) $condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0])) {
            // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (isset($this->conditionBuilders[$operator])) {
                $method = $this->conditionBuilders[$operator];
            } else {
                $method = 'buildSimpleCondition';
            }
            array_shift($condition);
            return $this->$method($indexes, $operator, $condition, $params);
        }

        // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
        return $this->buildHashCondition($indexes, $condition, $params);
    }

    /**
     * Creates a condition based on column-value pairs.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param array $condition the condition specification.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildHashCondition($indexes, $condition, &$params)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value) || $value instanceof \Traversable || $value instanceof Query) {
                // IN condition
                $parts[] = $this->buildInCondition($indexes, 'IN', [$column, $value], $params);
            } else {
                if (strpos($column, '(') === false) {
                    $quotedColumn = $this->db->quoteColumnName($column);
                } else {
                    $quotedColumn = $column;
                }
                if ($value === null) {
                    $parts[] = "$quotedColumn IS NULL";
                } else {
                    $parts[] = $quotedColumn . '=' . $this->composeColumnValue($indexes, $column, $value, $params);
                }
            }
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(') AND (', $parts) . ')';
    }

    /**
     * Connects two or more SQL expressions with the `AND` or `OR` operator.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildAndCondition($indexes, $operator, $operands, &$params)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand) || $operand instanceof Expression) {
                $operand = $this->buildCondition($indexes, $operand, $params);
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return '(' . implode(") $operator (", $parts) . ')';
        }

        return '';
    }

    /**
     * Inverts an SQL expressions with `NOT` operator.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildNotCondition($indexes, $operator, $operands, &$params)
    {
        if (count($operands) != 1) {
            throw new InvalidParamException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand) || $operand instanceof Expression) {
            $operand = $this->buildCondition($indexes, $operand, $params);
        }
        if ($operand === '') {
            return '';
        }

        return "$operator ($operand)";
    }

    /**
     * Creates an SQL expressions with the `BETWEEN` operator.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use (e.g. `BETWEEN` or `NOT BETWEEN`)
     * @param array $operands the first operand is the column name. The second and third operands
     * describe the interval that column value should be in.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildBetweenCondition($indexes, $operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1], $operands[2])) {
            throw new Exception("Operator '$operator' requires three operands.");
        }

        list($column, $value1, $value2) = $operands;

        if (strpos($column, '(') === false) {
            $quotedColumn = $this->db->quoteColumnName($column);
        } else {
            $quotedColumn = $column;
        }
        $phName1 = $this->composeColumnValue($indexes, $column, $value1, $params);
        $phName2 = $this->composeColumnValue($indexes, $column, $value2, $params);

        return "$quotedColumn $operator $phName1 AND $phName2";
    }

    /**
     * Creates an SQL expressions with the `IN` operator.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
     * @param array $operands the first operand is the column name. If it is an array
     * a composite IN condition will be generated.
     * The second operand is an array of values that column value should be among.
     * If it is an empty array the generated expression will be a `false` value if
     * operator is `IN` and empty if operator is `NOT IN`.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildInCondition($indexes, $operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        if ($column === []) {
            return '';
        }

        if ($values instanceof Query) {
            // sub-query
            list($sql, $params) = $this->build($values, $params);
            $column = (array) $column;
            if (is_array($column)) {
                foreach ($column as $i => $col) {
                    if (strpos($col, '(') === false) {
                        $column[$i] = $this->db->quoteColumnName($col);
                    }
                }
                return '(' . implode(', ', $column) . ") $operator ($sql)";
            } else {
                if (strpos($column, '(') === false) {
                    $column = $this->db->quoteColumnName($column);
                }
                return "$column $operator ($sql)";
            }
        }

        if (!is_array($values) && !$values instanceof \Traversable) {
            // ensure values is an array
            $values = (array) $values;
        }

        if ($column instanceof \Traversable || ((is_array($column) || $column instanceof \Countable) && count($column) > 1)) {
            return $this->buildCompositeInCondition($indexes, $operator, $column, $values, $params);
        } elseif (is_array($column)) {
            $column = reset($column);
        }

        $sqlValues = [];
        foreach ($values as $i => $value) {
            if (is_array($value)) {
                $value = isset($value[$column]) ? $value[$column] : null;
            }
            $sqlValues[$i] = $this->composeColumnValue($indexes, $column, $value, $params);
        }

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        if ($sqlValues === []) {
            if ($operator === 'IN') {
                if (empty($column)) {
                    throw new Exception("Operator '$operator' requires column being specified.");
                }
                $column = $this->db->quoteColumnName($column);
                return "({$column} = 0 AND {$column} = 1)";
            }
            return '';
        }

        if (count($sqlValues) > 1) {
            return "$column $operator (" . implode(', ', $sqlValues) . ')';
        }

        $operator = $operator === 'IN' ? '=' : '<>';
        return $column . $operator . reset($sqlValues);
    }

    /**
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
     * @param array $columns
     * @param array $values
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    protected function buildCompositeInCondition($indexes, $operator, $columns, $values, &$params)
    {
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($columns as $column) {
                if (isset($value[$column])) {
                    $vs[] = $this->composeColumnValue($indexes, $column, $value[$column], $params);
                } else {
                    $vs[] = 'NULL';
                }
            }
            $vss[] = '(' . implode(', ', $vs) . ')';
        }

        $sqlColumns = [];
        foreach ($columns as $i => $column) {
            if (strpos($column, '(') === false) {
                $sqlColumns[$i] = $this->db->quoteColumnName($column);
            }
        }

        return '(' . implode(', ', $sqlColumns) . ") $operator (" . implode(', ', $vss) . ')';
    }

    /**
     * Creates an SQL expressions with the `LIKE` operator.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use (e.g. `LIKE`, `NOT LIKE`, `OR LIKE` or `OR NOT LIKE`)
     * @param array $operands an array of two or three operands
     *
     * - The first operand is the column name.
     * - The second operand is a single value or an array of values that column value
     *   should be compared with. If it is an empty array the generated expression will
     *   be a `false` value if operator is `LIKE` or `OR LIKE`, and empty if operator
     *   is `NOT LIKE` or `OR NOT LIKE`.
     * - An optional third operand can also be provided to specify how to escape special characters
     *   in the value(s). The operand should be an array of mappings from the special characters to their
     *   escaped counterparts. If this operand is not provided, a default escape mapping will be used.
     *   You may use `false` or an empty array to indicate the values are already escaped and no escape
     *   should be applied. Note that when using an escape mapping (or the third operand is not provided),
     *   the values will be automatically enclosed within a pair of percentage characters.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildLikeCondition($indexes, $operator, $operands, &$params)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        $escape = isset($operands[2]) ? $operands[2] : ['%'=>'\%', '_'=>'\_', '\\'=>'\\\\'];
        unset($operands[2]);

        list($column, $values) = $operands;

        if (!is_array($values)) {
            $values = [$values];
        }

        if (empty($values)) {
            return $operator === 'LIKE' || $operator === 'OR LIKE' ? '0=1' : '';
        }

        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            $andor = ' AND ';
        } else {
            $andor = ' OR ';
            $operator = $operator === 'OR LIKE' ? 'LIKE' : 'NOT LIKE';
        }

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        $parts = [];
        foreach ($values as $value) {
            if ($value instanceof Expression) {
                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
                $phName = $value->expression;
            } else {
                $phName = self::PARAM_PREFIX . count($params);
                $params[$phName] = empty($escape) ? $value : ('%' . strtr($value, $escape) . '%');
            }
            $parts[] = "$column $operator $phName";
        }

        return implode($andor, $parts);
    }

    /**
     * Creates an SQL expressions like `"column" operator value`.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $operator the operator to use. Anything could be used e.g. `>`, `<=`, etc.
     * @param array $operands contains two column names.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if count($operands) is not 2
     */
    public function buildSimpleCondition($indexes, $operator, $operands, &$params)
    {
        if (count($operands) !== 2) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        list($column, $value) = $operands;

        $value = $this->composeColumnValue($indexes, $column, $value, $params);

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        return "$column $operator $value";
    }

    /**
     * @param array $columns
     * @return string the ORDER BY clause built from [[query]].
     */
    public function buildWithin($columns)
    {
        if (empty($columns)) {
            return '';
        }
        $orders = [];
        foreach ($columns as $name => $direction) {
            if ($direction instanceof Expression) {
                $orders[] = $direction->expression;
            } else {
                $orders[] = $this->db->quoteColumnName($name) . ($direction === SORT_DESC ? ' DESC' : ' ASC');
            }
        }

        return 'WITHIN GROUP ORDER BY ' . implode(', ', $orders);
    }

    /**
     * @param array $options query options in format: optionName => optionValue
     * @param array $params the binding parameters to be populated
     * @return string the OPTION clause build from [[query]]
     */
    public function buildOption($options, &$params)
    {
        if (empty($options)) {
            return '';
        }
        $optionLines = [];
        foreach ($options as $name => $value) {
            if ($value instanceof Expression) {
                $actualValue = $value->expression;
            } else {
                if (is_array($value)) {
                    $actualValueParts = [];
                    foreach ($value as $key => $valuePart) {
                        if (is_numeric($key)) {
                            $actualValuePart = '';
                        } else {
                            $actualValuePart = $key . ' = ';
                        }
                        if ($valuePart instanceof Expression) {
                            $actualValuePart .= $valuePart->expression;
                        } else {
                            $phName = self::PARAM_PREFIX . count($params);
                            $params[$phName] = $valuePart;
                            $actualValuePart .= $phName;
                        }
                        $actualValueParts[] = $actualValuePart;
                    }
                    $actualValue = '(' . implode(', ', $actualValueParts) . ')';
                } else {
                    $actualValue = self::PARAM_PREFIX . count($params);
                    $params[$actualValue] = $value;
                }
            }
            $optionLines[] = $name . ' = ' . $actualValue;
        }

        return 'OPTION ' . implode(', ', $optionLines);
    }

    /**
     * @param array $facets facet specifications
     * @param array $params the binding parameters to be populated
     * @return string the FACET clause build from [[query]]
     * @throws InvalidConfigException on invalid facet specification.
     */
    protected function buildFacets($facets, &$params)
    {
        if (empty($facets)) {
            return '';
        }

        $sqlParts = [];

        foreach ($facets as $key => $value) {
            if (is_numeric($key)) {
                $facet = [
                    'select' => $value
                ];
            } else {
                if (is_array($value)) {
                    $facet = $value;
                    if (!array_key_exists('select', $facet)) {
                        $facet['select'] = $key;
                    }
                } else {
                    throw new InvalidConfigException('Facet specification must be an array, "' . gettype($value) . '" given.');
                }
            }
            if (!array_key_exists('limit', $facet)) {
                $facet['limit'] = null;
            }
            if (!array_key_exists('offset', $facet)) {
                $facet['offset'] = null;
            }

            $facetSql = 'FACET ' . $this->buildSelectFields((array)$facet['select'], $params);
            if (!empty($facet['order'])) {
                $facetSql .= ' ' . $this->buildOrderBy($facet['order']);
            }
            $facetSql .= ' ' . $this->buildLimit($facet['limit'], $facet['offset']);

            $sqlParts[] = $facetSql;
        }

        return implode($this->separator, $sqlParts);
    }

    /**
     * Builds SHOW META query.
     * @param bool|string|Expression $showMeta show meta specification.
     * @param array $params the binding parameters to be populated
     * @return string SHOW META query, if it does not required - empty string.
     */
    protected function buildShowMeta($showMeta, &$params)
    {
        if (empty($showMeta)) {
            return '';
        }
        $sql = 'SHOW META';
        if (is_bool($showMeta)) {
            return $sql;
        }

        if ($showMeta instanceof Expression) {
            foreach ($showMeta->params as $n => $v) {
                $params[$n] = $v;
            }
            $phName = $showMeta->expression;
        } else {
            $phName = self::PARAM_PREFIX . count($params);
            $escape = ['%'=>'\%', '_'=>'\_', '\\'=>'\\\\'];
            $params[$phName] = '%' . strtr($showMeta, $escape) . '%';
        }

        $sql .= " LIKE {$phName}";
        return $sql;
    }

    /**
     * Composes column value for SQL, taking in account the column type.
     * @param IndexSchema[] $indexes list of indexes, which affected by query
     * @param string $columnName name of the column
     * @param mixed $value raw column value
     * @param array $params the binding parameters to be populated
     * @return string SQL expression, which represents column value
     */
    protected function composeColumnValue($indexes, $columnName, $value, &$params)
    {
        if ($value === null) {
            return 'NULL';
        } elseif ($value instanceof Expression) {
            $params = array_merge($params, $value->params);
            return $value->expression;
        }
        foreach ($indexes as $index) {
            $columnSchema = $index->getColumn($columnName);
            if ($columnSchema !== null) {
                break;
            }
        }
        if (is_array($value)) {
            // MVA :
            $lineParts = [];
            foreach ($value as $subValue) {
                if ($subValue instanceof Expression) {
                    $params = array_merge($params, $subValue->params);
                    $lineParts[] = $subValue->expression;
                } else {
                    $phName = self::PARAM_PREFIX . count($params);
                    $lineParts[] = $phName;
                    $params[$phName] = (isset($columnSchema)) ? $columnSchema->dbTypecast($subValue) : $subValue;
                }
            }

            return '(' . implode(',', $lineParts) . ')';
        } else {
            $phName = self::PARAM_PREFIX . count($params);
            $params[$phName] = (isset($columnSchema)) ? $columnSchema->dbTypecast($value) : $value;
            return $phName;
        }
    }

    /**
     * @param array $indexes index names.
     * @return IndexSchema[] index schemas.
     */
    private function getIndexSchemas($indexes)
    {
        $indexSchemas = [];
        if (!empty($indexes)) {
            foreach ($indexes as $indexName) {
                $index = $this->db->getIndexSchema($indexName);
                if ($index !== null) {
                    $indexSchemas[] = $index;
                }
            }
        }
        return $indexSchemas;
    }
    
    /**
     * Builds a SQL statement for creating a new index table.
     *
     * The columns in the new index should be specified as name-definition pairs (e.g. 'name' => 'string'),
     * where name stands for a column name which will be properly quoted by the method, and definition
     * stands for the column type which can contain an abstract DB type.
     *
     * For example,
     *
     * ```php
     * $sql = $queryBuilder->createTable('user', [
     *  'id' => 'pk',
     *  'name' => 'string',
     *  'age' => 'integer',
     * ]);
     * ```
     *
     * @param string $table the name of the index to be created. The name will be properly quoted by the method.
     * @param array $columns the columns (name => definition) in the new index.
     * @param string $options additional SQL fragment that will be appended to the generated SQL.
     * @return string the SQL statement for creating a new index.
     * @since 2.0.14
     */
    public function createTable($table, $columns, $options = null)
    {
        $cols = [];
        foreach ($columns as $name => $type) {
            if (is_string($name)) {
                $cols[] = "\t" . $this->db->quoteColumnName($name) . ' ' . $type;
            } else {
                $cols[] = "\t" . $type;
            }
        }
        $sql = 'CREATE TABLE ' . $this->db->quoteTableName($table) . " (\n" . implode(",\n", $cols) . "\n)";

        return $options === null ? $sql : $sql . ' ' . $options;
    }
    
    /**
     * Builds a SQL statement for dropping a index.
     * @param string $table the table to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping a index.
     * @since 2.0.14
     */
    public function dropTable($table)
    {
        return 'DROP TABLE ' . $this->db->quoteTableName($table);
    }
    
    /**
     * Builds a SQL statement for adding a new index column.
     * @param string $table the index that the new column will be added to. The index name will be properly quoted by the method.
     * @param string $column the name of the new column. The name will be properly quoted by the method.
     * @param string $type the column type.
     * @return string the SQL statement for adding a new column.
     * @since 2.0.14
     */
    public function addColumn($table, $column, $type)
    {
        return 'ALTER TABLE ' . $this->db->quoteTableName($table)
            . ' ADD COLUMN ' . $this->db->quoteColumnName($column) . ' '
            . $type;
    }
    
    /**
     * Builds a SQL statement for dropping a index column.
     * @param string $table the index whose column is to be dropped. The name will be properly quoted by the method.
     * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping a index column.
     * @since 2.0.14
     */
    public function dropColumn($table, $column)
    {
        return 'ALTER TABLE ' . $this->db->quoteTableName($table)
            . ' DROP COLUMN ' . $this->db->quoteColumnName($column);
    }
        
}
