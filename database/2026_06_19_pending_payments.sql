-- ============================================================
-- VIKOBA - Add payment approval workflow
-- ============================================================

-- Add status column to repayments table for approval workflow
-- 'approved' = automatically applied (default for backwards compat)
-- 'pending'  = member-submitted, awaiting admin approval
-- 'rejected' = denied by admin
ALTER TABLE repayments 
ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' 
AFTER reference;

-- Add reviewed_by and reviewed_at for tracking who approved/rejected
ALTER TABLE repayments
ADD COLUMN reviewed_by INT NULL AFTER notes,
ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
ADD COLUMN rejection_reason TEXT NULL AFTER reviewed_at;

-- Update existing repayments to be approved
UPDATE repayments SET status = 'approved' WHERE status IS NULL OR status = '';