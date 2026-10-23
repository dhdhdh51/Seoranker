-- OPTIONAL: database-level immutability for audit_logs.
-- The application never updates/deletes audit rows and the hash chain detects tampering,
-- but these triggers also block UPDATE/DELETE at the database level.
-- Requires the TRIGGER privilege (and, with binary logging on, log_bin_trust_function_creators=1),
-- which many shared hosts do not grant — that is why this file is not part of schema.sql.
-- NOTE: with these triggers installed, bin/backup.php --restore cannot rewrite audit_logs;
-- drop the triggers before a restore and re-create them afterwards.

DROP TRIGGER IF EXISTS audit_logs_no_update;
DROP TRIGGER IF EXISTS audit_logs_no_delete;

CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
