-- Poweradmin schema update to 4.4.0
-- Add is_default flag to zone_templ for marking a system-wide default zone template
-- Only one template should carry is_default=true at a time; uniqueness is enforced at the application layer

ALTER TABLE "zone_templ" ADD COLUMN "is_default" boolean DEFAULT false NOT NULL;

-- Add the edit-as-client permission to databases upgraded from 2.x: 2.2.1 put it in the structure
-- files only, so the 4.1.0 and 4.2.0 Editor grants found nothing. Grant it only where it was missing.
SELECT setval('perm_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_items), 1));
SELECT setval('perm_templ_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ_items), 1));

DO $$
DECLARE
    new_id integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_content_edit_own_as_client') THEN
        INSERT INTO perm_items (name, descr)
        VALUES ('zone_content_edit_own_as_client', 'User is allowed to edit record, but not SOA and NS.')
        RETURNING id INTO new_id;
        INSERT INTO perm_templ_items (templ_id, perm_id)
        SELECT id, new_id FROM perm_templ
        WHERE (name = 'Editor' AND template_type = 'user' AND descr = 'Edit own zone records but cannot modify SOA and NS records.')
           OR (name = 'Editors' AND template_type = 'group' AND descr = 'Edit zone records (no SOA/NS) for group members.');
    END IF;
END $$;
