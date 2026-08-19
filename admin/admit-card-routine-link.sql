-- Admit Card <-> Exam Routine linkage — run once.
-- Enables creating admit cards from an exam routine and restricting access
-- to the students actually enrolled (registered) in the routine's courses.

ALTER TABLE ac_admit_cards
    ADD COLUMN routine_id INT NULL DEFAULT NULL AFTER batch_id,
    ADD KEY idx_ac_admit_cards_routine (routine_id);

ALTER TABLE ac_admit_card_courses
    ADD COLUMN offer_subject_id INT NULL DEFAULT NULL AFTER admit_card_id,
    ADD KEY idx_ac_admit_card_courses_subject (offer_subject_id);
