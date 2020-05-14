<?php
/*
 * @Description:
 * @Author: Misuoka
 * @Github: https://github.com/misuoka
 * @Licensed: MIT
 * @Version: 1.0.0
 * @Date: 2019-04-22 14:55:31
 * @LastEditTime: 2019-04-25 11:27:00
 */
namespace ocipdo;

class PDOStatement extends \PDOStatement
{
    public $queryString;

    private $ocipdo       = null;
    private $connection   = null;
    private $ocistmt      = null;
    private $stmtSql      = null;
    private $stmtType     = null;
    private $fetchMode    = null;
    private $case         = null;
    private $bind         = [];
    private $bindLob      = [];
    private $bindType     = [];
    private $proceOutdata = []; // 存储过程输出参数的缓存

    public function __construct($ocipdo, $sql)
    {
        try {
            $this->ocipdo     = $ocipdo;
            $this->connection = $ocipdo->getConnection();
            $this->case       = $ocipdo->getAttribute(\PDO::ATTR_CASE);
            $this->fetchMode  = \PDO::FETCH_BOTH;
            $this->stmtSql    = $this->insertParamMarks($sql);
            $this->ocistmt    = \oci_parse($this->connection, $this->stmtSql);
            $this->stmtType   = oci_statement_type($this->ocistmt);
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }
    }

    // 安排一个特定的变量绑定到一个查询结果集中给定的列。每次调用 PDOStatement::fetch() 或 PDOStatement::fetchAll() 都将更新所有绑定到列的变量。
    public function bindColumn($column, &$value, $type = null, $maxlen = null, $driverdata = null)
    {
        $column                  = is_numeric($column) ? $column : strtoupper($column);
        $this->bind[$column]     = &$value; //
        $this->bindType[$column] = $type;
    }

