-- Migration: extend loans.status enum to new workflow values
ALTER TABLE loans MODIFY COLUMN status ENUM('draft','submitted','under_review','review_requested','resubmitted','approved','rejected','disbursed','completed','defaulted') DEFAULT 'submitted';

-- If your DB previously had values like 'pending','active' or 'needs_changes', convert them:
UPDATE loans SET status = 'submitted' WHERE status = 'pending';
UPDATE loans SET status = 'disbursed' WHERE status = 'active';
UPDATE loans SET status = 'review_requested' WHERE status = 'needs_changes';

-- Note: Run this migration against your DB to apply schema and status normalization.
