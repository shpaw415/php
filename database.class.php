<?php


class Database
{
    private $pdo;
    private $stmt;
    public array|false $result;

    public $returnMsg = '';

    private $host = DB_HOST ?? 'localhost';
    private $user = DB_USER?? 'root';
    private $pass = DB_PASS?? '';
    private $name = DB_NAME?? '';

    public function __construct($host = false, $dbname = false, $username = false, $password = false)
    {
        if($host) $this->host = $host;
        if($dbname) $this->name = $dbname;
        if($username) $this->user = $username;
        if($password) $this->pass = $password;
        try {
            $this->pdo = new PDO('mysql:host=' . $this->host . ';dbname=' . $this->name . ';charset=utf8', $this->user, $this->pass);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo 'Connection failed: ' . $e->getMessage();
        }
    }
    /**
     * Query the database with the given query and parameters
     * @param string $query
     * @param array $array
     */
    public function query_me($query, $array = [])
    {
        $this->stmt = $this->pdo->prepare($query);
        $this->stmt->execute($array);
        $this->result = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($this->result) {
            return $this->result;
        } else {
            return false;
        }
    }
    public function insert($table, $array)
    {
        $keys = array_keys($array);
        $val = array_values($array);
        $values = null;
        $x = 1;
        foreach ($array as $item) {
            $values .= '?';
            if ($x < count($array)) {
                $values .= ', ';
            }
            $x++;
        }
        $sql = "INSERT INTO {$table} (" . implode(', ', $keys) . ") VALUES ({$values})";
        if (!$this->query_me($sql, $val)) {
            return false;
        }
        return true;
    }
    /**
     * Add an array to a json value in a table/column
     */
    public function addJson(string $InTable, string $InColumn, string $whenColumn, string|int $valueIs, array $newInfoarray)
    {
        // $InTable = table to change
        // $InColumn = column to change
        // $whenColumn = column to check
        // $valueIs = value to check
        // $newInfo = new info to add
        try {
            $query = "SELECT $InColumn FROM $InTable WHERE $whenColumn = ?";
            $for = [$valueIs];

            if (!$this->query_me($query, $for))
                return false;
            $oldInfo = $this->result[0][$InColumn];
            if ($oldInfo != null) {
                $oldInfo = json_decode($oldInfo, true);
                $oldInfo[] = $newInfoarray;
            } else {
                $oldInfo = [$newInfoarray];
            }
            $oldInfo = json_encode($oldInfo);
            $query = "UPDATE $InTable SET $InColumn = ? WHERE $whenColumn = ?";
            $for = [$oldInfo, $valueIs];
            $this->query_me($query, $for);
            return false;
        }
        catch (Exception $e) {
            return $e->getMessage();
        }
    }
    public function get(array $column_list, string $from, string $where, string $value_is) {
        if(!is_array($column_list)) $column_list = array($column_list);
        $query_columns = '';
        $x = 1;
        foreach ($column_list as $column) {
            if($x < count($column_list)) $query_columns .= $column . ', ';
            else $query_columns .= $column;
            $x++;
        }
        $this->query_me("SELECT {$query_columns} FROM {$from} WHERE {$where} = ?", [$value_is]);
        return $this->result;
    }
    public function update(string $column, string $table, string $where, string $value_is, string|array $newValue) {
        if(!is_string($newValue)) $newValue = json_encode($newValue);
        $this->query_me("UPDATE {$table} SET {$column} =? WHERE {$where} =?", [$newValue, $value_is]);
    }
    public function updateBatch(String $table,String $where,mixed $value_is, Array $newValues) {
        $setValues = '';
        $for = [];
        foreach ($newValues as $key => $value) {
            $setValues .= "$key =?,";
            $for[] = $value;
        }
        $setValues = substr($setValues, 0, -1);
        
        $query = "UPDATE {$table} SET {$setValues}  WHERE {$where} =?";
        $for[] = $value_is;
        $this->query_me($query, $for);
    }
    public function delete($table, $where, $value_is) {
        $this->query_me("DELETE FROM {$table} WHERE {$where} =?", [$value_is]);
    }
}
