-- Code of Conduct CMS Module
-- Run once to create tables and seed default data.

CREATE TABLE IF NOT EXISTS `cms_coc_sections` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_key` VARCHAR(50)  NOT NULL UNIQUE COMMENT 'student | faculty | staff',
  `title`       VARCHAR(255) NOT NULL,
  `subtitle`    VARCHAR(255) DEFAULT NULL,
  `intro_text`  TEXT         DEFAULT NULL,
  `icon`        VARCHAR(100) NOT NULL DEFAULT 'fas fa-book',
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cms_coc_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id`  INT UNSIGNED NOT NULL,
  `item_text`   TEXT         NOT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_coc_items_section` (`section_id`),
  CONSTRAINT `fk_coc_items_section` FOREIGN KEY (`section_id`) REFERENCES `cms_coc_sections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed sections ─────────────────────────────────────────────────────────────

INSERT INTO `cms_coc_sections` (`section_key`, `title`, `subtitle`, `intro_text`, `icon`, `sort_order`, `is_active`) VALUES
('student', 'Student Code of Conduct', 'Rules & Responsibilities for Students',
 'The Prime University Student Code of Conduct has been formulated with the goal of upholding standard mission of smooth disciplinary activities. It is the responsibility of the Prime University to prepare the "Students Code of Conduct" and make that available to all members to the University community so that in case of violations and subsequent convening of the "Disciplinary Committee" measures and procedures may be clear to all parties concerned. The violations of code of conduct shall invoke disciplinary process as prescribed in these rules. Sanction will be commensurate with the seriousness of the offence and may include suspension or extreme, expulsion from the university. Repeated offences justify increasingly severe sanction.',
 'fas fa-user-graduate', 1, 1),
('faculty', 'Faculty Member Code of Conduct', 'Professional Standards for Faculty',
 'It is the responsibility of every faculty member to advance and disseminate knowledge through his/her professional activities. A faculty member should always try to give his/her best to the students and to the university. A faculty member should always adhere to honest dealing and fair play. A faculty member in good conscience should have the freedom of expression of opinion.',
 'fas fa-chalkboard-teacher', 2, 1),
('staff', 'Staff Code of Conduct', 'Standards of Integrity for All Staff',
 'The general conduct and behaviour of the Prime University employees in carrying out their work is an important yardstick by which the performance, honesty, integrity, and impartiality of the Prime University is judged and public trust maintained. It is important therefore that our core values underpin the day to day activities of the University.',
 'fas fa-users-cog', 3, 1);

-- ── Seed student items ────────────────────────────────────────────────────────

SET @s1 = (SELECT id FROM cms_coc_sections WHERE section_key = 'student');

