-- Medical Center Module SQL

CREATE TABLE IF NOT EXISTS mc_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mc_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_slots INT NOT NULL DEFAULT 10,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mc_appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_name VARCHAR(200) NOT NULL,
    patient_type ENUM('student','faculty','staff','officer') NOT NULL DEFAULT 'student',
    patient_id_no VARCHAR(50),
    department VARCHAR(200),
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(200),
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    chief_complaint TEXT,
    status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT,
    token_number VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mc_prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT,
    patient_name VARCHAR(200) NOT NULL,
    patient_type ENUM('student','faculty','staff','officer') NOT NULL DEFAULT 'student',
    patient_id_no VARCHAR(50),
    department VARCHAR(200),
    age VARCHAR(20),
    gender ENUM('male','female','other'),
    contact_number VARCHAR(20),
    diagnosis TEXT,
    medicines_json TEXT COMMENT 'JSON array of prescribed medicines',
    advice TEXT,
    follow_up_date DATE,
    prescription_date DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mc_medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    generic_name VARCHAR(200),
    category VARCHAR(100),
    unit VARCHAR(50) DEFAULT 'tablet',
    quantity_in_stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 10,
    supplier VARCHAR(200),
    unit_cost DECIMAL(10,2),
    expiry_date DATE,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mc_health_tips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(300) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'General',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO mc_settings (`key`, `value`) VALUES
('clinic_name', 'Prime University Medical Center'),
('doctor_name', 'Dr. Saida Ahmed'),
('doctor_qualification', 'MBBS, MPH (NIPSOM), CCD, CCVD, FCGP'),
('doctor_designation', 'Medical Officer'),
('clinic_location', 'Ground Floor, Administrative Building, Prime University'),
('clinic_hours_weekday', '9:00 AM – 5:00 PM'),
('clinic_hours_weekend', 'Closed'),
('contact_phone', '01969-955566'),
('contact_email', 'medical@primeuniversity.ac.bd'),
('emergency_note', 'For emergency, call 999 or proceed to nearest hospital immediately.'),
('appointment_enabled', '1');

INSERT IGNORE INTO mc_schedules (day_of_week, start_time, end_time, max_slots, is_available) VALUES
(0, '09:00:00', '17:00:00', 10, 0),
(1, '09:00:00', '17:00:00', 20, 1),
(2, '09:00:00', '17:00:00', 20, 1),
(3, '09:00:00', '17:00:00', 20, 1),
(4, '09:00:00', '17:00:00', 20, 1),
(5, '09:00:00', '13:00:00', 10, 1),
(6, '09:00:00', '17:00:00', 10, 0);

INSERT IGNORE INTO mc_health_tips (title, content, category, sort_order) VALUES
('Stay Hydrated', 'Drink at least 8 glasses of water daily. Proper hydration supports concentration, energy levels, and overall health during your academic journey.', 'Wellness', 1),
('Mental Health Matters', 'It is okay to seek help. Our medical center offers free counseling sessions and mental health support for all students and staff.', 'Mental Health', 2),
('Regular Health Screening', 'Get your blood pressure, blood glucose, and BMI checked regularly. Early detection prevents long-term complications.', 'Preventive Care', 3),
('Manage Stress Effectively', 'Practice mindfulness, maintain a regular sleep schedule, and take short breaks between study sessions to manage academic stress.', 'Wellness', 4);
