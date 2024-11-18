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
$cmid = required_param('cmid', PARAM_INT);

// Verify and login.
try {
    continue_session::handle_sessionkey($key, $userid);
} catch (exception $e) {
    header("Location: {$CFG->wwwroot}");
    exit;
}

redirect(new \moodle_url('/mod/quiz/accessrule/seb/config.php', ['cmid' => $cmid]));
