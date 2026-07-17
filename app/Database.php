<?php
declare(strict_types=1);

namespace App;

/**
 * Database abstraction layer for the SEO system
 * Handles database operations for the SEO strategies
 */
class Database
{
    private static ?Database $instance = null;
    private \PDO $connection;

    private function __construct()
    {
        // The connection details come from environment variables
        // Prefer TCP default to avoid implicit unix socket behavior (common source of failures in containers/WSL).
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
        // Evita PDO via socket Unix quando MySQL escuta só em TCP (erro 2002 "No such file or directory").
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
        $dbname = $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? getenv('DB_DATABASE') ?? getenv('DB_NAME') ?: 'meli';
        $username = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? getenv('DB_USERNAME') ?? getenv('DB_USER');
        $password = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? getenv('DB_PASSWORD') ?? getenv('DB_PASS') ?: '';

        if (!is_string($username) || trim($username) === '') {
            throw new \InvalidArgumentException(
                'DB_USERNAME/DB_USER não configurado. Defina um usuário de aplicação no ambiente.'
            );
        }

        $username = trim($username);

        // Validate required environment variables
        if (empty($_ENV['DB_HOST']) && empty(getenv('DB_HOST'))) {
            log_warning('DB_HOST não definido, usando default: 127.0.0.1');
        }
        if (empty($_ENV['DB_DATABASE']) && empty(getenv('DB_DATABASE'))) {
            log_warning('DB_DATABASE não definido, usando default: meli');
        }
        if (empty($_ENV['DB_USERNAME']) && empty($_ENV['DB_USER']) && empty(getenv('DB_USERNAME')) && empty(getenv('DB_USER'))) {
            log_warning('DB_USERNAME/DB_USER não definido no ambiente');
        }

        try {
            // Connect to MySQL/MariaDB
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $this->connection = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            $errorMessage = "Database connection failed: " . $e->getMessage();
            $debugInfo = " | Host: {$host}, Port: {$port}, DB: {$dbname}";

            // Add helpful debugging info (never log credentials)
            if (empty($password)) {
                $debugInfo .= " | Hint: Check if DB_PASSWORD is set in your .env file";
            }

            error_log($errorMessage . $debugInfo);
            throw new \Exception("Database connection failed. Check server logs for details.");
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): \PDO
    {
        // When running under PHPUnit in a no-DB integration environment, throw SkippedTestError
        // so that every test calling getInstance() is marked "skipped" rather than "error".
        // PHPUNIT_DB_AVAILABLE is only defined in tests/bootstrap.php (integration path), never in
        // production — so this guard is completely inert outside of test runs.
        if (
            defined('PHPUNIT_DB_AVAILABLE')
            && !PHPUNIT_DB_AVAILABLE
            && class_exists('\PHPUnit\Framework\SkippedTestError')
        ) {
            throw new \PHPUnit\Framework\SkippedTestError(
                'Database unavailable: ' . ($GLOBALS['phpunit_db_error'] ?? 'connection refused')
            );
        }

        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->connection;
    }

    /**
     * Execute a query with parameters
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            // Determine if it's a SELECT statement
            $isSelect = stripos(trim($sql), 'SELECT') === 0 ||
                stripos(trim($sql), 'PRAGMA') === 0 ||
                stripos(trim($sql), 'WITH') === 0;

            if ($isSelect) {
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                // For INSERT/UPDATE/DELETE, return affected rows
                return [['affected_rows' => $stmt->rowCount()]];
            }
        } catch (\PDOException $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): void
    {
        $this->connection->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): void
    {
        $this->connection->rollBack();
    }

    /**
     * Execute a raw SQL statement
     */
    public function exec(string $sql): int
    {
        try {
            return $this->connection->exec($sql);
        } catch (\PDOException $e) {
            throw new \Exception("Exec failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a prepared statement
     */
    public function prepare(string $sql): \PDOStatement
    {
        try {
            return $this->connection->prepare($sql);
        } catch (\PDOException $e) {
            throw new \Exception("Prepare failed: " . $e->getMessage());
        }
    }

    /**
     * Get the PDO connection object
     */
    public function getConnection(): \PDO
    {
        return $this->connection;
    }

    /**
     * Validates a SQL identifier (table/column name) to prevent SQL injection
     */
    private function sanitizeIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
        }
        return "`{$name}`";
    }

    /**
     * Insert a record into a table
     */
    public function insert(string $table, array $data): int
    {
        $safeTable = $this->sanitizeIdentifier($table);
        $safeColumns = array_map([$this, 'sanitizeIdentifier'], array_keys($data));
        $columns = implode(', ', $safeColumns);
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$safeTable} ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return (int)$this->connection->lastInsertId();
        } catch (\PDOException $e) {
            throw new \Exception("Insert failed: " . $e->getMessage());
        }
    }

