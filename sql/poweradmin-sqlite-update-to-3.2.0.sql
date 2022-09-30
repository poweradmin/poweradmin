CREATE TABLE log_users (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL);

CREATE TABLE log_zones (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL, zone_id integer);