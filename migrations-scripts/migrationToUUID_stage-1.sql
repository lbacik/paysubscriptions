
-- Migration script from paysub_v1 to paysub_v2

-- Step 1: Create UUIDs for existing users
ALTER TABLE paysub_v1.user ADD COLUMN uuid BINARY(16) DEFAULT NULL;
ALTER TABLE paysub_v1.reset_password_request ADD COLUMN uuid BINARY(16) DEFAULT NULL;
ALTER TABLE paysub_v1.subscription ADD COLUMN uuid BINARY(16) DEFAULT NULL;
