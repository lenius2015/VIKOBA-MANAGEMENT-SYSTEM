-- Migration: add structured status and reviewer columns to repayments
-- Run with: mysql -u <user> -p <database> < 2026_06_19_add_repayment_status.sql

-- NOTE: Uses MySQL 8+ syntax `IF NOT EXISTS` for safety. If your server is older,
-- run the ALTER statements manually after checking INFORMATION_SCHEMA.COLUMNS.

ALTER TABLE repayments
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL;

-- Index to make status queries fast
CREATE INDEX IF NOT EXISTS `idx_repayments_status` ON repayments (`status`);

-- Optional: backfill existing records that already have approvals/notes
-- If your previous workflow annotated notes with 'Approved by' or 'REJECTED by',
-- you can run the following updates to initialize status values where appropriate.

-- Mark as approved if notes indicate approval
UPDATE repayments
SET status = 'approved'
WHERE status = 'pending'
  AND (notes LIKE '%Approved by %' OR notes LIKE '%Approved on %');

-- Mark as rejected if notes indicate rejection
UPDATE repayments
SET status = 'rejected'
WHERE status = 'pending'
  AND (notes LIKE '%REJECTED by %' OR notes LIKE '%REJECTED%');

-- Leave other records as 'pending' (default)

-- Optional: add foreign key for reviewed_by to users (uncomment if desired and if users.id exists)
-- ALTER TABLE repayments
--   ADD CONSTRAINT fk_repayments_reviewed_by_users FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

COMMIT;