INSERT INTO `cms_coc_items` (`section_id`, `item_text`, `sort_order`) VALUES
(@s1, 'Entering the University premise without Identity Cards.', 1),
(@s1, 'Smoking or taking liquors, drugs, etc. inside the University premises.', 2),
(@s1, 'Playing cards.', 3),
(@s1, 'Writing, drawing or painting on any university property.', 4),
(@s1, 'Putting on attire that is lewd, indecent, or obscene.', 5),
(@s1, 'Cheating in the Examinations.', 6),
(@s1, 'Disorderly conduct, including obstructive and disruptive behaviour that interferes with teaching, research, administration, or other university or university-authorized activity.', 7),
(@s1, 'Failure to comply with the directions of authorized university officials in the performance of their duties, including failure to identify oneself when requested to do so; failure to comply with the terms of a disciplinary sanction; or refusal to vacate a university facility when directed to do so.', 8),
(@s1, 'Unauthorized entry, use, or occupancy of university facilities.', 9),
(@s1, 'Interfering with an individual\'s personal safety, academic efforts, employment or participation in university-sponsored activities; injuring that person or damaging his or her property; or using "fighting words" that are spoken face-to-face as a personal insult.', 10),
(@s1, 'Intentionally obstructing or blocking access to university facilities, property, or programs.', 11),
(@s1, 'Engagement, solicitation, initiation, encouragement, abetment, organization, facilitation, or provocation of any sort of political activity inside and in the adjacent area of the university premises.', 12),
(@s1, 'Dishonest conduct including false accusation of misconduct, forgery, alteration, or misuse of any university document, record, or identification; and giving to a university official information known to be false.', 13),
(@s1, 'Assuming another person\'s identity or role through deception or without proper authorization.', 14),
(@s1, 'Knowingly initiating, transmitting, filing, or circulating a false report or warning concerning an impending bombing, fire, or other emergency or catastrophe.', 15),
(@s1, 'Unauthorized release or use of any university access codes for computer systems, duplicating systems, and other university equipment.', 16),
(@s1, 'Actions that endanger one\'s self, others in the university community, or the academic process.', 17),
(@s1, 'Unauthorized taking, possession, or use of university property or services, or the property or services of others.', 18),
(@s1, 'Damage or destruction of university property or the property belonging to others.', 19),
(@s1, 'Unauthorized setting of fires on university property; unauthorized use of or interference with fire equipment and emergency personnel.', 20),
(@s1, 'Unauthorized possession, use, manufacture, distribution, or sale of illegal fireworks, incendiary devices, weapons or other dangerous explosives, drugs.', 21),
(@s1, 'Acting with violence.', 22),
(@s1, 'Aiding, encouraging, or participating in a riot.', 23),
(@s1, 'Harassment of any kind.', 24),
(@s1, 'Stalking or hazing of any kind whether the behaviour is carried out verbally, physically, electronically, or in written form.', 25),
(@s1, 'Physical abuse of any person including use of physical force, violence, or threats that endanger health, safety, academic efforts, or participation in university activities.', 26),
(@s1, 'Sexual assault or sexual contact with another person, including while any party involved is in an impaired state.', 27),
(@s1, 'Gambling or any other game or activity with the element of betting.', 28),
(@s1, 'Violation of other disseminated university regulations, policies, or rules.', 29),
(@s1, 'A violation of any criminal law.', 30),
(@s1, 'Engaging in or encouraging any behaviour or activity that threatens or intimidates any potential participant in a judicial process.', 31),
(@s1, 'Possession and distribution of unauthorized printed materials inimical to public interest.', 32),
(@s1, 'Membership in political subversive organization.', 33);

-- ── Seed faculty items ────────────────────────────────────────────────────────

SET @s2 = (SELECT id FROM cms_coc_sections WHERE section_key = 'faculty');

INSERT INTO `cms_coc_items` (`section_id`, `item_text`, `sort_order`) VALUES
(@s2, 'Willful failure to perform the academic duties assigned to him/her in accordance with the Act, Statutes and Ordinances.', 1),
(@s2, 'Victimization of and discrimination against students, colleagues and other staff.', 2),
(@s2, 'Inciting of student(s) against other student(s), colleague(s), the University administration and its employee(s).', 3),
(@s2, 'Raising question of caste, creed, religion, race or sex in his/her relationship with students, colleagues and other staff.', 4),
(@s2, 'Refusal to carry out the decisions of competent authorities/bodies and officers of the University in due exercise of their functions, made in accordance with the Act, Statutes and Ordinances.', 5),
(@s2, 'Knowingly or wilfully neglecting his duties.', 6),
(@s2, 'Propagating through teaching lessons or otherwise, communal or sectarian outlook, or inciting or allowing any student to indulge in communal or sectarian activities.', 7),
(@s2, 'Discriminating against any student on the ground of caste, creed, language, place of origin, social and cultural background.', 8),
(@s2, 'Indulging or encouraging any form of malpractice connected with examinations or any other university activity.', 9),
(@s2, 'Absenting himself/herself from classes without prior permission of the proper authority while being present in the University.', 10),
(@s2, 'Remaining absent from the University without leave or without the previous permission of the authority.', 11),
(@s2, 'Accepting any job of a remunerative character from any source other than the university or giving private tuition to any student or other person.', 12),
(@s2, 'Engaging himself as a selling agent or canvasser for any publishing firm or trader.', 13),
(@s2, 'Asking for or accepting any contribution or otherwise associating himself with the raising of any fund or making any other collections.', 14),
(@s2, 'Entering into any monetary transaction with any student or his parent/guardian; exploiting his influence for personal ends.', 15),
(@s2, 'Accepting or permitting any member of his family or any other person acting on his behalf to accept any gift from any student or his parent/guardian.', 16),
(@s2, 'Practicing or inciting any student to practice casteism, communalism or untouchability.', 17),
(@s2, 'Causing or inciting any other person to cause any damage to the university property.', 18),
(@s2, 'Behaving or encouraging or inciting a student, faculty member or an employee to behave in a rowdy or disorderly manner in the university premises.', 19),
(@s2, 'Committing or inciting an act of violence, or any act which involves moral turpitude.', 20),
(@s2, 'Organizing or attending any meeting during the working hours except where permitted by the authority.', 21),
(@s2, 'Not punching attendance machine on time at arrival and departure from the university campus.', 22),
(@s2, 'Not devoting the requisite number of teaching hours as assigned according to the teaching load.', 23),
(@s2, 'Using abusive language, quarrelling or displaying riotous behaviour.', 24),
(@s2, 'Committing acts of insubordination and defiance to lawful orders.', 25),
(@s2, 'Making false accusations against a person, whether after being provoked or otherwise.', 26),
(@s2, 'Misappropriating university property, or committing acts of theft, fraud or embezzlement of funds.', 27),
(@s2, 'Obstructing employees of the university staff from performing their lawful duties.', 28),
(@s2, 'Divulging confidential matters relating to the university.', 29),
(@s2, 'Possessing weapons, explosives or any other objectionable material in university premises.', 30),
(@s2, 'Engaging in any activity that is not in conformity with the character and traditions of the Prime University.', 31);

