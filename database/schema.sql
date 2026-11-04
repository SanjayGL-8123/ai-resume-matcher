-- AI Resume Analyzer & Job Matcher Database Schema
-- Compatible with MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+

CREATE DATABASE IF NOT EXISTS resume_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE resume_ai;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(120) UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Resumes Table
CREATE TABLE IF NOT EXISTS resumes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  text_content MEDIUMTEXT,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Resume Analysis Table
CREATE TABLE IF NOT EXISTS resume_analysis (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resume_id INT NOT NULL,
  ats_score TINYINT UNSIGNED NOT NULL,
  skills_json JSON,
  feedback_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resume_id) REFERENCES resumes(id) ON DELETE CASCADE,
  INDEX idx_resume_id (resume_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Job Sources Table
CREATE TABLE IF NOT EXISTS job_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL,
  last_status VARCHAR(255),
  last_run TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Job Vacancies Table
CREATE TABLE IF NOT EXISTS job_vacancies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(50) NOT NULL,
  external_id VARCHAR(150) NOT NULL,
  company VARCHAR(150),
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT,
  skills_json JSON,
  location VARCHAR(150),
  is_remote TINYINT(1) DEFAULT 0,
  apply_url VARCHAR(600),
  posted_at DATETIME,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_job (source, external_id),
  INDEX idx_active (is_active),
  INDEX idx_company (company),
  INDEX idx_remote (is_remote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Job Matches Table
CREATE TABLE IF NOT EXISTS job_matches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resume_id INT NOT NULL,
  job_id INT NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  label VARCHAR(20),
  matching_json JSON,
  missing_json JSON,
  courses_json JSON,
  UNIQUE KEY uq_match (resume_id, job_id),
  FOREIGN KEY (resume_id) REFERENCES resumes(id) ON DELETE CASCADE,
  FOREIGN KEY (job_id) REFERENCES job_vacancies(id) ON DELETE CASCADE,
  INDEX idx_match_score (score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Applications Tracking Table
CREATE TABLE IF NOT EXISTS applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  job_id INT NOT NULL,
  status ENUM('saved', 'applied', 'interview', 'rejected', 'offer') DEFAULT 'saved',
  notes TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app (user_id, job_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (job_id) REFERENCES job_vacancies(id) ON DELETE CASCADE,
  INDEX idx_app_user (user_id),
  INDEX idx_app_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Admin Activity Logs Table
CREATE TABLE IF NOT EXISTS admin_activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  action VARCHAR(255) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Default Job Sources
INSERT INTO job_sources (name, last_status, last_run) VALUES
('adzuna', 'Ready to collect', NULL),
('remotive', 'Ready to collect', NULL),
('arbeitnow', 'Ready to collect', NULL),
('seed', 'Active (Initial seed)', NOW())
ON DUPLICATE KEY UPDATE last_status = VALUES(last_status);

-- Seed Initial Tech Jobs for Instant Testing & Demo
INSERT INTO job_vacancies (source, external_id, company, title, description, skills_json, location, is_remote, apply_url, posted_at, is_active)
VALUES
('seed', 'seed-001', 'TechNova Solutions', 'Junior Python / Backend Developer', 
 'We are seeking a Junior Python Developer with solid foundations in Python, FastAPI or Flask, REST APIs, and SQL databases. Experience with Git, Docker, and Linux is a major plus. You will work on microservices and real-time data pipelines.',
 '["python", "fastapi", "flask", "rest api", "sql", "mysql", "git", "docker", "linux"]',
 'Bengaluru, India', 0, 'https://linkedin.com/jobs', NOW(), 1),

('seed', 'seed-002', 'CloudScale Dynamics', 'Full Stack Software Engineer', 
 'Looking for an energetic Full Stack Engineer proficient in JavaScript/TypeScript, React, Node.js, and MongoDB/MySQL. Familiarity with HTML, CSS, Tailwind, CI/CD, and AWS cloud deployment is desired.',
 '["javascript", "typescript", "react", "node.js", "html", "css", "mongodb", "mysql", "rest api", "git", "aws", "ci/cd"]',
 'Remote', 1, 'https://remotive.com', NOW(), 1),

('seed', 'seed-003', 'Insight Data Labs', 'Data Analyst / Junior Data Scientist', 
 'Join our analytics team! Requires proficiency in Python, SQL, Pandas, NumPy, Data Analysis, and visualization tools such as Power BI or Tableau. Basic knowledge of Machine Learning and Excel is required.',
 '["python", "sql", "pandas", "numpy", "data analysis", "power bi", "tableau", "excel", "machine learning"]',
 'Hyderabad, India', 0, 'https://adzuna.com', NOW(), 1),

('seed', 'seed-004', 'Apex Systems', 'Java Backend Engineer', 
 'Exciting opportunity for Java developers. Must have strong understanding of Core Java, Spring Boot, Microservices, MySQL or PostgreSQL, Hibernate, and REST API development. Docker and Kubernetes experience is advantageous.',
 '["java", "spring", "mysql", "postgresql", "rest api", "docker", "kubernetes", "git"]',
 'Pune, India', 0, 'https://linkedin.com/jobs', NOW(), 1),

('seed', 'seed-005', 'CyberGuard InfoSec', 'Cybersecurity & Network Associate', 
 'Entry-level position in information security. Required skills include Cybersecurity fundamentals, Networking (TCP/IP, Firewalls), Linux administration, Python scripting, and vulnerability testing.',
 '["cybersecurity", "networking", "linux", "python", "testing"]',
 'Remote', 1, 'https://remotive.com', NOW(), 1),

('seed', 'seed-006', 'NextGen AI Labs', 'Machine Learning Engineer', 
 'Looking for an ML Engineer with hands-on experience in Python, PyTorch or TensorFlow, Scikit-Learn, Deep Learning, and NLP. Experience building and deploying models with Docker and FastAPI is preferred.',
 '["python", "machine learning", "deep learning", "nlp", "pytorch", "tensorflow", "scikit-learn", "fastapi", "docker"]',
 'Bengaluru, India', 0, 'https://adzuna.com', NOW(), 1)

ON DUPLICATE KEY UPDATE title = VALUES(title), is_active = 1;
