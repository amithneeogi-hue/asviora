-- Superseded.
--
-- This build's migration is config/upgrade-v5.sql. It is mapped to both
-- step 4 and step 5 in includes/migrate.php, so it runs whether the
-- database was left on schema 3 or already marked as 4 by an earlier
-- build. This file is kept only so nothing that still points at the old
-- name errors out; it does nothing.
SELECT 1;
