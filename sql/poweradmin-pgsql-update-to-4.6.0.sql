-- Poweradmin schema update to 4.6.0
-- Add the zone_change_requests table and the change approval permissions for
-- the opt-in change approval workflow (approval.enabled). Requests carry a JSON
-- action list that is replayed against the zone once a reviewer approves it.

-- The 4.1.0 update shipped before 4.6.0 inserted permissions with explicit ids without
-- advancing the sequences, so the inserts below would collide. Resync them first.
SELECT setval('perm_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_items), 1));
SELECT setval('perm_templ_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ), 1));
SELECT setval('perm_templ_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ_items), 1));

CREATE SEQUENCE IF NOT EXISTS zone_change_requests_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."zone_change_requests" (
    "id" integer DEFAULT nextval('zone_change_requests_id_seq') NOT NULL,
    "zone_id" integer NOT NULL,
    "zone_name" character varying(255) NOT NULL,
    "kind" character varying(16) NOT NULL,
    "status" character varying(16) NOT NULL,
    "requester_id" integer,
    "requester_name" character varying(64) NOT NULL,
    "request_comment" text,
    "base_serial" character varying(32),
    "payload" text NOT NULL,
    "reviewer_id" integer,
    "reviewer_name" character varying(64),
    "review_comment" text,
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "reviewed_at" timestamp,
    "applied_at" timestamp,
    "error" text,
    "snapshot" text,
    CONSTRAINT "zone_change_requests_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX IF NOT EXISTS "idx_zone_change_requests_zone_id" ON "public"."zone_change_requests" USING btree ("zone_id");
CREATE INDEX IF NOT EXISTS "idx_zone_change_requests_status" ON "public"."zone_change_requests" USING btree ("status");
CREATE INDEX IF NOT EXISTS "idx_zone_change_requests_requester_id" ON "public"."zone_change_requests" USING btree ("requester_id");
CREATE INDEX IF NOT EXISTS "idx_zone_change_requests_created_at" ON "public"."zone_change_requests" USING btree ("created_at");

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

-- Restore permissions that the 4.2.0 and 4.5.0 updates failed to add on databases
-- whose sequences lagged behind, with the view-split grants 4.5.0 gave alongside them.
DO $$
DECLARE
    p record;
    new_id integer;
BEGIN
    FOR p IN SELECT * FROM (VALUES
        ('user_enforce_mfa', 'User is required to use multi-factor authentication.', NULL),
        ('zone_dnssec_manage_own', 'User is allowed to manage DNSSEC keys for zones he owns.', NULL),
        ('zone_logs_view_own', 'User is allowed to view activity logs for zones he owns.', NULL),
        ('zone_logs_view_others', 'User is allowed to view activity logs for zones he does not own.', NULL),
        ('user_logs_view', 'User is allowed to view the user activity logs.', NULL),
        ('group_logs_view', 'User is allowed to view the group activity logs.', NULL),
        ('zone_content_edit_ns_subzone', 'User is allowed to edit NS records below the zone apex, but not SOA and apex NS records.', NULL),
        ('zone_metadata_view_own', 'User is allowed to see the meta data of zones he owns.', 'zone_content_view_own'),
        ('zone_metadata_view_others', 'User is allowed to see the meta data of zones he does not own.', 'zone_content_view_others'),
        ('zone_ownership_view_own', 'User is allowed to see the owners of zones he owns.', 'zone_content_view_own'),
        ('zone_ownership_view_others', 'User is allowed to see the owners of zones he does not own.', 'zone_content_view_others'),
        ('server_status_view', 'User is allowed to view the PowerDNS server status, e.g. for monitoring.', NULL)
    ) AS t(name, descr, granted_from)
    LOOP
        CONTINUE WHEN EXISTS (SELECT 1 FROM perm_items WHERE name = p.name);
        INSERT INTO perm_items (name, descr) VALUES (p.name, p.descr) RETURNING id INTO new_id;
        IF p.granted_from IS NOT NULL THEN
            INSERT INTO perm_templ_items (templ_id, perm_id)
            SELECT DISTINCT pti.templ_id, new_id
            FROM perm_templ_items pti
            JOIN perm_items src ON src.id = pti.perm_id AND src.name = p.granted_from;
        END IF;
    END LOOP;
END $$;
