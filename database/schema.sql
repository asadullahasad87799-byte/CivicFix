-- CivicFix v3.0 (Smart-City GIS, AI Vision, WhatsApp/SMS/IVR & Disaster-Resilient Edition)
-- Compatible with MySQL / MariaDB (XAMPP)

CREATE DATABASE IF NOT EXISTS civicfix_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE civicfix_db;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    department VARCHAR(120) NOT NULL DEFAULT 'District & Municipal Command Center',
    role VARCHAR(40) NOT NULL DEFAULT 'super_admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_code VARCHAR(32) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL,
    category VARCHAR(60) NOT NULL,
    department_code VARCHAR(32) NOT NULL DEFAULT 'DEPT-GEN',
    assigned_department VARCHAR(160) NOT NULL DEFAULT 'General Civic & Tehbazari Nodal Cell',
    description TEXT NOT NULL,
    citizen_name VARCHAR(100) DEFAULT 'Anonymous Citizen',
    citizen_phone VARCHAR(30) DEFAULT NULL,
    citizen_email VARCHAR(120) DEFAULT NULL,
    ward_zone VARCHAR(100) DEFAULT 'Zone D - Model Town',
    location_text VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    resolution_photo_path VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'Submitted',
    priority VARCHAR(24) NOT NULL DEFAULT 'Medium',
    sla_hours INT NOT NULL DEFAULT 48,
    intake_channel VARCHAR(40) NOT NULL DEFAULT 'Web Portal',
    cluster_count INT NOT NULL DEFAULT 1,
    ai_suggested_priority VARCHAR(24) DEFAULT NULL,
    ai_explanation TEXT DEFAULT NULL,
    possible_duplicate_of VARCHAR(32) DEFAULT NULL,
    duplicate_similarity DECIMAL(5,2) DEFAULT NULL,
    upvotes INT NOT NULL DEFAULT 1,
    admin_remarks TEXT DEFAULT NULL,
    assigned_je VARCHAR(120) DEFAULT NULL,
    escalation_tier VARCHAR(80) DEFAULT 'Tier 1 - Field JE',
    citizen_rating TINYINT DEFAULT NULL,
    citizen_feedback TEXT DEFAULT NULL,
    feedback_at DATETIME DEFAULT NULL,
    reopen_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_dept (department_code),
    INDEX idx_coords (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaint_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    old_status VARCHAR(32) DEFAULT NULL,
    new_status VARCHAR(32) NOT NULL,
    action_note TEXT NOT NULL,
    changed_by VARCHAR(120) NOT NULL DEFAULT 'System',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cid (complaint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_analysis_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    suggested_priority VARCHAR(24) NOT NULL,
    urgency_score INT NOT NULL DEFAULT 50,
    matched_keywords VARCHAR(255) DEFAULT NULL,
    explanation TEXT DEFAULT NULL,
    duplicate_candidates_json TEXT DEFAULT NULL,
    analysis_source VARCHAR(64) DEFAULT 'heuristic_v3',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_cid (complaint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS citizen_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    channel VARCHAR(32) NOT NULL,
    recipient_masked VARCHAR(80) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message_body TEXT NOT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    delivery_status VARCHAR(40) NOT NULL DEFAULT 'DELIVERED',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_cid (complaint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
