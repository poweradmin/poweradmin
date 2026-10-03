-- Poweradmin schema update to 4.6.0
-- Add the zone_change_requests table and the change approval permissions for
-- the opt-in change approval workflow (approval.enabled). Requests carry a JSON
-- action list that is replayed against the zone once a reviewer approves it.

CREATE TABLE IF NOT EXISTS zone_change_requests (
    id integer PRIMARY KEY,
    zone_id integer NOT NULL,
    zone_name VARCHAR(255) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    requester_id integer,
    requester_name VARCHAR(64) NOT NULL,
    request_comment TEXT,
    base_serial VARCHAR(32),
    payload TEXT NOT NULL,
    reviewer_id integer,
    reviewer_name VARCHAR(64),
    review_comment TEXT,
    created_at timestamp DEFAULT current_timestamp NOT NULL,
    reviewed_at timestamp,
    applied_at timestamp,
    error TEXT,
    snapshot TEXT
);

CREATE INDEX IF NOT EXISTS idx_zone_change_requests_zone_id ON zone_change_requests(zone_id);
CREATE INDEX IF NOT EXISTS idx_zone_change_requests_status ON zone_change_requests(status);
CREATE INDEX IF NOT EXISTS idx_zone_change_requests_requester_id ON zone_change_requests(requester_id);
CREATE INDEX IF NOT EXISTS idx_zone_change_requests_created_at ON zone_change_requests(created_at);

-- Change approval permissions. No template is granted them automatically;
-- admins opt in through the permission template editor.
INSERT INTO perm_items (name, descr)
SELECT 'zone_change_request_own', 'User is allowed to request changes to zones they own'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_change_request_own');

INSERT INTO perm_items (name, descr)
SELECT 'zone_change_request_others', 'User is allowed to request changes to any zone'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_change_request_others');

INSERT INTO perm_items (name, descr)
SELECT 'zone_change_approve_own', 'User is allowed to review change requests for zones they own'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_change_approve_own');

INSERT INTO perm_items (name, descr)
SELECT 'zone_change_approve_others', 'User is allowed to review change requests for any zone'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_change_approve_others');

-- Databases upgraded from 4.0.0-4.0.4 straight to 4.1.0 or later never got the 4.0.5
-- records_zone_templ primary key (#906). Copying rowid keeps ids, so a re-run changes nothing.
BEGIN TRANSACTION;
CREATE TABLE records_zone_templ_new (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id integer NOT NULL, record_id integer NOT NULL, zone_templ_id integer NOT NULL);
INSERT INTO records_zone_templ_new (id, domain_id, record_id, zone_templ_id)
SELECT rowid, domain_id, record_id, zone_templ_id FROM records_zone_templ;
DROP TABLE records_zone_templ;
ALTER TABLE records_zone_templ_new RENAME TO records_zone_templ;
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_domain_id ON records_zone_templ(domain_id);
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_zone_templ_id ON records_zone_templ(zone_templ_id);
COMMIT;