    // Declaration of ocipdo\PDOStatement::bindParam($parameter, &$value, int $type = PDO::PARAM_STR, ?int $length = NULL, $options = NULL): think\oracle\boolean should be compa tible with PDOStatement::bindParam($paramno, &$param, $type = NULL, $maxlen = NULL, $driverdata = NULL)
    public function bindParam($param, &$value, $type = \PDO::PARAM_STR, $length = null, $options = null)
    {
        $ret = false;

        try {
            $param   = $this->parseParam($param);
            $ociType = $this->getOciType($type);

            if ($type && $this->isProcedureOutType($type)) {

                $outkey                      = str_replace(':', '', $param);
                $this->proceOutdata[$outkey] = &$value; // 作为out参数输出的缓存，同时也兼顾bindParam的变量引用
                $ret                         = \oci_bind_by_name($this->ocistmt, $param, $this->proceOutdata[$outkey], $length, $ociType);

            } elseif ($type || $length) {

                if ($ociType == \SQLT_CLOB || $ociType == \SQLT_BLOB) {
                    $lob                   = \oci_new_descriptor($this->connection, \OCI_D_LOB);
                    $ret                   = \oci_bind_by_name($this->ocistmt, $param, $lob, -1, $ociType);
                    $this->bindLob[$param] = array(
                        'lob'   => $lob,
                        'value' => $value,
                    );
                    $lob->writeTemporary($value);
                } else {
                    $ret = \oci_bind_by_name($this->ocistmt, $param, $value, $length ?: -1, $ociType);
                }

            } else {
                $ret = \oci_bind_by_name($this->ocistmt, $param, $value);
            }

            if (!isset($lob)) {
                $this->bind[$param]     = $value;
                $this->bindType[$param] = $type;
            }
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        return $ret;
    }

    // \PDO::PARAM_NULL; // 0
    // \PDO::PARAM_INT; // 1
    // \PDO::PARAM_STR; // 2
    // \PDO::PARAM_LOB; // 3
    // \PDO::PARAM_STMT; // 4
    // \PDO::PARAM_BOOL; // 5
    // \PDO::PARAM_INPUT_OUTPUT; // 2147483648
    // Declaration of ocipdo\PDOStatement::bindValue($parameter, $value, int $type = PDO::PARAM_STR): think\oracle\boolean should be compatible with PDOStatement::bindValue($paramno, $param, $type = NULL)
    public function bindValue($param, $value, $type = \PDO::PARAM_STR)
    {
        $ret = false;

        try {
            $param   = $this->parseParam($param);
            $ociType = $this->getOciType($type);

            if ($ociType == \SQLT_CLOB || $ociType == \SQLT_BLOB) {

                $lob                   = \oci_new_descriptor($this->connection, \OCI_D_LOB);
                $ret                   = \oci_bind_by_name($this->ocistmt, $param, $lob, -1, $ociType);
                $this->bindLob[$param] = array(
                    'lob'   => $lob,
                    'value' => $value,
                );
                $lob->writeTemporary($value); // 写入LOB

            } else {

                $ret                    = \oci_bind_by_name($this->ocistmt, $param, $value);
                $this->bind[$param]     = $value;
                $this->bindType[$param] = $type;

            }

        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        return $ret;
    }

    public function closeCursor()
    {
        set_error_handler(array($this->ocipdo, "errorHandler"));

        try {
            if($this->ocistmt) {
                \oci_free_statement($this->ocistmt);
            }
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        restore_error_handler();
        $this->ocistmt = null;
    }

    public function columnCount(): int
    {
        $count = 0;

        if ($this->ocistmt) {
            try {
                $count = \oci_num_fields($this->ocistmt);
            } catch (\Exception $e) {
                throw new \PDOException($e->getMessage());
            }
        }

        return $count;
    }

    public function debugDumpParams()
    {
        // SQL: [53] select * from tb_role where rid=:rid and rname=:rname
        // Sent SQL: [50] select * from tb_role where rid='1' and rname='ge'
        // Params:  2
        // Key: Name: [4] :rid
        // paramno=-1
        // name=[4] ":rid"
        // is_param=1
        // param_type=2
        // Key: Name: [6] :rname
        // paramno=-1
        // name=[6] ":rname"
        // is_param=1
        // param_type=2

        $realSql = $this->getRealSql($this->bind, $this->stmtSql);
        $dump    = [
            "SQL: [" . strlen($this->stmtSql) . "] {$this->stmtSql}",
            "Sent SQL: [" . strlen($realSql) . "] {$realSql}",
            "Params:" . sizeof($this->bind),
        ];

        foreach ($this->bind as $key => $value) {
            $dump[] = "Key: Name: [" . strlen($key) . "] {$key}";
            $dump[] = "paramno=-1";
            $dump[] = "name=[" . strlen($key) . "] \"{$key}\"";
            $dump[] = "is_param=1";
            $dump[] = "param_type=" . $this->bindType[$key];
        }

        foreach ($this->bindLob as $key => $value) {
            $dump[] = "Key: Name: [" . strlen($key) . "] {$key}";
            $dump[] = "paramno=-1";
            $dump[] = "name=[" . strlen($key) . "] \"{$key}\"";
            $dump[] = "is_param=1";
            $dump[] = "param_type=3";
        }

        echo implode("\n", $dump);
    }

    public function errorCode()
    {
        return $this->ocipdo->errorCode();
    }

    public function errorInfo()
    {
        return $this->ocipdo->errorInfo();
    }

    public function execute($parameters = [])
    {
        $ret = false;
        set_error_handler(array($this->ocipdo, "errorHandler"));

        try {
            $mode = $this->ocipdo->isAutoCommit() ? \OCI_COMMIT_ON_SUCCESS : \OCI_DEFAULT;

            // 处理本函数传递的绑定数据
            if ($parameters && sizeof($parameters) > 0) {
                foreach ($parameters as $key => $val) {
                    $parm = $key;
                    if (preg_match('/^\d+$/', $key)) {
                        $parm++;
                    }
                    // 需要引用
                    if (!$this->bindParam($parm, $parameters[$key])) {
                        $this->ocipdo->getError();
                        $error = $this->errorInfo();
                        throw new \PDOException($error[2]);
                    }
                }
            }

            $ret = \oci_execute($this->ocistmt, $mode);

            if (!$ret) {
                $this->ocipdo->getError($this->ocistmt);
                $error = $this->errorInfo();
                throw new \PDOException($error[2]);
            }
        } catch (\PDOException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        restore_error_handler();
        return $ret;
    }

    // Declaration of ocipdo\PDOStatement::fetch(int $style, int $cursor_orientation, int $offset = 0): think\oracle\mixed should be compatible with PDOStatement::fetch($how = NULL, $orientation = NULL, $offset = NULL)
    public function fetch($style = null, $orientation = null, $offset = null)
    {
        set_error_handler(array($this->ocipdo, "errorHandler"));

        $result          = null;
        $style           = is_null($style) ? $this->fetchMode : $style;
        $this->fetchMode = $style;

        try {
            if (in_array($this->stmtType, ['BEGIN', 'CALL'])) {
                $result = $this->fetchProcedure($style);
            } else {
                switch ($style) {
                    case \PDO::FETCH_BOTH:
                    case \PDO::FETCH_BOUND:
                        $result = \oci_fetch_array($this->ocistmt, \OCI_BOTH+\OCI_RETURN_NULLS);
                        break;
                    case \PDO::FETCH_ASSOC:
                        $result = \oci_fetch_array($this->ocistmt, \OCI_ASSOC+\OCI_RETURN_NULLS);
                        $result = $this->fieldCase($result);
                        break;
                    case \PDO::FETCH_NUM:
                        $result = \oci_fetch_array($this->ocistmt, \OCI_NUM+\OCI_RETURN_NULLS);
                        break;
                    case \PDO::FETCH_OBJ:
                        $result = \oci_fetch_object($this->ocistmt); // TODO: 此处是否需要转换大小写，需要在PDO MySQL下测试查看
                        break;
                }

                $this->paramBound($result);
            }
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        restore_error_handler();
        return $result;
    }

    // Declaration of ocipdo\PDOStatement::fetchAll(?int $style = NULL, $argument = NULL, array $args = Array): array should be compatible with PDOStatement::fetchAll($how = NULL, $class_name = NULL, $ctor_args = NULL)
    public function fetchAll($style = null, $argument = null, $args = [])
    {
        $style  = is_null($style) ? $this->fetchMode : $style;
        $result = null;
        try {
            if (in_array($this->stmtType, ['BEGIN', 'CALL'])) {
                $result = $this->fetchProcedure($style);
            } else {
                switch ($style) {
                    case \PDO::FETCH_ASSOC:
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_ASSOC);
                        break;
                    case \PDO::FETCH_NUM:
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_NUM);
                        break;
                    case \PDO::FETCH_BOTH:
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_NUM+\OCI_ASSOC);
                        break;
                    case \PDO::FETCH_COLUMN:
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_NUM);
                        $result = array_map(function ($vals) use ($argument) {
                            return $vals[intval($argument)];
                        }, $result);
                        break;
                    case \PDO::FETCH_COLUMN | \PDO::FETCH_GROUP:
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_NUM);
                        $temp = array();
                        foreach ($result as $key => $value) {
                            if (!array_key_exists($value[0], $temp)) {
                                $temp[$value[0]] = array();
                            }
                            array_push($temp[$value[0]], $value[1]);
                        }
                        $result = $temp;
                        break;
                    case \PDO::FETCH_CLASS:
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_ASSOC);
                        $temp = array();
                        foreach ($result as $idx => $data) {
                            array_push($temp, $this->loadDataCreateObject($argument, $data, $args));
                        }
                        $result = $temp;
                        break;
                    case \PDO::FETCH_FUNC:
                        if (!function_exists($argument)) {
                            throw new \PDOException("Function {$argument} does not exists");
                        }
                        $ref  = new \ReflectionFunction($argument);
                        $args = $ref->getNumberOfParameters();
                        if ($args < 1) {
                            throw new \PDOException("Function {$argument} can't receive parameters");
                        }
                        \oci_fetch_all($this->ocistmt, $result, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW+\OCI_NUM);
                        foreach ($result as $idx => $value) {
                            $temp = array();
                            foreach ($value as $key => $data) {
                                array_push($temp, $data);
                            }
                            call_user_func_array($argument, $temp);
                        }
                        break;
                }
            }
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        $result = $this->fieldCase($result);
        return $result;
    }

