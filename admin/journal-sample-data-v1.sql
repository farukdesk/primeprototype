-- ============================================================
-- Journal Management - Sample / Demo Data (v1)
-- Run AFTER admin/journal-management-v1.sql on an EMPTY module
-- (assumes auto-increment ids start at 1 for the journal_* tables).
-- Safe to delete later: it is normal demo content only.
-- ============================================================

-- ── Journals ─────────────────────────────────────────────────────
INSERT INTO journal_journals
  (id, name, slug, short_name, issn, e_issn, description, publisher, department,
   frequency, language, contact_email, website_url, status, sort_order) VALUES
(1, 'Journal of Prime University', 'journal-of-prime-university', 'JPU',
 '2789-1234', '2789-5678',
 'The Journal of Prime University (JPU) is a peer-reviewed, multidisciplinary journal publishing original research articles, review papers, and case studies from all faculties of the university. JPU aims to promote scholarly research and disseminate knowledge in science, engineering, business, humanities, and social sciences.',
 'Prime University', 'Research / Academic Affairs',
 'Biannual', 'English', 'jpu@primeuniversity.ac.bd',
 'https://primeuniversity.ac.bd/journal.php?slug=journal-of-prime-university',
 'active', 1),
(2, 'Prime University Journal of Business Studies', 'prime-university-journal-of-business-studies', 'PUJBS',
 '2790-4321', '2790-8765',
 'PUJBS publishes empirical and theoretical research in accounting, finance, marketing, management, and entrepreneurship, with a special focus on emerging economies and the business landscape of Bangladesh.',
 'Prime University', 'Faculty of Business Administration',
 'Quarterly', 'English', 'pujbs@primeuniversity.ac.bd',
 NULL, 'active', 2);

-- ── Volumes ──────────────────────────────────────────────────────
INSERT INTO journal_volumes (id, journal_id, volume_number, year) VALUES
(1, 1, 1, 2024),
(2, 1, 2, 2025),
(3, 2, 1, 2025);

-- ── Issues ───────────────────────────────────────────────────────
INSERT INTO journal_issues (id, volume_id, issue_number, title, published_date, is_current, is_published) VALUES
(1, 1, 1, NULL, '2024-06-30', 0, 1),
(2, 1, 2, NULL, '2024-12-31', 0, 1),
(3, 2, 1, 'Special Issue on Sustainable Development', '2025-06-30', 1, 1),
(4, 3, 1, NULL, '2025-03-31', 1, 1);

-- ── Authors ──────────────────────────────────────────────────────
INSERT INTO journal_authors (id, full_name, email, affiliation, country, bio) VALUES
(1, 'Dr. Md. Rafiqul Islam', 'rafiqul.islam@primeuniversity.ac.bd',
 'Department of Computer Science & Engineering, Prime University', 'Bangladesh',
 'Professor of Computer Science with research interests in machine learning and natural language processing.'),
(2, 'Dr. Ayesha Siddiqua', 'ayesha.siddiqua@primeuniversity.ac.bd',
 'Department of Business Administration, Prime University', 'Bangladesh',
 'Associate Professor researching consumer behaviour and digital marketing in emerging markets.'),
(3, 'Mohammad Tanvir Hasan', 'tanvir.hasan@primeuniversity.ac.bd',
 'Department of Electrical & Electronic Engineering, Prime University', 'Bangladesh',
 'Assistant Professor working on renewable energy systems and smart grids.'),
(4, 'Dr. Farhana Rahman', 'farhana.rahman@primeuniversity.ac.bd',
 'Department of English, Prime University', 'Bangladesh',
 'Senior Lecturer focusing on applied linguistics and English language teaching.'),
(5, 'Sadia Afrin Nodi', 'sadia.nodi@primeuniversity.ac.bd',
 'Department of Law, Prime University', 'Bangladesh',
 'Lecturer researching cyber law and data protection in South Asia.');

-- ── Articles (no PDFs attached in demo data - upload via admin) ──────────────
INSERT INTO journal_articles
  (id, issue_id, title, slug, abstract, keywords, page_from, page_to, doi, pdf_file, status, published_date, views, downloads) VALUES
(1, 1,
 'A Machine Learning Approach to Predicting Student Dropout in Private Universities of Bangladesh',
 'machine-learning-approach-predicting-student-dropout',
 'Student dropout is a persistent challenge for private universities in Bangladesh. This study applies supervised machine learning models to five years of enrollment records to identify at-risk students early. Random Forest achieved the highest accuracy (91.2%), with attendance rate, first-semester CGPA, and tuition payment delays emerging as the strongest predictors. The paper proposes an early-warning framework that academic advisors can deploy with minimal infrastructure.',
 'machine learning, student dropout, higher education, early warning system, Bangladesh',
 1, 18, NULL, NULL, 'published', '2024-06-30', 154, 62),
