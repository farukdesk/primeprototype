-- Adds an allowlist marker to admit-card student tokens.
--
-- Background: ac_student_tokens rows are created in two situations:
--   1. Pre-seeded by the Bulk CSV Import so that only the imported (matched)
--      students see that card on the student portal, and
--   2. Lazily, whenever a student's admit card PDF is generated (QR verify token).
--
-- The portal used "card has any tokens" to detect case 1, but case 2 also
-- creates tokens. As soon as the first student (or an admin) downloaded a
-- manually created card, the card disappeared for every other eligible
-- student. The is_allowlist flag makes the two cases distinguishable:
-- only tokens with is_allowlist = 1 restrict portal visibility.
--
-- Note: existing tokens default to 0, so admit cards bulk-imported BEFORE
-- this migration are no longer restricted to their imported students and
-- become visible to every student of the card's dept + program. Re-import
-- affected cards if per-section restriction is required.

ALTER TABLE ac_student_tokens
    ADD COLUMN is_allowlist TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX idx_ac_student_tokens_allowlist (admit_card_id, is_allowlist);