    // Declaration of ocipdo\PDOStatement::fetchColumn(int $column = 0) should be compatible with PDOStatement::fetchColumn($column_number = NULL)
    public function fetchColumn($column = 0)
    {
        if (in_array($this->stmtType, ['BEGIN', 'CALL'])) {
            $result = $this->fetchProcedure(\PDO::FETCH_NUM);
        } else {
            $result = $this->fetch(\PDO::FETCH_NUM);
        }

        return $result === false ? null : $result[$column]; // 避免严格模式下，抛出异常
    }

    // Declaration of ocipdo\PDOStatement::fetchObject(string $class, array $args): think\oracle\mixed should be compatible with PDOStatement::fetchObject($class_name = NULL, $ctor_args = NULL)
    public function fetchObject($class = "stdClass", $args = null)
    {
        $cls = null;

        try {

            if (in_array($this->stmtType, ['BEGIN', 'CALL'])) {
                $data = $this->fetchProcedure(\PDO::FETCH_ASSOC);
            } else {
                $data = $this->fetch(\PDO::FETCH_ASSOC);
            }

            $cls = $this->loadDataCreateObject($data, $class, $args);
        } catch (\Exception $e) {

        }

        return $cls;
    }

    /**
     * 得到语句的一个属性。当前，不存在通用的属性，只有驱动特定的属性
     *
     * @param integer $attr
     * @return mixed
     */
    public function getAttribute($attr)
    {
        //
    }

