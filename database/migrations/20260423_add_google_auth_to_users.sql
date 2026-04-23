ALTER TABLE users
ADD COLUMN IF NOT EXISTS google_id VARCHAR(255);

ALTER TABLE users
ALTER COLUMN password_hash DROP NOT NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'users'
          AND column_name = 'password'
    ) THEN
        EXECUTE 'ALTER TABLE users ALTER COLUMN password DROP NOT NULL';
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS users_google_id_unique
ON users (google_id)
WHERE google_id IS NOT NULL;