    /**
     * Update records in a table
     */
    public function update(string $table, array $data, array $where): int
    {
        $safeTable = $this->sanitizeIdentifier($table);
        $safeSetKeys = array_map(fn(string $k): string => $this->sanitizeIdentifier($k) . ' = ?', array_keys($data));
        $setClause = implode(', ', $safeSetKeys);
        $safeWhereKeys = array_map(fn(string $k): string => $this->sanitizeIdentifier($k) . ' = ?', array_keys($where));
        $whereClause = implode(' AND ', $safeWhereKeys);

        $sql = "UPDATE {$safeTable} SET {$setClause} WHERE {$whereClause}";

        $values = array_values($data);
        $values = array_merge($values, array_values($where));

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($values);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new \Exception("Update failed: " . $e->getMessage());
        }
    }

    /**
     * Delete records from a table
     */
    public function delete(string $table, array $where): int
    {
        $safeTable = $this->sanitizeIdentifier($table);
        $safeWhereKeys = array_map(fn(string $k): string => $this->sanitizeIdentifier($k) . ' = ?', array_keys($where));
        $whereClause = implode(' AND ', $safeWhereKeys);

        $sql = "DELETE FROM {$safeTable} WHERE {$whereClause}";

        $values = array_values($where);

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($values);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new \Exception("Delete failed: " . $e->getMessage());
        }
    }

    /**
     * Select records from a table
     */
    public function select(string $table, array $conditions = [], ?array $fields = ['*'], ?int $limit = null, ?int $offset = null): array
    {
        $safeTable = $this->sanitizeIdentifier($table);
        $safeFields = array_map(function (string $f): string {
            return $f === '*' ? '*' : $this->sanitizeIdentifier($f);
        }, $fields ?? ['*']);
        $fieldList = implode(', ', $safeFields);
        $sql = "SELECT {$fieldList} FROM {$safeTable}";

        $params = [];
        if (!empty($conditions)) {
            $safeCondKeys = array_map(fn(string $k): string => $this->sanitizeIdentifier($k) . ' = ?', array_keys($conditions));
            $whereClause = implode(' AND ', $safeCondKeys);
            $sql .= " WHERE {$whereClause}";
            $params = array_values($conditions);
        }

        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \Exception("Select failed: " . $e->getMessage());
        }
    }

    /**
     * Check if a record exists in a table
     */
    public function exists(string $table, array $conditions): bool
    {
        $safeTable = $this->sanitizeIdentifier($table);
        $safeCondKeys = array_map(fn(string $k): string => $this->sanitizeIdentifier($k) . ' = ?', array_keys($conditions));
        $whereClause = implode(' AND ', $safeCondKeys);
        $sql = "SELECT 1 FROM {$safeTable} WHERE {$whereClause} LIMIT 1";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(array_values($conditions));
            return $stmt->fetch() !== false;
        } catch (\PDOException $e) {
            throw new \Exception("Exists check failed: " . $e->getMessage());
        }
    }

    /**
     * Count records in a table
     */
    public function count(string $table, array $conditions = []): int
    {
        $safeTable = $this->sanitizeIdentifier($table);
        $sql = "SELECT COUNT(*) as count FROM {$safeTable}";
        $params = [];

        if (!empty($conditions)) {
            $safeCondKeys = array_map(fn(string $k): string => $this->sanitizeIdentifier($k) . ' = ?', array_keys($conditions));
            $whereClause = implode(' AND ', $safeCondKeys);
            $sql .= " WHERE {$whereClause}";
            $params = array_values($conditions);
        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (\PDOException $e) {
            throw new \Exception("Count failed: " . $e->getMessage());
        }
    }
}
