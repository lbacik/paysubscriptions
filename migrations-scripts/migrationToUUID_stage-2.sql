
-- Migration script from paysub_v1 to paysub_v2

-- Step 2: Migrate users to paysub
INSERT INTO paysub.user (id, email, roles, password, is_verified, created_at, updated_at)
SELECT uuid, email, roles, password, is_verified, NOW(), NOW()
FROM paysub_v1.user;

-- Step 3: Migrate reset_password_request to paysub
INSERT INTO paysub.reset_password_request (id, user_id, selector, hashed_token, requested_at, expires_at)
SELECT r.uuid, u.uuid, r.selector, r.hashed_token, r.requested_at, r.expires_at
FROM paysub_v1.reset_password_request r
JOIN paysub_v1.user u ON r.user_id = u.id;

-- Step 4: Migrate subscription to paysub
INSERT INTO paysub.subscription (id, owner_id, name, first_payment, monthly, yearly, created_at, updated_at)
SELECT s.uuid, u.uuid, s.name, s.first_payment, s.monthly, s.yearly, NOW(), NOW()
FROM paysub_v1.subscription s
JOIN paysub_v1.user u ON s.owner_id = u.id;
