<?php
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/remember_me.php";

final class RememberFakeStatement extends PDOStatement
{
    private RememberFakePdo $database;
    private string $sql;
    private mixed $result = false;

    public function __construct(RememberFakePdo $database, string $sql)
    {
        $this->database = $database;
        $this->sql = preg_replace('/\s+/', ' ', trim($sql)) ?: "";
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        if (str_starts_with($this->sql, "INSERT INTO remember_tokens")) {
            $this->database->tokens[(string)$params[":selector"]] = [
                "user_id" => (int)$params[":user_id"],
                "token_hash" => (string)$params[":token_hash"],
                "expires_at" => (int)$params[":expires_at"],
            ];
        } elseif (str_starts_with($this->sql, "SELECT rt.user_id")) {
            $token = $this->database->tokens[(string)$params[":selector"]] ?? null;
            $user = is_array($token) ? ($this->database->users[$token["user_id"]] ?? null) : null;
            $this->result = is_array($token) && is_array($user)
                ? array_merge($token, $user)
                : false;
        } elseif (str_starts_with($this->sql, "DELETE FROM remember_tokens WHERE selector")) {
            unset($this->database->tokens[(string)$params[":selector"]]);
        } elseif (str_starts_with($this->sql, "DELETE FROM remember_tokens WHERE user_id")) {
            foreach ($this->database->tokens as $selector => $token) {
                if ((int)$token["user_id"] === (int)$params[":user_id"]) {
                    unset($this->database->tokens[$selector]);
                }
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result;
    }
}

final class RememberFakePdo extends PDO
{
    public array $tokens = [];
    public array $users = [];
    private bool $transaction = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RememberFakeStatement($this, $query);
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }
}

final class RememberMemorySessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ""; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}

function remember_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function remember_source(string $relativePath): string
{
    return file_get_contents(__DIR__ . "/../" . $relativePath) ?: "";
}

$_SERVER["HTTPS"] = "on";
session_set_save_handler(new RememberMemorySessionHandler(), true);
session_name("SERVITECH_REMEMBER_TEST");
session_start();

$pdo = new RememberFakePdo();
$pdo->users[7] = ["email" => "customer@example.test", "role" => "customer"];

// Case B: checked password login receives an opaque persistent token and can
// rebuild authentication after the ordinary PHP session has disappeared.
servitech_remember_issue($pdo, 7);
$rawToken = (string)($_COOKIE[servitech_remember_cookie_name()] ?? "");
$parts = servitech_remember_parse_cookie($rawToken);
remember_assert($parts !== null, "Issued remember cookie format is invalid.");
$stored = $pdo->tokens[$parts["selector"]] ?? null;
remember_assert(is_array($stored), "Remember token was not stored.");
remember_assert($stored["token_hash"] !== $parts["validator"], "Raw validator must never be stored.");
remember_assert(hash_equals($stored["token_hash"], hash("sha256", $parts["validator"])), "Stored validator hash is incorrect.");

$_SESSION = [];
$_COOKIE[servitech_remember_cookie_name()] = $rawToken;
remember_assert(servitech_remember_restore($pdo), "A valid remember token did not restore login.");
remember_assert((int)($_SESSION["user_id"] ?? 0) === 7, "Restored login has the wrong user.");
remember_assert(!empty($_SESSION["remember_me"]), "Restored login was not marked remembered.");

// Case C: logout revokes the server-side token and removes the browser token.
servitech_remember_revoke_current($pdo);
remember_assert($pdo->tokens === [], "Logout did not revoke the remembered token.");
remember_assert(empty($_COOKIE[servitech_remember_cookie_name()]), "Logout did not clear the remembered cookie.");

$formSource = remember_source("auth/log_in.php");
$loginSource = remember_source("auth/login.php");
$sessionSource = remember_source("config/session_check.php");
$googleSource = remember_source("auth/google_login.php");
$logoutSource = remember_source("auth/logout.php");
$migrationSource = remember_source("database/migrations/20260624_add_remember_tokens.sql");

// Cases A and D plus wiring/security regression checks.
remember_assert(str_contains($formSource, 'name="remember_me"'), "Login form does not submit remember_me.");
remember_assert(str_contains($loginSource, '$_POST["remember_me"]'), "Backend does not read remember_me.");
remember_assert(str_contains($loginSource, "servitech_apply_session_cookie_lifetime(false)"), "Unchecked/local login is not kept session-only.");
remember_assert(str_contains($sessionSource, "servitech_remember_restore"), "Session bootstrap does not restore remembered login.");
remember_assert(str_contains($loginSource, "login_remember_retry"), "Failed login does not preserve checkbox state.");
remember_assert(str_contains($loginSource, 'servitech_login_failure_redirect("fail", $rememberMe)'), "Invalid-login handling bypasses the normal failure path.");
remember_assert(substr_count($googleSource, '$_SESSION["remember_me"] = false;') >= 2, "Google login must remain session-only in both auth modes.");
remember_assert(str_contains($logoutSource, "servitech_remember_revoke_current"), "Logout does not revoke remember tokens.");
remember_assert(str_contains($migrationSource, "token_hash CHAR(64)"), "Remember-token migration is missing hashed token storage.");
remember_assert(str_contains($migrationSource, "ON DELETE CASCADE"), "Remember tokens are not tied to account deletion.");

session_destroy();
echo "Remember-me authentication checks passed.\n";
