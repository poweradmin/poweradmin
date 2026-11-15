-- Create admin user with password 'poweradmin123' for E2E testing
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, 'admin', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Administrator', 'admin@example.net', 'Administrator with full rights.', 1, 1, 0);
SELECT setval('users_id_seq', (SELECT MAX(id) FROM users));
