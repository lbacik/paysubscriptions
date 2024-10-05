
-- Migration script from paysub_v1 to paysub_v2

-- Disable binary logging for this session
SET SESSION sql_log_bin = 0;

-- Step 1: Create UUIDs for existing users
ALTER TABLE paysub_v1.user ADD COLUMN uuid BINARY(16) DEFAULT (UUID_TO_BIN(UUID()));

-- Step 2: Migrate users to paysub
INSERT INTO paysub.user (id, email, roles, password, is_verified, created_at, updated_at)
SELECT uuid, email, roles, password, is_verified, NOW(), NOW()
FROM paysub_v1.user;

-- Step 3: Migrate reset_password_request to paysub
INSERT INTO paysub.reset_password_request (id, user_id, selector, hashed_token, requested_at, expires_at)
SELECT UUID_TO_BIN(UUID()), u.uuid, r.selector, r.hashed_token, r.requested_at, r.expires_at
FROM paysub_v1.reset_password_request r
JOIN paysub_v1.user u ON r.user_id = u.id;

-- Step 4: Migrate subscription to paysub
INSERT INTO paysub.subscription (id, owner_id, name, first_payment, monthly, yearly, created_at, updated_at)
SELECT UUID_TO_BIN(UUID()), u.uuid, s.name, s.first_payment, s.monthly, s.yearly, NOW(), NOW()
FROM paysub_v1.subscription s
JOIN paysub_v1.user u ON s.owner_id = u.id;

-- Step 5: Populate limits table
INSERT INTO paysub.limits (id, user_id, subscriptions, created_at, updated_at)
SELECT UUID_TO_BIN(UUID()), u.uuid, COUNT(s.id), NOW(), NOW()
FROM paysub_v1.user u
LEFT JOIN paysub_v1.subscription s ON u.id = s.owner_id
GROUP BY u.id;
