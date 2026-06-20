-- Add temporary lockout column to users to support failed login lockouts
ALTER TABLE users
  ADD COLUMN locked_until DATETIME NULL AFTER status;
