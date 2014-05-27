<?php
/*
 * Only the first function gets called, so put the preferred function
 * first
 *
 *
 */

add_listener('authenticate', 'authenticate_local');