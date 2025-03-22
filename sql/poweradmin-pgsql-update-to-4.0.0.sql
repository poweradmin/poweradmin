CREATE TABLE login_attempts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NULL,
    ip_address VARCHAR(45) NOT NULL,
    "timestamp" INTEGER NOT NULL,
    successful BOOLEAN NOT NULL,
    CONSTRAINT fk_login_attempts_users
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_login_attempts_user_id ON login_attempts(user_id);
CREATE INDEX idx_login_attempts_ip_address ON login_attempts(ip_address);
CREATE INDEX idx_login_attempts_timestamp ON login_attempts("timestamp");
