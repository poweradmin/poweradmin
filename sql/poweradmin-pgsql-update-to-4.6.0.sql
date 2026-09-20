-- Poweradmin schema update to 4.6.0
-- Add the zone_change_requests table and the change approval permissions for
-- the opt-in change approval workflow (approval.enabled). Requests carry a JSON
-- action list that is replayed against the zone once a reviewer approves it.

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
