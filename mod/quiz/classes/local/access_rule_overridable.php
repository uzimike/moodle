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

namespace mod_quiz\local;

use mod_quiz\form\edit_override_form;
use MoodleQuickForm;

/**
 * Class overridable
 *
 * @package    mod_quiz
 * @copyright  2024 Michael Kotlyar <michael.kotlyar@catalyst-eu.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface access_rule_overridable {

    /**
     * Add fields to the quiz override form.
     *
     * @param edit_override_form $quizform the quiz override settings form being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     * @return void
     */
    public static function add_override_form_fields(edit_override_form $quizform, MoodleQuickForm $mform): void;

    /**
     * Override form section header.
     *
     * Array must have three keys. The 'name' key for the name of the heading element, the 'title' key for the
     * text to display in the heading and true/false for the 'expand' key to determine if the section containing
     * these fields should be expanded.
     *
     * @return array [name, title]
     */
    public static function get_override_form_section_header(): array;

    /**
     * Determine whether the rule section should be expanded or not in the override form.
     *
     * @param edit_override_form $quizform the quiz override settings form being built.
     * @return bool return true if section should be expanded.
     */
    public static function get_override_form_section_expand(edit_override_form $quizform): bool;

    /**
     * Validate the data from any form fields added using {@see add_override_form_fields()}.
     *
     * @param array $errors the errors found so far.
     * @param array $data the submitted form data.
     * @param array $files information about any uploaded files.
     * @param edit_override_form $quizform the quiz override form object.
     * @return array the updated $errors array.
     */
    public static function validate_override_form_fields(array $errors,
        array $data, array $files, edit_override_form $quizform): array;

    /**
     * Save any submitted settings when the quiz override settings form is submitted.
     *
     * @param array $override data from the override form.
     * @return void
     */
    public static function save_override_settings(array $override): void;

    /**
     * Delete any rule-specific override settings when the quiz override is deleted.
     *
     * @param int $quizid all overrides being deleted should belong to the same quiz.
     * @param array $overrides an array of override objects to be deleted.
     * @return void
     */
    public static function delete_override_settings($quizid, $overrides): void;

    /**
     * Provide form field keys in the override form as a string array
     *
     * @return array e.g. ['rule_enabled', 'rule_password'].
     */
    public static function get_override_setting_keys(): array;

    /**
     * Provide required form field keys in the override form as a string array
     *
     * @return array e.g. ['rule_enabled'].
     */
    public static function get_override_required_setting_keys(): array;

    /**
     * Get components of the SQL query to fetch the access rule components' override
     * settings. To be used as part of a quiz_override query to reference.
     *
     * @param string $overridetablename Name of the table to reference for joins.
     * @return array [$selects, $joins, $params']
     */
    public static function get_override_settings_sql($overridetablename): array;

    /**
     * Update fields and values of the override table using the override settings.
     *
     * @param object $override the override data to use to update the $fields and $values.
     * @param array $fields the fields to populate.
     * @param array $values the fields to populate.
     * @param context $context the context of which the override is being applied to.
     * @return array [$fields, $values]
     */
    public static function add_override_table_fields($override, $fields, $values, $context): array;
}
