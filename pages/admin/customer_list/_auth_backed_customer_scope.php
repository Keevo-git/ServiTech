<?php

/**
 * Return the canonical SQL scope for customers that are backed by a live,
 * confirmed Supabase Auth account.
 *
 * auth.users is intentionally joined by auth_user_id only. Email is mutable
 * and provider-specific metadata must not affect password or Google accounts.
 */
function admin_auth_backed_customer_scope_sql(
    string $usersAlias = "users",
    string $authAlias = "auth_account"
): string {
    foreach ([$usersAlias, $authAlias] as $alias) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new InvalidArgumentException("Invalid customer query alias.");
        }
    }

    return <<<SQL
FROM public.users {$usersAlias}
INNER JOIN auth.users {$authAlias}
  ON {$authAlias}.id = {$usersAlias}.auth_user_id
WHERE {$usersAlias}.auth_user_id IS NOT NULL
  AND {$authAlias}.deleted_at IS NULL
  AND {$authAlias}.email_confirmed_at IS NOT NULL
  AND {$usersAlias}.email_verified_at IS NOT NULL
  AND LOWER(
    TRIM(
      COALESCE(
        NULLIF(to_jsonb({$usersAlias})->>'role', ''),
        'customer'
      )
    )
  ) = 'customer'
SQL;
}

/**
 * Auth schema reads must use the server-side database role. Admin auth is
 * checked before callers load this helper; no Auth fields are returned.
 */
function admin_auth_backed_customer_connection(): PDO
{
    if (!function_exists("servitech_db_connect_privileged")) {
        throw new RuntimeException("The privileged database connection is unavailable.");
    }

    return servitech_db_connect_privileged();
}
