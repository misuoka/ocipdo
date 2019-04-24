<?php
/*
 * @Description:
 * @Author: Misuoka
 * @Github: https://github.com/misuoka
 * @Licensed: MIT
 * @Version: 1.0.0
 * @Date: 2019-04-22 14:52:31
 * @LastEditTime: 2019-04-24 17:19:35
 */
namespace ocipdo;

require_once dirname(__FILE__) . "/PDOStatement.php";

use ocipdo\PDOStatement as OCIStatement;

class PDO extends \PDO
{
    private $connection;
    private $lastError;
    private $charset    = 'AL32UTF8';
    private $autocommit = true;
    private $persistent = false;
    private $case       = \PDO::CASE_NATURAL;
    private $timeout;
    private $host;
    private $tns;

    public function __construct(string $dsn, string $username, string $password, array $options = [])
    {
        if (!function_exists('oci_new_connect')) {
            throw new \PDOException("No support for Oracle, please install the OCI driver");
        }

        $this->parseTns($dsn);
        $this->parseCharset($dsn);
        $this->parseOption($options);

        try {

            if ($this->persistent === true) {
                $this->connection = \oci_pconnect($username, $password, $this->tns, $this->charset);
            } else {
                $this->connection = \oci_new_connect($username, $password, $this->tns, $this->charset);
            }

            if (!$this->connection) {
                $error = $this->getError(false);
                throw new \Exception($error['code'] . ': ' . $error['message']);
            } elseif ($this->timeout != null) {
                \oci_set_call_timeout($this->connection, $this->timeout);
            }

        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        return $this;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function beginTransaction(): boolean
    {
        $this->setAttribute(\PDO::ATTR_AUTOCOMMIT, false);
    }

    public function commit(): boolean
    {
        $ret = \oci_commit($this->connection);

        if (!$ret) {
            $this->getError();
        }

        return $ret;
    }

    public function errorCode()
    {
        if (!$this->lastError) {
            return null;
        }

        return intval($this->lastError["code"]);
    }

    public function errorInfo()
    {
        if (!$this->lastError) {
            return null;
        }

        return [
            $this->lastError["code"],
            $this->lastError["code"],
            $this->lastError["message"],
        ];
    }

    public function exec($statement)
    {
        $rows = false;

        try {
            $stmt = $this->query($statement);
            $rows = $stmt->rowCount();
            $stmt->closeCursor();
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        return $rows;
    }

    public function getAttribute($attribute)
    {
        switch ($attribute) {
            case \PDO::ATTR_AUTOCOMMIT:
                return $this->autocommit;
            case \PDO::ATTR_CASE:
                return $this->case;
            case \PDO::ATTR_CLIENT_VERSION:
                return \oci_client_version();
            case \PDO::ATTR_CONNECTION_STATUS:
                return $this->connection ? $this->host . ' via TCP/IP' : false; // 自
            case \PDO::ATTR_DRIVER_NAME:
                return 'oci';
            case \PDO::ATTR_ERRMODE:
                return '';
            case \PDO::ATTR_ORACLE_NULLS:
                return 'NULL';
            case \PDO::ATTR_PERSISTENT:
                return $this->persistent;
            case \PDO::ATTR_PREFETCH:
                return '';
            case \PDO::ATTR_SERVER_INFO:
                return $this->host;
            case \PDO::ATTR_SERVER_VERSION:
                return \oci_server_version($this->connection);
            case \PDO::ATTR_TIMEOUT:
                return $this->timeout;
        }
        return null;
    }

    public static function getAvailableDrivers(): array
    {
        $drivers = \PDO::getAvailableDrivers();

        if (!in_array("oci", $drivers)) {
            array_push($drivers, "oci");
        }

        return $drivers;
    }

    public function inTransaction(): boolean
    {
        return !$this->autocommit;
    }

    public function lastInsertId($sequence = null): string
    {
        if (!$sequence) {
            throw new \PDOException("SQLSTATE[IM001]: Driver does not support this function: driver does not support getting attributes in system_requirements");
        }
        $id = 0;

        try {
            $stmt = $this->query("SELECT {$sequence}.CURRVAL FROM DUAL");
            $data = $stmt->fetch(\PDO::FETCH_ASSOC);
            $id   = intval($data["CURRVAL"]);
            $stmt->closeCursor();
        } catch (\PDOException $e) {
            $id = -1;
        }

        return $id;
    }

    // public function prepare(string $statement, array $options = []): PDOStatement
    public function prepare($sql, $options = null)
    {
        $stmt = null;

        try {
            $stmt = new OCIStatement($this, $sql);

            if (is_array($options) && !empty($options)) {
                foreach ($options as $key => $val) {
                    $stmt->setAttribute($key, $val);
                }
            }
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        return $stmt;
    }

    public function query(string $sql, int $mode = null, $p1 = null, $p2 = null): PDOStatement
    {
        $stmt = null;

        try {
            $stmt = new OCIStatement($this, $sql);
            if (!$stmt->execute()) {
                $this->getError($stmt);
            }
        } catch (\Exception $e) {
            throw new \PDOException($e->getMessage());
        }

        return $stmt;
    }

    public function quote($string, $paramType = \PDO::PARAM_STR): string
    {
        $string = preg_replace('/\'/', "''", $string);
        $string = "'{$string}'";

        return $string;
    }

    public function rollBack(): boolean
    {
        $ret = \oci_rollback($this->connection);

        if (!$ret) {
            $this->getError();
        }

        return $ret;
    }

    public function setAttribute($attribute, $value): boolean
    {
        switch ($attribute) {
            case \PDO::ATTR_AUTOCOMMIT:
                $this->autocommit = (is_bool($value) && $value) || in_array(strtolower($value), array("on", "true"));
            case \PDO::ATTR_CASE:
                $this->case = $value;
            case \PDO::ATTR_ERRMODE:
                $this->errmode = $value;
        }
        return true;
    }

    public function errorHandler($errno, $errstr, $errfile, $errline)
    {
        preg_match('/(ORA-)(\d+)/', $errstr, $error);
        if ($error) {
            $this->lastError = intval($error[2]);
        } else {
            $this->getError($this->connection);
        }
    }

    public function isAutoCommit()
    {
        return $this->autocommit;
    }

    public function getError($resource = null)
    {
        $resource = $resource ?: $this->connection; // $stmt or $connection

        if (is_resource($resource)) {
            $error = \oci_error($resource);
        } else {
            $error = \oci_error();
        }

        if (!$error) {
            return null;
        }

        $this->lastError = $error;
        return $error;
    }

    private function parseTns($dsn)
    {
        $tns = preg_replace('/^oci:/', '', trim($dsn));
        $arr = preg_split('/;/', $tns);
        $tns = str_replace(array('dbname=//', 'dbname='), '', $arr[0]);

        if (!preg_match('/DESCRIPTION/', strtoupper($tns)) && false !== strpos($tns, ':')) {
            // 不是 tnsnames.ora 字符串，也不是 则认为是 oci:dbname=//localhost:1521/mydb
            list($host, $temp) = preg_split('/:/', $tns);
            list($port, $sid)  = preg_split('/\//', $temp);
            $tns               = sprintf('(DESCRIPTION=(ADDRESS_LIST = (ADDRESS = (PROTOCOL = TCP)(HOST = %s)(PORT = %u)))(CONNECT_DATA=(SID=%s)))', $host, $port, $sid);
        } elseif (preg_match('/DESCRIPTION/', strtoupper($tns))) {
            if (preg_match('/HOST\s*=\s*(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $tns, $matches)) {
                $host = $matches[1];
            } elseif (preg_match('/HOST\s*=\s*([a-zA-Z]+[.]{1}[a-zA-Z\d\-]+[.]{1}[a-zA-Z\d]*)\s*/i', $tns, $matches)) {
                $host = $matches[1];
            }
        }

        $this->host = $host ?? '';
        $this->tns  = $tns;
    }

    private $charsets = [
        'UTF8'      => 'AL32UTF8',
        'UTF-8'     => 'AL32UTF8',
        'AL32UTF8'  => 'AL32UTF8',
        'ZHS16GBK'  => 'ZHS16GBK',
        'AL16UTF16' => 'AL16UTF16',
    ];

    private function parseCharset($dsn)
    {
        $arr = preg_split('/;/', $dsn);

        if (count($arr) > 1) {
            $charset = trim(preg_replace('/^charset\s=\s/i', '', strtolower($charset[1]))) ?: null;
        } else {
            $charset = 'AL32UTF8';
        } /* else {
        $langs = array_filter(array(getenv("NLS_LANG")), "strlen");
        $charset = !empty($langs) ?array_shift($langs) : 'AL32UTF8';
        }; */
        //  oci_new_connect(): Invalid character set name: SIMPLIFIED CHINESE_CHINA.ZHS16GBK
        $this->charset = $charsets[$charset] ?? $this->charset;
    }

    private function parseOption($options = [])
    {
        $this->persistent = array_key_exists(\PDO::ATTR_PERSISTENT, $options) && $options[\PDO::ATTR_PERSISTENT] === true;
        $this->timeout    = array_key_exists(\PDO::ATTR_TIMEOUT, $options) ? $options[\PDO::ATTR_TIMEOUT] * 1000 : null;
        $this->case       = array_key_exists(\PDO::ATTR_CASE, $options) ? $options[\PDO::ATTR_CASE] : \PDO::CASE_NATURAL;
    }

    public function __destruct()
    {
        if ($this->connection) {
            \oci_close($this->connection);
        }

        $this->connection = null;
        $this->lastError  = null;
    }
}
