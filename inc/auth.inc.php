<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
/*
 * Only the first function gets called, so put the preferred functions first
 * 
 */


/** Authenticate Session
 *
 * Checks if user is logging in, logging out, or session expired and performs
 * actions accordingly
 *
 * @return null
 */
add_listener('authenticate', 'authenticate_local');