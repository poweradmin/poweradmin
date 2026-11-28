-- Poweradmin schema update to 4.4.0
-- Add is_default flag to zone_templ for marking a system-wide default zone template
-- Only one template should carry is_default=1 at a time; uniqueness is enforced at the application layer

ALTER TABLE zone_templ ADD COLUMN is_default BOOLEAN NOT NULL DEFAULT 0;

-- Add the edit-as-client permission to databases upgraded from 2.x: 2.2.1 put it in the structure
-- files only, so the 4.1.0 and 4.2.0 Editor grants found nothing. Grant it only where it was missing.
CREATE TEMP TABLE pa_add_client_edit AS
SELECT COUNT(*) = 0 AS missing FROM perm_items WHERE name = 'zone_content_edit_own_as_client';

INSERT INTO perm_items (name, descr)
SELECT 'zone_content_edit_own_as_client', 'User is allowed to edit record, but not SOA and NS.'
WHERE (SELECT missing FROM pa_add_client_edit) = 1;

INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
JOIN perm_items pi ON pi.name = 'zone_content_edit_own_as_client'
WHERE (SELECT missing FROM pa_add_client_edit) = 1
  AND ((pt.name = 'Editor' AND pt.template_type = 'user' AND pt.descr = 'Edit own zone records but cannot modify SOA and NS records.')
    OR (pt.name = 'Editors' AND pt.template_type = 'group' AND pt.descr = 'Edit zone records (no SOA/NS) for group members.'));

DROP TABLE pa_add_client_edit;
