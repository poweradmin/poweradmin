ALTER TABLE perm_templ_items MODIFY templ_id int(11) NOT NULL;
ALTER TABLE perm_templ_items MODIFY perm_id  int(11) NOT NULL;
ALTER TABLE zones MODIFY id int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE zones MODIFY domain_id int(11) NOT NULL;
ALTER TABLE zones MODIFY owner int(11) NOT NULL;
ALTER TABLE zones MODIFY zone_templ_id int(11) NOT NULL;
