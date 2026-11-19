<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Uuid;
use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function isConfigured(): bool
    {
        return (int) $this->database->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    /** @return array{id:int,uuid:string,email:string}|null */
    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }

        $statement = $this->database->pdo()->prepare('SELECT id, uuid, email FROM users WHERE id = ? LIMIT 1');
        $statement->execute([(int) $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @return array{id:int,uuid:string,email:string} */
    public function registerInitial(string $email, string $password): array
    {
        if ($this->isConfigured()) {
            throw new RuntimeException('The first account has already been created.');
        }
        $this->validateCredentials($email, $password);

        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('INSERT INTO users (uuid, email, password_hash) VALUES (?, ?, ?)');
        $statement->execute([Uuid::v4(), strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;

        return $this->user() ?? throw new RuntimeException('Unable to load the new account.');
    }

    public function login(string $email, string $password): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!is_array($user) || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    private function validateCredentials(string $email, string $password): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Use a password of at least 12 characters.');
        }
    }
}
