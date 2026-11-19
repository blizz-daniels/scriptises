<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $connection = null;

    /** @param array{host:string, port:int, name:string, user:string, password:string, charset:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
            $this->config['charset'],
        );

        try {
            $this->connection = new PDO($dsn, $this->config['user'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed. Check config/config.php and your database service.', 0, $exception);
        }

        return $this->connection;
    }
}