-- ── Seed staff items ──────────────────────────────────────────────────────────

SET @s3 = (SELECT id FROM cms_coc_sections WHERE section_key = 'staff');

INSERT INTO `cms_coc_items` (`section_id`, `item_text`, `sort_order`) VALUES
(@s3, 'Ensuring that their conduct does not bring the integrity of their position or the University into disrepute.', 1),
(@s3, 'Not using their position or the resources of the University for personal gain or for the benefit of competitors.', 2),
(@s3, 'Avoiding conflicts of interest and acting in a way that enhances public trust and confidence.', 3),
(@s3, 'Remaining absent from duty without permission.', 4),
(@s3, 'Showing negligence or indifference in discharging duties.', 5),
(@s3, 'Overstaying the leave without intimation.', 6),
(@s3, 'Discharging their duties with diligence, efficiency and courtesy.', 7),
(@s3, 'Making impartial decisions based on examination of facts, merits and law relating to each matter.', 8),
(@s3, 'Serving the University conscientiously, honestly and impartially.', 9),
(@s3, 'Showing insubordination alone or in combination with others, to any lawful or reasonable order of the competent authority.', 10),
(@s3, 'Unauthorized use of University property.', 11),
(@s3, 'Causing wilful loss to University property.', 12),
(@s3, 'Misappropriation of fund of the University.', 13),
(@s3, 'Any activity which creates indiscipline or moral degradation.', 14),
(@s3, 'Involvement in any other activity which is considered to be detrimental to the interest of the University.', 15),
(@s3, 'Soliciting gifts directly or indirectly.', 16),
(@s3, 'Not showing reasonable care for University property, resources, and funds.', 17),
(@s3, 'Incurring liability on the part of the Prime University without proper authorization.', 18),
(@s3, 'Not observing the rules governing the making of claims and payments of any kind.', 19),
(@s3, 'Absenting themselves from duty without authorization during working hours.', 20),
(@s3, 'Engaging in any gainful occupation other than as an employee of the University that might impair performance or conflict with the interests of the University.', 21),
(@s3, 'Failing to follow the principles of respect for others, collegiality, equality, and maintaining a courteous, efficient and impartial workplace.', 22),
(@s3, 'Failing to treat students and the public equally, with courtesy and in an impartial fashion.', 23),
(@s3, 'Providing incorrect information in their written application and at the interview.', 24),
(@s3, 'Failing to report a charge or conviction of an indictable criminal offence to the registrar of the university.', 25);
