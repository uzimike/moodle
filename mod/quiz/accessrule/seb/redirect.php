<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * TODO describe file redirect
 *
 * @package    quizaccess_seb
 * @copyright  2024 Michael Kotlyar <michael.kotlyar@catalyst-eu.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../../config.php');

use \quizaccess_seb\continue_session;

$key = required_param('key', PARAM_TEXT);
$userid = required_param('userid', PARAM_INT);
$wantsurl = required_param('wantsurl', PARAM_TEXT);

// Validate the URL.
if (!filter_var($wantsurl, FILTER_VALIDATE_URL)) {
    header("Location: {$CFG->wwwroot}");
    exit;
}

// Ensure the domain matches the Moodle domain.
$domain = parse_url($wantsurl, PHP_URL_HOST);
$mydomain = parse_url($CFG->wwwroot, PHP_URL_HOST);;
if ($domain != $mydomain) {
    header("Location: {$CFG->wwwroot}");
    exit;
}

// Verify and login.
continue_session::handle_sessionkey($key, $userid, $wantsurl);
