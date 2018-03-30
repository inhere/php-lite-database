<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/10/18
 * Time: 下午9:39
 */

namespace Inhere\LiteDb;

use PDO;
use PDOStatement;

/**
 * Class ExtendedPdo - for mysql, sqlite, pgSql database
 * @package Inhere\LiteDb
 */
class ExtendedPdo extends AbstractLiteDb
{
    /** @var PDO */
    protected $pdo;

    /** @var bool */
    protected $debug = false;

    /** @var string */
    protected $databaseName;

    /** @var string */
    protected $tablePrefix;

    /** @var string */
    protected $prefixPlaceholder = '{@pfx}';

    /** @var string */
    protected $quoteNamePrefix = '"';

    /** @var string */
    protected $quoteNameSuffix = '"';

    /** @var string */
    protected $quoteNameEscapeChar = '"';

    /** @var string */
    protected $quoteNameEscapeReplace = '""';

    /**
     * All of the queries run against the connection.
     * @var array
     * [
     *  [time, category, message, context],
     *  ... ...
     * ]
     */
    protected $queryLog = [];

    /**
     * database config
     * @var array
     */
    protected $config = [
        'driver' => 'mysql', // 'sqlite'
        // 'dsn' => 'mysql:host=localhost;port=3306;dbname=test;charset=UTF8',
        'host' => 'localhost',
        'port' => '3306',
        'user' => 'root',
        'password' => '',
        'database' => 'test',
        'charset' => 'utf8',

        'timeout' => 0,
        'timezone' => null,
        'collation' => 'utf8_unicode_ci',

        'options' => [],

        'tablePrefix' => '',

        'debug' => false,
        // retry times.
        'retry' => 0,
    ];

    /**
     * The default PDO connection options.
     * @var array
     */
    protected static $pdoOptions = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES "UTF8"',
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * Is this driver supported.
     * @param string $driver
     * @return bool
     */
    public static function isSupported(string $driver): bool
    {
        return \in_array($driver, \PDO::getAvailableDrivers(), true);
    }

    /**
     * @param array $config
     * @throws \RuntimeException
     */
    public function __construct(array $config = [])
    {
        if (!class_exists(\PDO::class, false)) {
            throw new \RuntimeException("The php extension 'pdo' is required.");
        }

        parent::__construct($config);

        // init something...
        $this->debug = (bool)$this->config['debug'];
        $this->tablePrefix = $this->config['tablePrefix'];
        $this->databaseName = $this->config['database'];

        $retry = (int) $this->config['retry'];
        $this->config['retry'] = ($retry > 0 && $retry <= 5) ? $retry : 0;
        $this->config['options'] = static::$pdoOptions + $this->config['options'];

        if (!self::isSupported($this->config['driver'])) {
            throw new \RuntimeException("The system is not support driver: {$this->config['driver']}");
        }

        $this->initQuoteNameChar($this->config['driver']);
    }

    /**
     * @return $this
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function connect(): self
    {
        if ($this->pdo) {
            return $this;
        }

        $config = $this->config;
        $retry = (int) $config['retry'];
        $retry = ($retry > 0 && $retry <= 5) ? $retry : 0;
        $dsn = DsnHelper::getDsn($config);

        do {
            try {
                $this->pdo = new PDO($dsn, $config['user'], $config['password'], $config['options']);
                break;
            } catch (\PDOException $e) {
                if ($retry <= 0) {
                    throw new \PDOException('Could not connect to DB: ' . $e->getMessage() . '. DSN: ' . $dsn);
                }
            }

            $retry--;
            \usleep(50000);
        } while ($retry >= 0);

        $this->log('connect to DB server', ['config' => $config], 'connect');
        $this->fire(self::CONNECT, [$this]);

        return $this;
    }

    /**
     * reconnect
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function reconnect()
    {
        $this->pdo = null;
        $this->connect();
    }

    /**
     * disconnect
     */
    public function disconnect()
    {
        $this->fire(self::DISCONNECT, [$this]);
        $this->pdo = null;
    }

    /**
     * @param $name
     * @param array $arguments
     * @return mixed
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function __call($name, array $arguments)
    {
        $this->connect();

        if (!\method_exists($this->pdo, $name)) {
            $class = \get_class($this);
            throw new \InvalidArgumentException("Class '{$class}' does not have a method '{$name}'");
        }

        return $this->pdo->$name(...$arguments);
    }

    /**************************************************************************
     * extra methods
     *************************************************************************/

