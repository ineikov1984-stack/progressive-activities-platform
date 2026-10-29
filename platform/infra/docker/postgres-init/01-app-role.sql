DO
$$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'progressive_app') THEN
        CREATE ROLE progressive_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$$;

-- The application only reads and writes rows (under RLS). It never creates objects, triggers
-- or truncates: migrations run as the owner role (docs/process/DECISIONS.md).
ALTER ROLE progressive_app PASSWORD 'progressive-app-local-only';
GRANT CONNECT ON DATABASE progressive TO progressive_app;
GRANT USAGE ON SCHEMA public TO progressive_app;
REVOKE CREATE ON SCHEMA public FROM progressive_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO progressive_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO progressive_app;
ALTER DEFAULT PRIVILEGES FOR ROLE progressive IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO progressive_app;
ALTER DEFAULT PRIVILEGES FOR ROLE progressive IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO progressive_app;