    public function getColumnMeta($column)
    {
        // array(7) {
        //     'native_type' =>
        //     string(10) "VAR_STRING"
        //     'pdo_type' =>
        //     int(2)
        //     'flags' =>
        //     array(0) {
        //     }
        //     'table' =>
        //     string(7) "tb_role"
        //     'name' =>
        //     string(4) "rdes"
        //     'len' =>
        //     int(765)
        //     'precision' =>
        //     int(0)
        //   }
        // if (!$this->ocistmt) {
        //     return null;
        // }

        $name       = \oci_field_name($this->ocistmt, $colnum + 1);
        $len        = \oci_field_size($this->ocistmt, $colnum + 1);
        $type       = \oci_field_type($this->ocistmt, $colnum + 1);
        $prec       = \oci_field_scale($this->ocistmt, $colnum + 1);
        $oracletype = \oci_field_type_raw($this->ocistmt, $colnum + 1);

        return [
            "native_type" => $oracletype,
            "pdo_type"    => $type,
            "flags"       => '',
            "table"       => '',
            "name"        => $name,
            "len"         => $len,
            "precision"   => $prec,
        ];
    }

    /**
     * 在一个多行集语句句柄中推进到下一个行集。一些数据库服务支持返回一个以上行集（也被称为结果集）的存储过程，Oracle 不支持。
     *
     * @return boolean
     */
    public function nextRowset()
    {
        // TODO: 需要去确认Oracle是否支持
        return false;
    }