    /**
     * @var array
     */
    const SELECT_NODES = [
        // string: 'id, name'; array: ['id', 'name']
        'select',
        'from',
        // string: full join clause; array: [$table, $condition, $type = 'LEFT']
        'join',

        /** @see handleWheres() */
        'where',

        'having', // [$conditions, $glue = 'AND']
        'group', // 'id, name' || ['id', 'name']
        'order', // 'created ASC' || ['created', 'publish', 'DESC'] ['created ASC', 'publish DESC']
        'limit', // 10 OR [2, 10]
    ];

    /**
     * @var array
     */
    const UPDATE_NODES = ['update', 'set', 'where', 'order', 'limit'];

    /**
     * @var array
     */
    const DELETE_NODES = ['from', 'join', 'where', 'order', 'limit'];

    /**
     * @var array
     */
    const SELECT_OPTIONS = [
        /* data index column. */
        'indexKey' => null,

        /*
        data load type, in :
        'object' -- return object, instanceof the 'class'
        'column'       -- return array, only  [ 'value' ]
        'assoc'       -- return array, Contain  [ 'column' => 'value']
         */
        'fetchType' => 'assoc',
        'class' => null, // a className. when 'fetchType' eq 'object'
    ];

    /**
     * Run a select statement, fetch one
     * @param  string $from
     * @param  array|string|int $wheres
     * @param  string|array $select
     * @param  array $options
     * @return array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function findOne(string $from, $wheres = 1, $select = '*', array $options = []): array
    {
        $options['select'] = $this->qns($select ?: '*');
        $options['from'] = $this->qn($from);

        list($where, $bindings) = $this->handleWheres($wheres);

        $options['where'] = $where;
        $options['limit'] = 1;

        $statement = $this->compileSelect($options);

        if (isset($options['dumpSql'])) {
            return [$statement, $bindings];
        }

        if ($class = $options['class'] ?? null) {
            return $this->fetchObject($statement, $bindings, $class);
        }

        $method = 'fetchAssoc';

        if (isset($options['fetchType'])) {
            if ($options['fetchType'] === 'column') {
                $method = 'fetchColumn';
            } elseif ($options['fetchType'] === 'value') {
                $method = 'fetchValue';
            }
        }

        return $this->$method($statement, $bindings);
    }

    /**
     * Run a select statement, fetch all
     * @param  string $from
     * @param  array|string|int $wheres
     * @param  string|array $select
     * @param  array $options
     * @return array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function findAll(string $from, $wheres = 1, $select = '*', array $options = []): array
    {
        $options['select'] = $this->qns($select ?: '*');
        $options['from'] = $this->qn($from);

        list($where, $bindings) = $this->handleWheres($wheres);

        $options['where'] = $where;

        if (!isset($options['limit'])) {
            $options['limit'] = 1000;
        }

        $statement = $this->compileSelect($options);

        if (isset($options['dumpSql'])) {
            return [$statement, $bindings];
        }

        $indexKey = $options['indexKey'] ?? null;

        if ($class = $options['class'] ?? null) {
            return $this->fetchObjects($statement, $bindings, $class, $indexKey);
        }

        $method = 'fetchAssocs';

        if (isset($options['fetchType'])) {
            if ($options['fetchType'] === 'column') {
                // for get columns, indexKey is column number.
                $method = 'fetchColumns';
            } elseif ($options['fetchType'] === 'value') {
                $method = 'fetchValues';
            }
        }

        return $this->$method($statement, $bindings, $indexKey);
    }

    /**
     * Run a statement for insert a row
     * @param  string $from
     * @param  array $data <column => value>
     * @param  array $options
     * @return int|array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function insert(string $from, array $data, array $options = [])
    {
        if (!$data) {
            throw new \InvalidArgumentException('The data inserted into the database cannot be empty');
        }

        list($statement, $bindings) = $this->compileInsert($from, $data);

        if (isset($options['dumpSql'])) {
            return [$statement, $bindings];
        }

        $this->fetchAffected($statement, $bindings);

        // 'sequence' For special driver, like PgSQL
        return isset($options['sequence']) ? $this->lastInsertId($options['sequence']) : $this->lastInsertId();
    }

    /**
     * Run a statement for insert multi row
     * @param string $from
     * @param array $dataSet
     * @param  array $options
     * @return int|array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function insertBatch(string $from, array $dataSet, array $options = [])
    {
        list($statement, $bindings) = $this->compileInsert($from, $dataSet, $options['columns'] ?? [], true);

        if (isset($options['dumpSql'])) {
            return [$statement, $bindings];
        }

        return $this->fetchAffected($statement, $bindings);
    }

    /**
     * Run a update statement
     * @param  string $from
     * @param  array|string $wheres
     * @param  array $values
     * @param array $options
     * @return int|array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function update(string $from, $wheres, array $values, array $options = [])
    {
        list($where, $bindings) = $this->handleWheres($wheres);

        $options['update'] = $this->qn($from);
        $options['where'] = $where;

        $statement = $this->compileUpdate($values, $bindings, $options);

        if (isset($options['dumpSql'])) {
            return [$statement, $bindings];
        }

        return $this->fetchAffected($statement, $bindings);
    }

    /**
     * Run a delete statement
     * @param  string $from
     * @param  array|string $wheres
     * @param  array $options
     * @return int|array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function delete(string $from, $wheres, array $options = [])
    {
        if (!$wheres) {
            throw new \InvalidArgumentException('Safety considerations, where conditions can not be empty');
        }

        list($where, $bindings) = $this->handleWheres($wheres);

        $options['from'] = $this->qn($from);
        $options['where'] = $where;

        $statement = $this->compileDelete($options);

        if (isset($options['dumpSql'])) {
            return [$statement, $bindings];
        }

        return $this->fetchAffected($statement, $bindings);
    }

    /**
     * count
     * ```
     * $db->count();
     * ```
     * @param  string $table
     * @param  array|string $wheres
     * @return int
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function count(string $table, $wheres): int
    {
        list($where, $bindings) = $this->handleWheres($wheres);
        $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";

        $result = $this->fetchObject($sql, $bindings);

        return $result ? (int)$result->total : 0;
    }

    /**
     * exists
     * ```
     * $db->exists();
     * // SQL: select exists(select * from `table` where (`phone` = 152xxx)) as `exists`;
     * ```
     * @param $statement
     * @param array $bindings
     * @return int
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function exists($statement, array $bindings = []): int
    {
        $sql = \sprintf('SELECT EXISTS(%s) AS `exists`', $statement);

        $result = $this->fetchObject($sql, $bindings);

        return $result ? $result->exists : 0;
    }

    /********************************************************************************
     * fetch affected methods
     *******************************************************************************/