(2, 1,
 'Impact of Mobile Financial Services on Small Retail Businesses in Dhaka',
 'impact-mobile-financial-services-small-retail-dhaka',
 'This paper examines how mobile financial services (MFS) adoption affects the revenue, record keeping, and credit access of small retail businesses in Dhaka. Based on a survey of 240 retailers, MFS users reported 17% higher monthly transaction volumes and significantly better access to microcredit. Barriers include transaction fees and limited digital literacy among older shop owners.',
 'mobile financial services, SME, retail, financial inclusion, Dhaka',
 19, 34, NULL, NULL, 'published', '2024-06-30', 98, 41),
(3, 2,
 'Grid Integration Challenges of Rooftop Solar in Urban Bangladesh: A Case Study',
 'grid-integration-rooftop-solar-urban-bangladesh',
 'Rooftop solar capacity in Bangladeshi cities has grown rapidly under net-metering policies, yet grid integration remains difficult. Using measured data from twelve installations in Dhaka and Chattogram, this case study quantifies voltage fluctuation and reverse power flow issues, and evaluates three mitigation strategies including smart inverter control and localized battery storage.',
 'rooftop solar, net metering, grid integration, renewable energy, smart inverter',
 1, 15, NULL, NULL, 'published', '2024-12-31', 76, 28),
(4, 3,
 'Sustainable Campus Initiatives and Their Effect on Student Environmental Behaviour',
 'sustainable-campus-initiatives-student-environmental-behaviour',
 'This mixed-methods study evaluates how sustainability initiatives such as waste segregation, tree plantation drives, and paperless administration influence the everyday environmental behaviour of university students. Survey data from 410 students show a significant positive association between visible campus initiatives and self-reported pro-environmental behaviour, mediated by environmental awareness.',
 'sustainability, environmental behaviour, green campus, higher education',
 1, 22, NULL, NULL, 'published', '2025-06-30', 45, 12),
(5, 4,
 'Determinants of E-Commerce Adoption Among Rural Women Entrepreneurs in Bangladesh',
 'ecommerce-adoption-rural-women-entrepreneurs-bangladesh',
 'Drawing on the UTAUT2 framework, this study surveys 312 rural women entrepreneurs to identify the determinants of e-commerce adoption. Performance expectancy, social influence, and facilitating conditions significantly predict adoption intention, while perceived risk shows a strong negative effect. Policy implications for training programmes and logistics support are discussed.',
 'e-commerce, women entrepreneurs, UTAUT2, rural development, Bangladesh',
 1, 19, NULL, NULL, 'published', '2025-03-31', 61, 23),
(6, 3,
 'Data Protection Readiness of Bangladeshi Fintech Startups: A Legal Review',
 'data-protection-readiness-bangladeshi-fintech-startups',
 'This article reviews the data protection practices of fifteen fintech startups against the draft Personal Data Protection framework of Bangladesh and international benchmarks such as the GDPR. Findings reveal significant gaps in consent management and breach notification readiness, and the paper recommends a phased compliance roadmap suitable for early-stage companies.',
 'data protection, fintech, privacy law, GDPR, Bangladesh',
 23, 40, NULL, NULL, 'draft', NULL, 0, 0);

-- ── Article <-> Author assignments (ordered) ────────────────────────────────
INSERT INTO journal_article_authors (article_id, author_id, author_order) VALUES
(1, 1, 1), (1, 3, 2),
(2, 2, 1),
(3, 3, 1), (3, 1, 2),
(4, 4, 1), (4, 2, 2),
(5, 2, 1), (5, 5, 2),
(6, 5, 1);

-- ── Editorial board ───────────────────────────────────────────────────
INSERT INTO journal_editorial_board (journal_id, name, role, affiliation, email, sort_order, is_active) VALUES
(1, 'Prof. Dr. M. Abdus Sattar', 'Editor-in-Chief', 'Vice Chancellor, Prime University', 'eic.jpu@primeuniversity.ac.bd', 1, 1),
(1, 'Prof. Dr. Nusrat Jahan', 'Managing Editor', 'Dean, Faculty of Science & Engineering, Prime University', 'me.jpu@primeuniversity.ac.bd', 2, 1),
(1, 'Dr. Md. Rafiqul Islam', 'Associate Editor', 'Department of CSE, Prime University', 'rafiqul.islam@primeuniversity.ac.bd', 3, 1),
(1, 'Dr. Kamal Uddin Ahmed', 'Member', 'Department of Mathematics, Prime University', NULL, 4, 1),
(2, 'Prof. Dr. Shahnaz Parvin', 'Editor-in-Chief', 'Dean, Faculty of Business Administration, Prime University', 'eic.pujbs@primeuniversity.ac.bd', 1, 1),
(2, 'Dr. Ayesha Siddiqua', 'Managing Editor', 'Department of Business Administration, Prime University', 'ayesha.siddiqua@primeuniversity.ac.bd', 2, 1);

-- ── Module settings ──────────────────────────────────────────────────
INSERT INTO journal_settings (setting_key, setting_val) VALUES
('publisher_name', 'Prime University'),
('gs_language', 'en'),
('archive_intro', 'Browse the published volumes and issues of the academic journals of Prime University.')
ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val);
