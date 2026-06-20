-- Migration: Add profile picture support for user accounts
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) DEFAULT NULL;
