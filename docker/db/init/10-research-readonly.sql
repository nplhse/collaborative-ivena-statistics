-- Read-only role for the export connection of data extract requests (ADR 045), like the hosting's `_ro` user:
-- it reads every table of the schema public and writes nothing. Runs as the database owner, on a fresh volume through
-- docker-entrypoint-initdb.d and on an existing one with `make db-readonly-role`; running it twice changes nothing.
-- Local development only: the password is the same placeholder as the one of the application user.
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_ro') THEN
        CREATE ROLE app_ro LOGIN PASSWORD 'password';
    END IF;
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO app_ro', current_database());
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT ON TABLES TO app_ro', current_user);
END
$$;

GRANT USAGE ON SCHEMA public TO app_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_ro;
