USE eglise_germe_de_david;
ALTER TABLE users ADD COLUMN role ENUM('admin','treasurer','viewer') NOT NULL DEFAULT 'viewer', ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE transactions ADD COLUMN updated_by INT NULL, ADD COLUMN updated_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS audit_logs(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,action VARCHAR(40) NOT NULL,table_name VARCHAR(80) NOT NULL,record_id BIGINT NULL,details TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(created_at),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL);
INSERT IGNORE INTO settings(setting_key,setting_value) VALUES('remote_url','');

UPDATE users SET role='admin' WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM users) first_user);
