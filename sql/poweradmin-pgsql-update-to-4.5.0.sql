-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE SEQUENCE IF NOT EXISTS log_record_changes_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."log_record_changes" (
    "id" integer DEFAULT nextval('log_record_changes_id_seq1') NOT NULL,
    "zone_id" integer,
    "record_id" integer,
    "action" character varying(32) NOT NULL,
    "user_id" integer,
    "username" character varying(64) NOT NULL,
    "before_state" text,
    "after_state" text,
    "client_ip" character varying(64),
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT "log_record_changes_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX IF NOT EXISTS "idx_log_record_changes_created_at" ON "public"."log_record_changes" USING btree ("created_at");
CREATE INDEX IF NOT EXISTS "idx_log_record_changes_zone_id" ON "public"."log_record_changes" USING btree ("zone_id");
CREATE INDEX IF NOT EXISTS "idx_log_record_changes_action" ON "public"."log_record_changes" USING btree ("action");
