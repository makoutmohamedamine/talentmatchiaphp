<?php

declare(strict_types=1);

final class Database
{
    private ?PDO $pdo = null;
    private ?string $driver = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $this->driver = strtolower((string) $this->config->get('DB_CONNECTION', 'mysql'));
        $name = $this->config->get('DB_NAME');
        $user = $this->config->get('DB_USER');
        $password = $this->config->get('DB_PASSWORD', '');
        $host = $this->config->get('DB_HOST', '127.0.0.1');
        $port = $this->config->get('DB_PORT', $this->driver === 'pgsql' ? '5432' : '3306');

        if (!$name || !$user) {
            throw new RuntimeException('Configuration base de donnees incomplete: DB_NAME et DB_USER sont requis.');
        }

        $dsn = match ($this->driver) {
            'pgsql', 'postgres', 'postgresql' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
            default => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        };
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);

        return $this->pdo;
    }

    public function driver(): string
    {
        if ($this->driver === null) {
            $this->pdo();
        }
        return $this->driver ?? 'mysql';
    }

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->run($sql, $params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function insert(string $table, array $data): array
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        if (in_array($this->driver(), ['pgsql', 'postgres', 'postgresql'], true)) {
            return $this->one($sql . ' RETURNING *', $data) ?? [];
        }

        $this->run($sql, $data);
        $id = (int) $this->pdo()->lastInsertId();
        return $this->one("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]) ?? [];
    }

    public function update(string $table, int $id, array $data): ?array
    {
        if ($data === []) {
            return $this->one("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $data['id'] = $id;

        $sql = sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $sets));

        if (in_array($this->driver(), ['pgsql', 'postgres', 'postgresql'], true)) {
            return $this->one($sql . ' RETURNING *', $data);
        }

        $this->run($sql, $data);
        return $this->one("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
    }
}