    public function rowCount()
    {
        set_error_handler(array($this->ocipdo, "errorHandler"));

        $rows = null;
        try {
            $rows = \oci_num_rows($this->ocistmt); // 出错时返回false
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        restore_error_handler();
        return $rows;
    }

    /**
     * 给语句设置一个属性。当前，没有通用的属性可以设置，只有驱动特定的属性：
     *
     * @param integer $attr
     * @param [type] $value
     * @return void
     */
    public function setAttribute($attr, $value)
    {
        // TODO: 是否需要实现？
    }

    // Declaration of ocipdo\PDOStatement::setFetchMode(int $mode, $p1 = NULL, $p2 = NULL) should be compatible with PDOStatement::setFetchMode($mode, $params = NULL)
    public function setFetchMode($mode, $params = null)
    {
        $this->fetchMode = $mode;
        return true;
    }

    private function loadDataCreateObject($data, $class, $args = [])
    {
        try {
            $clsObj = new $class(args);

            foreach ($data as $key => $value) {
                if ($name !== 'stdClass' && !array_key_exists(strtolower($key), get_object_vars($clsObj))) {
                    var_dump(get_object_vars($clsObj));
                    continue;
                }
                $key          = strtolower($key);
                $clsObj->$key = $value;
            }
        } catch (\Exception $e) {
            $clsObj = null;
        }

        return $clsObj;
    }

    private function insertParamMarks($sql)
    {
        $index = -1;
        $regex = '/(?<!\')\?(?!\')/';
        return preg_replace_callback($regex, function ($matches) use (&$index) {$index++;return ":oci_param_{$index}";}, $sql);
    }

    private function parseParam($val)
    {
        if (preg_match('/^\d+$/', $val)) {
            $val = ":oci_param_" . (intval($val) - 1);
        }
        return $val;
    }

    private function getRealSql($bind, $sql)
    {
        foreach ($bind as $key => $value) {
            $type = $this->bindType[$key] ?? \PDO::PARAM_STR;

            if (\PDO::PARAM_STR == $type) {
                $value = '\'' . addslashes($value) . '\'';
            } elseif (\PDO::PARAM_INT == $type && '' === $value) {
                $value = 0;
            }

            // 判断占位符
            $sql = is_numeric($key) ?
            substr_replace($sql, $value, strpos($sql, '?'), 1) :
            substr_replace($sql, $value, strpos($sql, ':' . $key), strlen(':' . $key));
        }

        return $sql;
    }

    private function paramBound($data)
    {
        if ($this->fetchMode != \PDO::FETCH_BOUND) {
            return;
        }

        foreach ($this->bind as $key => &$value) {
            if (is_numeric($key)) {
                $key--;
            } else {
                $key = strtoupper($key);
            }

            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
        }
    }

    private function fieldCase($result)
    {
        if (!$this->case || $this->case === \PDO::CASE_NATURAL) {
            return $result;
        }

        switch ($this->case) {
            case \PDO::CASE_LOWER:
                $case = CASE_LOWER;
                break;
            case \PDO::CASE_UPPER:
                $case = CASE_UPPER;
                break;
            default:
                throw new \PDOException('Unknown case attribute: ' . $this->case);
        }

        return array_map(
            function ($item) use ($case) {
                if (is_array($item)) {
                    $item = array_change_key_case($item, $case);
                }
                return $item;
            }, array_change_key_case($result, $case)
        );
    }

    // echo \PDO::PARAM_NULL . "\n";
    // echo \PDO::PARAM_INT . "\n";
    // echo \PDO::PARAM_STR . "\n";
    // echo \PDO::PARAM_LOB . "\n";
    // echo \PDO::PARAM_STMT . "\n";
    // echo \PDO::PARAM_BOOL . "\n";
    // echo \PDO::PARAM_INPUT_OUTPUT . "\n";
    private function getOciType($pdoType)
    {
        $ociType = SQLT_CHR;
        $pdoType = $pdoType & ~\PDO::PARAM_INPUT_OUTPUT;

        switch ($pdoType) {
            case \PDO::PARAM_NULL:
            case \PDO::PARAM_STR:
                $ociType = SQLT_CHR;
                break;
            case \PDO::PARAM_INT:
                $ociType = SQLT_INT;
                break;
            case \PDO::PARAM_BOOL:
                $ociType = SQLT_BOL;
                break;
            case \PDO::PARAM_LOB:
                $ociType = SQLT_CLOB;
                break;
            case \PDO::PARAM_LOB + PDO::PARAM_LOB:
                $ociType = SQLT_BLOB;
                break;
        }

        return $ociType;
    }

    private function isProcedureOutType($type)
    {
        return in_array($this->stmtType, ['BEGIN', 'CALL']) && \PDO::PARAM_INPUT_OUTPUT === ($type & \PDO::PARAM_INPUT_OUTPUT);
    }

    /**
     * 返回存储过程的数据
     *
     * @param string $fetchMode
     * @return void
     */
    private function fetchProcedure($fetchMode = '')
    {
        // TODO: 返回各种 fetchMode 下的数据结构
        return $this->proceOutdata;
    }

    public function __destruct()
    {
        $this->freeBind();
        $this->closeCursor();
    }

    public function freeBind()
    {
        foreach ($this->bindLob as $bind) {
            $ret = $bind['lob']->free(); // 释放资源 oci_new_descriptor
        }

        $this->bind         = [];
        $this->bindLob      = [];
        $this->bindType     = [];
        $this->proceOutdata = [];
    }
}
