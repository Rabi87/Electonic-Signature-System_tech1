<?php
require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->connection = new PDO($dsn, DB_USER, DB_PASS);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    public function query($sql) {
        return $this->connection->query($sql);
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollBack() {
        return $this->connection->rollBack();
    }
}

/**
 * دالة مساعدة للحصول على اتصال بقاعدة البيانات
 */
function getDB() {
    return Database::getInstance()->getConnection();
}

/**
 * تنفيذ استعلام مع معلمات
 */
function executeQuery($sql, $params = []) {
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("خطأ في تنفيذ الاستعلام: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على سجل واحد
 */
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetch() : false;
}

/**
 * الحصول على جميع السجلات
 */
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll() : false;
}

/**
 * إدراج سجل جديد
 */
function insert($table, $data) {
    $columns = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));
    
    $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
    
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($data);
        return getDB()->lastInsertId();
    } catch (PDOException $e) {
        error_log("خطأ في الإدراج: " . $e->getMessage());
        return false;
    }
}

/**
 * تحديث سجل
 */
function update($table, $data, $where) {
    $set = [];
    foreach ($data as $key => $value) {
        $set[] = "$key = :$key";
    }
    
    $whereClause = [];
    $whereParams = [];
    foreach ($where as $key => $value) {
        $whereClause[] = "$key = :where_$key";
        $whereParams["where_$key"] = $value;
    }
    
    $sql = "UPDATE $table SET " . implode(', ', $set) . 
           " WHERE " . implode(' AND ', $whereClause);
    
    $allParams = array_merge($data, $whereParams);
    
    try {
        $stmt = getDB()->prepare($sql);
        return $stmt->execute($allParams);
    } catch (PDOException $e) {
        error_log("خطأ في التحديث: " . $e->getMessage());
        return false;
    }
}

/**
 * حذف سجل
 */
function delete($table, $where) {
    $whereClause = [];
    $params = [];
    foreach ($where as $key => $value) {
        $whereClause[] = "$key = :$key";
        $params[$key] = $value;
    }
    
    $sql = "DELETE FROM $table WHERE " . implode(' AND ', $whereClause);
    
    try {
        $stmt = getDB()->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("خطأ في الحذف: " . $e->getMessage());
        return false;
    }
}