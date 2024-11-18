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

namespace quizaccess_seb;

/**
 * Class continue_session
 *
 * @package    quizaccess_seb
 * @copyright  2024 Michael Kotlyar <michael.kotlyar@catalyst-eu.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class continue_session {

    const SCRIPT = 'seb';

    public static function create_sessionkey($userid) {
        global $CFG;

        // Security checks.
        require_login();

        // Generate URL.
        $user = get_complete_user_data('id', $userid, $CFG->mnet_localhost_id, true);

        $userid = $user->id;
        $iprestriction = $user->lastip ?: getremoteaddr();
        $validuntil = time() + 60;

        return create_user_key(self::SCRIPT, $userid, $userid, $iprestriction, $validuntil);
    }


    /**
     * Logs a user in using userkey and redirects after.
     *
     * @throws \moodle_exception If something went wrong.
     */
    public static function handle_sessionkey($key, $userid) {
        global $CFG;

        // Validate key.
        try {
            $key = validate_user_key($key, self::SCRIPT, $userid);
        } catch (\moodle_exception $exception) {
            // If user is logged in and key is not valid, we'd like to logout a user.
            if (isloggedin()) {
                require_logout();
            }
            throw $exception;
        }

        // Validate and login user.
        if (isloggedin()) {
            global $USER;
            if ($USER->id != $key->userid) {
                // Logout the current user if it's different to one that associated to the valid key.
                require_logout();
            }
        } else {
            $user = get_complete_user_data('id', $key->userid, $CFG->mnet_localhost_id, true);
            complete_user_login($user);
        }

        // Delete keys.
        delete_user_key(self::SCRIPT, $userid);
    }
}