    /**
     * @param string $statement
     * @param array $bindings
     * @throws \PDOException
     * @throws \InvalidArgumentException
     * @return int
     */
    public function fetchAffected($statement, array $bindings = []): int
    {
        $sth = $this->execute($statement, $bindings);
        $affected = $sth->rowCount();

        $this->freeResource($sth);

        return $affected;
    }

    /********************************************************************************
     * fetch a row methods
     *******************************************************************************/

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchAssoc(string $statement, array $bindings = [])
    {
        return $this->fetchOne($statement, $bindings);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @return mixed
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchOne(string $statement, array $bindings = [])
    {
        $sth = $this->execute($statement, $bindings);
        $result = $sth->fetch(PDO::FETCH_ASSOC);

        $this->freeResource($sth);

        return $result;
    }

    /**
     * 从结果集中的下一行返回单独的一列
     *
     * @param string $statement
     * @param array $bindings
     * @param int $columnNum 你想从行里取回的列的索引数字（以0开始的索引）
     * @return mixed
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchColumn(string $statement, array $bindings = [], int $columnNum = 0)
    {
        $sth = $this->execute($statement, $bindings);
        $result = $sth->fetchColumn($columnNum);

        $this->freeResource($sth);

        return $result;
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @return array|bool
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchValue(string $statement, array $bindings = [])
    {
        $sth = $this->execute($statement, $bindings);
        $result = $sth->fetch(PDO::FETCH_NUM);

        $this->freeResource($sth);

        return $result;
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchObject(string $statement, array $bindings = [], $class = 'stdClass', array $args = [])
    {
        $sth = $this->execute($statement, $bindings);

        if (!empty($args)) {
            $result = $sth->fetchObject($class, $args);
        } else {
            $result = $sth->fetchObject($class);
        }

        $this->freeResource($sth);

        return $result;
    }

    /********************************************************************************
     * fetch multi rows methods
     *******************************************************************************/

    /**
     * @param string $statement
     * @param array $bindings
     * @param string|int $indexKey
     * @param string $class a class name or fetch style name.
     * @return array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchAll(string $statement, array $bindings = [], $indexKey = null, $class = 'assoc'): array
    {
        // $sth = $this->execute($statement, $bindings);
        // $result = $sth->fetchAll(PDO::FETCH_ASSOC);

        if (\strtolower($class) === 'value') {
            return $this->fetchValues($statement, $bindings, $indexKey);
        }

        if (\strtolower($class) === 'assoc') {
            return $this->fetchAssocs($statement, $bindings, $indexKey);
        }

        return $this->fetchObjects($statement, $class, $indexKey);
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchAssocs(string $statement, array $bindings = [], $indexKey = null): array
    {
        $data = [];
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            if ($indexKey) {
                $data[$row[$indexKey]] = $row;
            } else {
                $data[] = $row;
            }
            // $data[current($row)] = $row;
        }

        $this->freeResource($sth);

        return $data;
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchValues(string $statement, array $bindings = [], $indexKey = null): array
    {
        $data = [];
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch(PDO::FETCH_NUM)) {
            if ($indexKey) {
                $data[$row[$indexKey]] = $row;
            } else {
                $data[] = $row;
            }
        }

        $this->freeResource($sth);

        return $data;
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchColumns(string $statement, array $bindings = [], int $columnNum = 0): array
    {
        $sth = $this->execute($statement, $bindings);
        $column = $sth->fetchAll(PDO::FETCH_COLUMN, $columnNum);

        $this->freeResource($sth);

        return $column;
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchObjects(string $statement, array $bindings = [], $class = 'stdClass', $indexKey = null, array $args = []): array
    {
        $data = [];
        $sth = $this->execute($statement, $bindings);

        // if (!empty($args)) {
        //     $result = $sth->fetchAll(PDO::FETCH_CLASS, $class, $args);
        // } else {
        //     $result = $sth->fetchAll(PDO::FETCH_CLASS, $class);
        // }

        while ($row = $sth->fetchObject($class, $args)) {
            if ($indexKey) {
                $data[$row->$indexKey] = $row;
            } else {
                $data[] = $row;
            }
        }

        $this->freeResource($sth);

        return $data;
    }

    /**
     * 每行调用一次函数. 将每行的列值作为参数传递给指定的函数，并返回调用函数后的结果。
     *
     * @param string $statement
     * @param array $bindings
     * @param callable $func
     *
     * ```php
     * function ($col1, $col2) {
     *  return $col1 . $col2;
     * }
     * ```
     *
     * @return array
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchFuns(callable $func, string $statement, array $bindings = []): array
    {
        $sth = $this->execute($statement, $bindings);
        $result = $sth->fetchAll(PDO::FETCH_FUNC, $func);

        $this->freeResource($sth);

        return $result;
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchGroups(string $statement, array $bindings = [], $style = PDO::FETCH_COLUMN): array
    {
        $sth = $this->execute($statement, $bindings);
        $group = $sth->fetchAll(PDO::FETCH_GROUP | $style);

        $this->freeResource($sth);

        return $group;
    }

    /**
     * {@inheritdoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function fetchPairs(string $statement, array $bindings = []): array
    {
        $sth = $this->execute($statement, $bindings);

        $result = $sth->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->freeResource($sth);

        return $result;
    }


    /********************************************************************************
     * Generator methods
     *******************************************************************************/

    /**
     * @param string $statement
     * @param array $bindings
     * @param int $fetchType
     * @return \Generator
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function cursor($statement, array $bindings = [], $fetchType = PDO::FETCH_ASSOC)
    {
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch($fetchType)) {
            $key = \current($row);
            yield $key => $row;
        }

        $this->freeResource($sth);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @throws \PDOException
     * @throws \InvalidArgumentException
     * @return \Generator
     */
    public function yieldAssoc($statement, array $bindings = [])
    {
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $key = \current($row);
            yield $key => $row;
        }

        $this->freeResource($sth);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @return \Generator
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function yieldAll($statement, array $bindings = [])
    {
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }

        $this->freeResource($sth);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @return \Generator
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function yieldValue($statement, array $bindings = [])
    {
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch(PDO::FETCH_NUM)) {
            yield $row;
        }

        $this->freeResource($sth);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @param int $columnNum
     * @return \Generator
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function yieldColumn($statement, array $bindings = [], int $columnNum = 0)
    {
        $sth = $this->execute($statement, $bindings);

        // while ($row = $sth->fetch(PDO::FETCH_NUM)) {
        //     yield $row[0];
        // }

        while ($colValue = $sth->fetchColumn($columnNum)) {
            yield $colValue;
        }

        $this->freeResource($sth);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @param string $class
     * @param array $args
     * @return \Generator
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function yieldObjects($statement, array $bindings = [], $class = 'stdClass', array $args = [])
    {
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetchObject($class, $args)) {
            yield $row;
        }

        $this->freeResource($sth);
    }

    /**
     * @param string $statement
     * @param array $bindings
     * @return \Generator
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function yieldPairs($statement, array $bindings = [])
    {
        $sth = $this->execute($statement, $bindings);

        while ($row = $sth->fetch(PDO::FETCH_KEY_PAIR)) {
            yield $row;
        }

        $this->freeResource($sth);
    }

    /********************************************************************************
     * extended methods
     *******************************************************************************/

    /**
     * @param string $statement
     * @param array $params
     * @return PDOStatement
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function execute($statement, array $params = []): PDOStatement
    {
        // trigger before event
        $this->fire(self::BEFORE_EXECUTE, [$statement, $params, 'execute']);

        $sth = $this->prepareWithBindings($statement, $params);
        $sth->execute();

        // trigger after event
        $this->fire(self::AFTER_EXECUTE, [$statement, 'execute']);

        return $sth;
    }

    /**
     * @param string $statement
     * @param array $params
     * @return PDOStatement
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function prepareWithBindings($statement, array $params = []): PDOStatement
    {
        $this->connect();
        $statement = $this->replaceTablePrefix($statement);

        // if there are no values to bind ...
        if (!$params) {
            // ... use the normal preparation
            return $this->prepare($statement);
        }

        // prepare the statement
        $sth = $this->pdo->prepare($statement);

        $this->log($statement, $params);

        // for the placeholders we found, bind the corresponding data values
        $this->bindValues($sth, $params);

        // done
        return $sth;
    }

    /**
     * 事务
     * {@inheritDoc}
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function transactional(callable $func)
    {
        if (!\is_callable($func)) {
            throw new \InvalidArgumentException('Expected argument of type "callable", got "' . \gettype($func) . '"');
        }

        $this->connect();
        $this->pdo->beginTransaction();

        try {
            $return = $func($this);
            $this->pdo->commit();

            return $return ?: true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param PDOStatement $sth
     * @param array|\ArrayIterator $bindings
     */
    public function bindValues(PDOStatement $sth, $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $sth->bindValue(
                \is_string($key) ? $key : $key + 1, $value,
                \is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }

    /**
     * @param PDOStatement $sth
     * @param $key
     * @param $val
     * @return bool
     * @throws \RuntimeException
     */
    protected function bindValue(PDOStatement $sth, $key, $val): bool
    {
        if (\is_int($val)) {
            return $sth->bindValue($key, $val, PDO::PARAM_INT);
        }

        if (\is_bool($val)) {
            return $sth->bindValue($key, $val, PDO::PARAM_BOOL);
        }

        if (null === $val) {
            return $sth->bindValue($key, $val, PDO::PARAM_NULL);
        }

        if (!is_scalar($val)) {
            $type = \gettype($val);
            throw new \RuntimeException("Cannot bind value of type '{$type}' to placeholder '{$key}'");
        }

        return $sth->bindValue($key, $val);
    }

    /**************************************************************************
     * helper method
     *************************************************************************/

    /**
     * Check whether the connection is available
     * @return bool
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function ping(): bool
    {
        try {
            $this->connect();
            $this->pdo->query('SELECT 1')->fetchColumn();
        } catch (\PDOException $e) {
            if (\strpos($e->getMessage(), 'server has gone away') !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * handle where condition
     * @param array|string|\Closure|mixed $wheres
     * @example
     * ```
     * ...
     * $result = $db->findAll('user', [
     *      'userId' => 23,      // ==> 'AND `userId` = 23'
     *      'title' => 'test',  // value will auto add quote, equal to "AND title = 'test'"
     *
     *      ['publishTime', '>', '0'],  // ==> 'AND `publishTime` > 0'
     *      ['createdAt', '<=', 1345665427, 'OR'],  // ==> 'OR `createdAt` <= 1345665427'
     *      ['id', 'IN' ,[4,5,56]],   // ==> '`id` IN ('4','5','56')'
     *      ['id', 'NOT IN', [4,5,56]], // ==> '`id` NOT IN ('4','5','56')'
     *      // a closure
     *      function () {
     *          return 'a < 5 OR b > 6';
     *      }
     * ]);
     * ```
     * @return array
     * @throws \InvalidArgumentException
     */
    public function handleWheres($wheres): array
    {
        if (\is_object($wheres) && $wheres instanceof \Closure) {
            $wheres = $wheres($this);
        }

        if (!$wheres || $wheres === 1) {
            return [1, []];
        }

        if (\is_string($wheres)) {
            return [$wheres, []];
        }

        $nodes = $bindings = [];

        if (\is_array($wheres)) {
            foreach ($wheres as $key => $val) {
                if (\is_object($val) && $val instanceof \Closure) {
                    $val = $val($this);
                }

                $key = trim($key);

                // string key: $key is column name, $val is column value
                if ($key && !\is_numeric($key)) {
                    $nodes[] = 'AND ' . $this->qn($key) . '= ?';
                    $bindings[] = $val;

                    // array: [column, operator(e.g '=', '>=', 'IN'), value, option(Is optional, e.g 'AND', 'OR')]
                } elseif (\is_array($val)) {
                    if (!isset($val[2])) {
                        throw new \InvalidArgumentException('Where condition data is incomplete, at least 3 elements');
                    }

                    $bool = $val[3] ?? 'AND';
                    $nodes[] = \strtoupper($bool) . ' ' . $this->qn($val[0]) . " {$val[1]} ?";
                    $bindings[] = $val[2];
                } else {
                    $val = trim((string)$val);
                    $nodes[] = \preg_match('/^and |or /i', $val) ? $val : 'AND ' . $val;
                }
            }
        }

        $where = \implode(' ', $nodes);
        unset($nodes);

        return [$this->removeLeadingBoolean($where), $bindings];
    }

    /**
     * @param array $options
     * @return string
     */
    public function compileSelect(array $options): string
    {
        return $this->compileNodes(self::SELECT_NODES, $options);
    }

    /**
     * @param $from
     * @param array $data
     * @param array $columns
     * @param bool $isBatch
     * @return array
     */
    public function compileInsert(string $from, array $data, array $columns = [], $isBatch = false): array
    {
        $bindings = [];
        $table = $this->qn($from);

        if (!$isBatch) {
            $bindings = \array_values($data);
            $nameStr = $this->qns(\array_keys($data));
            $valueStr = '(' . rtrim(\str_repeat('?,', \count($data)), ',') . ')';
        } else {
            if ($columns) {
                $columnNum = \count($columns);
                $nameStr = $this->qns($columns);
            } else {
                $columnNum = \count($data[0]);
                $nameStr = $this->qns(\array_keys($data[0]));
            }

            $valueStr = '';
            $rowTpl = '(' . rtrim(\str_repeat('?,', $columnNum), ',') . '), ';

            foreach ($data as $row) {
                $bindings = \array_merge($bindings, \array_values($row));
                $valueStr .= $rowTpl;
            }

            $valueStr = \rtrim($valueStr, ', ');
        }

        return ["INSERT INTO $table ($nameStr) VALUES $valueStr", $bindings];
    }

    /**
     *
     * @param array $updates
     * @param array $bindings
     * @param array $options
     * @return string
     */
    public function compileUpdate(array $updates, array &$bindings, array $options): string
    {
        $nodes = $values = [];

        foreach ($updates as $column => $value) {
            if (\is_int($column)) {
                continue;
            }

            $nodes[] = $this->qn($column) . '= ?';
            $values[] = $value;
        }

        $options['set'] = \implode(',', $nodes);
        $bindings = \array_merge($values, $bindings);

        return $this->compileNodes(self::UPDATE_NODES, $options);
    }

    /**
     * @param array $options
     * @return string
     */
    public function compileDelete(array $options): string
    {
        return 'DELETE ' . $this->compileNodes(self::DELETE_NODES, $options);
    }

    /**
     * @param array $commandNodes
     * @param array $data
     * @return string
     */
    public function compileNodes(array $commandNodes, array $data): string
    {
        $nodes = [];

        foreach ($commandNodes as $node) {
            if (!isset($data[$node])) {
                continue;
            }

            $val = $data[$node];
            if ($isString = \is_string($val)) {
                $val = trim($val);
            }

            if ($node === 'join') {
                //string: full join structure. e.g 'left join TABLE t2 on t1.id = t2.id'
                if ($isString) {
                    $nodes[] = \stripos($val, 'join') !== false ? $val : 'LEFT JOIN ' . $val;

                    // array: ['TABLE t2', 't1.id = t2.id', 'left']
                } elseif (\is_array($val)) {
                    $nodes[] = ($val[2] ?? 'LEFT') . " JOIN {$val[0]} ON {$val[1]}";
                }

                continue;
            }

            if ($node === 'having') {
                // string: 'having AND col = val'
                if ($isString) {
                    $nodes[] = \stripos($val, 'having') !== false ? $val: 'HAVING ' . $val;

                    // array: ['t1.id = t2.id', 'AND']
                } elseif (\is_array($val)) {
                    $nodes[] = 'HAVING ' . ($val[1] ?? 'AND') . " {$val[0]}";
                }

                continue;
            }

            if ($node === 'group') {
                $nodes[] = 'GROUP BY ' . $this->qns($val);
                continue;
            }

            if ($node === 'order') {
                $nodes[] = 'ORDER BY ' . ($isString ? $val : \implode(' ', $val));
                continue;
            }

            $nodes[] = \strtoupper($node) . ' ' . ($isString ? $val : \implode(',', (array)$val));
        }

        return \implode(' ', $nodes);
    }

    /**
     * @param array|string $names
     * @return string
     */
    public function qns($names): string
    {
        if (\is_string($names)) {
            $names = trim($names, ', ');
            $names = \strpos($names, ',') ? \explode(',', $names) : [$names];
        }

        $names = \array_map(function ($field) {
            return $this->quoteName($field);
        }, $names);

        return \implode(',', $names);
    }

    /**
     * {@inheritdoc}
     */
    public function qn(string $name): string
    {
        return $this->quoteName($name);
    }

    /**
     * @param string $name
     * @return string
     */
    public function quoteName(string $name): string
    {
        // field || field as f
        if (\strpos($name, '.') === false) {
            return $this->quoteSingleName($name);
        }

        // t1.field || t1.field as f
        return \implode('.', \array_map([$this, 'quoteSingleName'], \explode('.', $name)));
    }

    /**
     * @param string $name
     * @return string
     */
    public function quoteSingleName(string $name): string
    {
        if ($name === '*') {
            return $name;
        }

        if (\stripos($name, ' as ') === false) {
            if (\strpos($name, $this->quoteNamePrefix) !== false) {
                $name = \str_replace($this->quoteNameEscapeChar, $this->quoteNameEscapeReplace, $name);
            }

            return $this->quoteNamePrefix . $name . $this->quoteNameSuffix;
        }

        // field as f
        $name = \str_ireplace(' as ', '#', $name);

        return \implode(' AS ', \array_map([$this, 'quoteSingleName'], \explode('#', $name)));
    }

    /**
     * {@inheritdoc}
     */
    protected function initQuoteNameChar($driver): void
    {
        switch ($driver) {
            case 'mysql':
                $this->quoteNamePrefix = '`';
                $this->quoteNameSuffix = '`';
                $this->quoteNameEscapeChar = '`';
                $this->quoteNameEscapeReplace = '``';

                return;
            case 'sqlsrv':
                $this->quoteNamePrefix = '[';
                $this->quoteNameSuffix = ']';
                $this->quoteNameEscapeChar = ']';
                $this->quoteNameEscapeReplace = '][';

                return;
            default:
                $this->quoteNamePrefix = '"';
                $this->quoteNameSuffix = '"';
                $this->quoteNameEscapeChar = '"';
                $this->quoteNameEscapeReplace = '""';

                return;
        }
    }

    public function q($value, $type = PDO::PARAM_STR): string
    {
        return $this->quote($value, $type);
    }

    /**
     * @param string|array $value
     * @param int $type
     * @return string
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function quote($value, $type = PDO::PARAM_STR): string
    {
        $this->connect();

        // non-array quoting
        if (!\is_array($value)) {
            return $this->pdo->quote($value, $type);
        }

        // quote array values, not keys, then combine with commas
        /** @var array $value */
        foreach ((array) $value as $k => $v) {
            $value[$k] = $this->pdo->quote($v, $type);
        }

        return \implode(', ', $value);
    }

    /********************************************************************************
     * Pdo methods
     *******************************************************************************/

    /**
     * @param string $statement
     * @return int
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function exec($statement): int
    {
        $this->connect();

        // trigger before event
        $this->fire(self::BEFORE_EXECUTE, [$statement, 'exec']);

        $affected = $this->pdo->exec($this->replaceTablePrefix($statement));

        // trigger after event
        $this->fire(self::AFTER_EXECUTE, [$statement, 'exec']);

        return $affected;
    }

    /**
     * {@inheritDoc}
     * @return PDOStatement
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function query($statement, ...$fetch): PDOStatement
    {
        $this->connect();

        // trigger before event
        $this->fire(self::BEFORE_EXECUTE, [$statement, 'query']);

        $sth = $this->pdo->query($this->replaceTablePrefix($statement), ...$fetch);

        // trigger after event
        $this->fire(self::AFTER_EXECUTE, [$statement, 'query']);

        return $sth;
    }

    /**
     * @param string $statement
     * @param array $options
     * @return PDOStatement
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function prepare($statement, array $options = []): PDOStatement
    {
        $this->connect();
        $this->log($statement, $options);

        return $this->pdo->prepare($this->replaceTablePrefix($statement), $options);
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function beginTransaction(): bool
    {
        $this->connect();

        return $this->pdo->beginTransaction();
    }

    /**
     * {@inheritDoc}
     * @throws \InvalidArgumentException
     * @throws \PDOException
     */
    public function inTransaction(): bool
    {
        $this->connect();

        return $this->pdo->inTransaction();
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function commit(): bool
    {
        $this->connect();

        return $this->pdo->rollBack();
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function rollBack(): bool
    {
        $this->connect();

        return $this->pdo->rollBack();
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function errorCode()
    {
        $this->connect();

        return $this->pdo->errorCode();
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function errorInfo(): array
    {
        $this->connect();

        return $this->pdo->errorInfo();
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function lastInsertId(string $name = null): string
    {
        $this->connect();

        return $this->pdo->lastInsertId($name);
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function getAttribute($attribute)
    {
        $this->connect();

        return $this->pdo->getAttribute($attribute);
    }

    /**
     * {@inheritDoc}
     * @throws \PDOException
     * @throws \InvalidArgumentException
     */
    public function setAttribute($attribute, $value): bool
    {
        $this->connect();

        return $this->pdo->setAttribute($attribute, $value);
    }

    /**
     * {@inheritDoc}
     */
    public static function getAvailableDrivers(): array
    {
        return PDO::getAvailableDrivers();
    }

    /**
     * @param PDOStatement $sth
     * @return $this
     */
    public function freeResource($sth = null): self
    {
        if ($sth && $sth instanceof PDOStatement) {
            $sth->closeCursor();
        }

        return $this;
    }

    /**************************************************************************
     * getter/setter methods
     *************************************************************************/

    /**
     * Get the name of the driver.
     * @return string
     */
    public function getDriverName(): string
    {
        return $this->config['driver'];
    }

    /**
     * Get the name of the connected database.
     * @return string
     */
    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    /**
     * Set the name of the connected database.
     * @param  string $database
     */
    public function setDatabaseName($database): void
    {
        $this->databaseName = $database;
    }

    /**
     * Get the table prefix for the connection.
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Set the table prefix in use by the connection.
     * @param  string $prefix
     * @return void
     */
    public function setTablePrefix($prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * @param $sql
     * @return mixed
     */
    public function replaceTablePrefix($sql)
    {
        return \str_replace($this->prefixPlaceholder, $this->tablePrefix, (string)$sql);
    }

    /**
     * Remove the leading boolean from a statement.
     * @param  string $value
     * @return string
     */
    protected function removeLeadingBoolean($value): string
    {
        return \preg_replace('/^and |or /i', '', $value, 1);
    }

    /**
     * @param string $message
     * @param array $context
     * @param string $category
     */
    public function log(string $message, array $context = [], $category = 'query'): void
    {
        if ($this->debug) {
            $this->queryLog[] = [microtime(1), 'db.' . $category, $message, $context];
        }
    }

    /**
     * @return array
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * @return PDO
     */
    public function getPdo(): PDO
    {
        if ($this->pdo instanceof \Closure) {
            return $this->pdo = ($this->pdo)($this);
        }

        return $this->pdo;
    }

    /**
     * @param PDO $pdo
     */
    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return (bool) $this->pdo;
    }

}
