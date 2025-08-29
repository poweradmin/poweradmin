-- Add API key management permission
INSERT INTO perm_items (id, name, descr) VALUES
(65, 'api_manage_keys', 'User is allowed to create and manage API keys.');

-- Add OIDC user links table for OpenID Connect authentication
CREATE TABLE IF NOT EXISTS oidc_user_links (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    oidc_subject VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, provider_id),
    UNIQUE (oidc_subject, provider_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_oidc_provider_id ON oidc_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_oidc_subject ON oidc_user_links(oidc_subject);
