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

use mod_quiz\form\edit_override_form;
use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_attempt;
use quizaccess_seb\seb_access_manager;
use quizaccess_seb\seb_quiz_settings;
use quizaccess_seb\settings_provider;
use quizaccess_seb\event\access_prevented;

/**
 * Implementation of the quizaccess_seb plugin.
 *
 * @package    quizaccess_seb
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @author     Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @copyright  2019 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_seb extends access_rule_base {

    /** @var seb_access_manager $accessmanager Instance to manage the access to the quiz for this plugin. */
    private $accessmanager;

    /**
     * Create an instance of this rule for a particular quiz.
     *
     * @param \mod_quiz\quiz_settings $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param seb_access_manager $accessmanager the quiz accessmanager.
     */
    public function __construct(\mod_quiz\quiz_settings $quizobj, int $timenow, seb_access_manager $accessmanager) {
        parent::__construct($quizobj, $timenow);
        $this->accessmanager = $accessmanager;
    }

    /**
     * Return an appropriately configured instance of this rule, if it is applicable
     * to the given quiz, otherwise return null.
     *
     * @param \mod_quiz\quiz_settings $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param bool $canignoretimelimits whether the current user is exempt from
     *      time limits by the mod/quiz:ignoretimelimits capability.
     * @return access_rule_base|null the rule, if applicable, else null.
     */
    public static function make(\mod_quiz\quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        $accessmanager = new seb_access_manager($quizobj);
        // If Safe Exam Browser is not required, this access rule is not applicable.
        if (!$accessmanager->seb_required()) {
            return null;
        }

        return new self($quizobj, $timenow, $accessmanager);
    }

    /**
     * Add any fields that this rule requires to the quiz settings form. This
     * method is called from {@link mod_quiz_mod_form::definition()}, while the
     * security section is being built.
     *
     * @param mod_quiz_mod_form $quizform the quiz settings form that is being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     */
    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {
        settings_provider::add_seb_settings_fields($quizform, $mform);
    }

    /**
     * Validate the data from any form fields added using {@link add_settings_form_fields()}.
     *
     * @param array $errors the errors found so far.
     * @param array $data the submitted form data.
     * @param array $files information about any uploaded files.
     * @param mod_quiz_mod_form $quizform the quiz form object.
     * @return array $errors the updated $errors array.
     */
    public static function validate_settings_form_fields(array $errors,
                                                         array $data, $files, mod_quiz_mod_form $quizform): array {

        $quizid = $data['instance'];
        $cmid = $data['coursemodule'];
        $context = $quizform->get_context();

        if (!settings_provider::can_configure_seb($context)) {
            return $errors;
        }

        if (settings_provider::is_seb_settings_locked($quizid)) {
            return $errors;
        }

        if (settings_provider::is_conflicting_permissions($context)) {
            return $errors;
        }

        $settings = settings_provider::filter_plugin_settings((object) $data);

        // Validate basic settings using persistent class.
        $quizsettings = (new seb_quiz_settings())->from_record($settings);
        // Set non-form fields.
        $quizsettings->set('quizid', $quizid);
        $quizsettings->set('cmid', $cmid);
        $quizsettings->validate();

        // Add any errors to list.
        foreach ($quizsettings->get_errors() as $name => $error) {
            $name = settings_provider::add_prefix($name); // Re-add prefix to match form element.
            $errors[$name] = $error->out();
        }

        // Edge case for filemanager_sebconfig.
        if ($quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_UPLOAD_CONFIG) {
            $errorvalidatefile = settings_provider::validate_draftarea_configfile($data['filemanager_sebconfigfile']);
            if (!empty($errorvalidatefile)) {
                $errors['filemanager_sebconfigfile'] = $errorvalidatefile;
            }
        }

        // Edge case to force user to select a template.
        if ($quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_TEMPLATE) {
            if (empty($data['seb_templateid'])) {
                $errors['seb_templateid'] = get_string('invalidtemplate', 'quizaccess_seb');
            }
        }

        if ($quizsettings->get('requiresafeexambrowser') != settings_provider::USE_SEB_NO) {
            // Global settings may be active which require a quiz password to be set if using SEB.
            if (!empty(get_config('quizaccess_seb', 'quizpasswordrequired')) && empty($data['quizpassword'])) {
                $errors['quizpassword'] = get_string('passwordnotset', 'quizaccess_seb');
            }
        }

        return $errors;
    }

    /**
     * Save any submitted settings when the quiz settings form is submitted. This
     * is called from {@link quiz_after_add_or_update()} in lib.php.
     *
     * @param stdClass $quiz the data from the quiz form, including $quiz->id
     *      which is the id of the quiz being saved.
     */
    public static function save_settings($quiz) {
        $context = context_module::instance($quiz->coursemodule);

        if (!settings_provider::can_configure_seb($context)) {
            return;
        }

        if (settings_provider::is_seb_settings_locked($quiz->id)) {
            return;
        }

        if (settings_provider::is_conflicting_permissions($context)) {
            return;
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        $settings = settings_provider::filter_plugin_settings($quiz);
        $settings->quizid = $quiz->id;
        $settings->cmid = $cm->id;

        // Get existing settings or create new settings if none exist.
        $quizsettings = seb_quiz_settings::get_by_quiz_id($quiz->id);
        if (empty($quizsettings)) {
            $quizsettings = new seb_quiz_settings(0, $settings);
        } else {
            $settings->id = $quizsettings->get('id');
            $quizsettings->from_record($settings);
        }

        // Process uploaded files if required.
        if ($quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_UPLOAD_CONFIG) {
            $draftitemid = file_get_submitted_draft_itemid('filemanager_sebconfigfile');
            settings_provider::save_filemanager_sebconfigfile_draftarea($draftitemid, $cm->id);
        } else {
            settings_provider::delete_uploaded_config_file($cm->id);
        }

        // Save or delete settings.
        if ($quizsettings->get('requiresafeexambrowser') != settings_provider::USE_SEB_NO) {
            $quizsettings->save();
        } else if ($quizsettings->get('id')) {
            $quizsettings->delete();
        }
    }

    /**
     * Delete any rule-specific settings when the quiz is deleted. This is called
     * from {@link quiz_delete_instance()} in lib.php.
     *
     * @param stdClass $quiz the data from the database, including $quiz->id
     *      which is the id of the quiz being deleted.
     */
    public static function delete_settings($quiz) {
        $quizsettings = seb_quiz_settings::get_by_quiz_id($quiz->id);
        // Check that there are existing settings.
        if ($quizsettings !== false) {
            $quizsettings->delete();
        }
    }

    /**
     * Return the bits of SQL needed to load all the settings from all the access
     * plugins in one DB query. The easiest way to understand what you need to do
     * here is probably to read the code of {@see \mod_quiz\access_manager::load_settings()}.
     *
     * If you have some settings that cannot be loaded in this way, then you can
     * use the {@link get_extra_settings()} method instead, but that has
     * performance implications.
     *
     * @param int $quizid the id of the quiz we are loading settings for. This
     *     can also be accessed as quiz.id in the SQL. (quiz is a table alisas for {quiz}.)
     * @return array with three elements:
     *     1. fields: any fields to add to the select list. These should be alised
     *        if neccessary so that the field name starts the name of the plugin.
     *     2. joins: any joins (should probably be LEFT JOINS) with other tables that
     *        are needed.
     *     3. params: array of placeholder values that are needed by the SQL. You must
     *        used named placeholders, and the placeholder names should start with the
     *        plugin name, to avoid collisions.
     */
    public static function get_settings_sql($quizid): array {
        return [
                'seb.requiresafeexambrowser AS seb_requiresafeexambrowser, '
                . 'seb.showsebtaskbar AS seb_showsebtaskbar, '
                . 'seb.showwificontrol AS seb_showwificontrol, '
                . 'seb.showreloadbutton AS seb_showreloadbutton, '
                . 'seb.showtime AS seb_showtime, '
                . 'seb.showkeyboardlayout AS seb_showkeyboardlayout, '
                . 'seb.allowuserquitseb AS seb_allowuserquitseb, '
                . 'seb.quitpassword AS seb_quitpassword, '
                . 'seb.linkquitseb AS seb_linkquitseb, '
                . 'seb.userconfirmquit AS seb_userconfirmquit, '
                . 'seb.enableaudiocontrol AS seb_enableaudiocontrol, '
                . 'seb.muteonstartup AS seb_muteonstartup, '
                . 'seb.allowspellchecking AS seb_allowspellchecking, '
                . 'seb.allowreloadinexam AS seb_allowreloadinexam, '
                . 'seb.activateurlfiltering AS seb_activateurlfiltering, '
                . 'seb.filterembeddedcontent AS seb_filterembeddedcontent, '
                . 'seb.expressionsallowed AS seb_expressionsallowed, '
                . 'seb.regexallowed AS seb_regexallowed, '
                . 'seb.expressionsblocked AS seb_expressionsblocked, '
                . 'seb.regexblocked AS seb_regexblocked, '
                . 'seb.allowedbrowserexamkeys AS seb_allowedbrowserexamkeys, '
                . 'seb.showsebdownloadlink AS seb_showsebdownloadlink, '
                . 'sebtemplate.id AS seb_templateid '
                , 'LEFT JOIN {quizaccess_seb_quizsettings} seb ON seb.quizid = quiz.id '
                . 'LEFT JOIN {quizaccess_seb_template} sebtemplate ON seb.templateid = sebtemplate.id '
                , []
        ];
    }

    /**
     * Fetches the best suited default value for a field. If there is an override value set, use this.
     * If there's no override value, check if the quiz had SEB settings and use this value instead.
     * Otherwise, use the default value defined.
     * 
     * @param string $field The field key to search $default and $override.
     * @param string $default The default form value.
     * @param \stdClass $override The override data object.
     * @param \stdClass $quiz The quiz data object.
     * @param boolean $removeprefix Remove 'seb_' from the field key.
     * @return string
     */
    private static function get_override_default_field($field, $default, $override, $quiz, $removeprefix = false) {
        if ($removeprefix) {
            $field = substr($field, 4);
        }
        return match(true) {
            isset($override->field) => $override->field,
            isset($quiz->$field)    => $quiz->$field,
            default                 => $default,
        };
    }

    /**
     * Add fields to the quiz override form.
     *
     * @param edit_override_form $quizform the quiz override settings form being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     * @return void
     */
    public static function add_override_form_fields(
        edit_override_form $quizform, MoodleQuickForm $mform) {
        global $DB;
        $override = $DB->get_record('quizaccess_seb_override', ['overrideid' => $quizform->get_overrideid()]);
        $context = $quizform->get_context();
        $quiz = $quizform->get_quiz();

        $mform->addElement('header', 'seb', get_string('seb', 'quizaccess_seb'));

        $mform->addElement('checkbox', 'seb_enabled', get_string('enabled', 'quizaccess_seb'));
        $mform->setDefault('seb_enabled', self::get_override_default_field('enabled', false, $override, $quiz));

        // ... "Require the use of Safe Exam Browser"
        if (settings_provider::can_override_donotrequire($context)) {
            $requireseboptions[settings_provider::USE_SEB_NO] = get_string('no');
        }

        if (settings_provider::can_configure_manually($context)
            || settings_provider::is_conflicting_permissions($context)) {
            $requireseboptions[settings_provider::USE_SEB_CONFIG_MANUALLY] = get_string('seb_use_manually', 'quizaccess_seb');
        }

        if (settings_provider::can_use_seb_template($context)
            || settings_provider::is_conflicting_permissions($context)) {
            if (!empty(settings_provider::get_template_options())) {
                $requireseboptions[settings_provider::USE_SEB_TEMPLATE] = get_string('seb_use_template', 'quizaccess_seb');
            }
        }

        $requireseboptions[settings_provider::USE_SEB_CLIENT_CONFIG] = get_string('seb_use_client', 'quizaccess_seb');

        $mform->addElement(
            'select',
            'seb_requiresafeexambrowser',
            get_string('seb_requiresafeexambrowser', 'quizaccess_seb'),
            $requireseboptions
        );

        $mform->setType('seb_requiresafeexambrowser', PARAM_INT);
        $mform->setDefault(
            'seb_requiresafeexambrowser',
            self::get_override_default_field('requiresafeexambrowser', 0, $override, $quiz)
        );
        $mform->addHelpButton('seb_requiresafeexambrowser', 'seb_requiresafeexambrowser', 'quizaccess_seb');
        $mform->disabledIf('seb_requiresafeexambrowser', 'enableseboverride');

        if (settings_provider::is_conflicting_permissions($context)) {
            $mform->freeze('seb_requiresafeexambrowser');
        }

        // ... "Safe Exam Browser config template"
        if (settings_provider::can_use_seb_template($context) ||
            settings_provider::is_conflicting_permissions($context)) {
            $element = $mform->addElement(
                'select',
                'seb_templateid',
                get_string('seb_templateid', 'quizaccess_seb'),
                settings_provider::get_template_options()
            );
        } else {
            $element = $mform->addElement('hidden', 'seb_templateid');
        }

        $mform->setType('seb_templateid', PARAM_INT);
        $mform->setDefault('seb_templateid', self::get_override_default_field('templateid', 0, $override, $quiz));
        $mform->addHelpButton('seb_templateid', 'seb_templateid', 'quizaccess_seb');
        $mform->disabledIf('seb_templateid', 'enableseboverride');

        if (settings_provider::is_conflicting_permissions($context)) {
            $mform->freeze('seb_templateid');
        }

        // ... "Show Safe Exam browser download button"
        if (settings_provider::can_change_seb_showsebdownloadlink($context)) {
            $mform->addElement('selectyesno',
                'seb_showsebdownloadlink',
                get_string('seb_showsebdownloadlink', 'quizaccess_seb')
            );

            $mform->setType('seb_showsebdownloadlink', PARAM_BOOL);
            $mform->setDefault('seb_showsebdownloadlink',
                self::get_override_default_field('showsebdownloadlink', 1, $override, $quiz));
            $mform->addHelpButton('seb_showsebdownloadlink', 'seb_showsebdownloadlink', 'quizaccess_seb');
            $mform->disabledIf('seb_showsebdownloadlink', 'enableseboverride');
        }

        // Manual config elements.
        $defaults = settings_provider::get_seb_config_element_defaults();
        $types = settings_provider::get_seb_config_element_types();

        foreach (settings_provider::get_seb_config_elements() as $name => $type) {
            if (!settings_provider::can_manage_seb_config_setting($name, $context)) {
                $type = 'hidden';
            }

            $mform->addElement($type, $name, get_string($name, 'quizaccess_seb'));

            $mform->addHelpButton($name, $name, 'quizaccess_seb');
            $mform->setType('seb_showsebdownloadlink', PARAM_BOOL);
            $mform->setDefault('seb_showsebdownloadlink',
                self::get_override_default_field('showsebdownloadlink', 1, $override, $quiz));
            $mform->disabledIf($name, 'enableseboverride');

            if (isset($defaults[$name])) {
                $mform->setDefault($name,
                    self::get_override_default_field($name, $defaults[$name], $override, $quiz, true));
            }

            if (isset($types[$name])) {
                $mform->setType($name, $types[$name]);
            }
        }

        if (settings_provider::can_change_seb_allowedbrowserexamkeys($context)) {
            $mform->addElement('textarea',
                'seb_allowedbrowserexamkeys',
                get_string('seb_allowedbrowserexamkeys', 'quizaccess_seb')
            );

            $mform->setType('seb_allowedbrowserexamkeys', PARAM_RAW);
            $mform->setDefault('seb_allowedbrowserexamkeys',
                self::get_override_default_field('allowedbrowserexamkeys', '', $override, $quiz));
            $mform->addHelpButton('seb_allowedbrowserexamkeys', 'seb_allowedbrowserexamkeys', 'quizaccess_seb');
            $mform->disabledIf('seb_allowedbrowserexamkeys', 'enableseboverride');
        }

        // Hideifs.
        foreach (settings_provider::get_quiz_hideifs() as $elname => $rules) {
            if ($mform->elementExists($elname)) {
                foreach ($rules as $hideif) {
                    $mform->hideIf(
                        $hideif->get_element(),
                        $hideif->get_dependantname(),
                        $hideif->get_condition(),
                        $hideif->get_dependantvalue()
                    );
                }
            }
        }

        // Lock elements.
        if (settings_provider::is_conflicting_permissions($context)) {
            // Freeze common quiz settings.
            $mform->addElement('enableseboverride');
            $mform->freeze('seb_requiresafeexambrowser');
            $mform->freeze('seb_templateid');
            $mform->freeze('seb_showsebdownloadlink');
            $mform->freeze('seb_allowedbrowserexamkeys');

            $quizsettings = seb_quiz_settings::get_by_quiz_id((int) $this->quiz->id);

            // Remove template ID if not using template for this quiz.
            if (empty($quizsettings) || $quizsettings->get('requiresafeexambrowser') != settings_provider::USE_SEB_TEMPLATE) {
                $mform->removeElement('seb_templateid');
            }

            // Freeze all SEB specific settings.
            foreach (settings_provider::get_seb_config_elements() as $element => $type) {
                if ($mform->elementExists($element)) {
                    $mform->freeze($element);
                }
            }
        }
    }


    /**
     * Validate the data from any form fields added using {@see add_override_form_fields()}.
     *
     * @param array $errors the errors found so far.
     * @param array $data the submitted form data.
     * @param array $files information about any uploaded files.
     * @param edit_override_form $quizform the quiz override form object.
     * @return array $errors the updated $errors array.
     */
    public static function validate_override_form_fields(array $errors,
            array $data, $files, edit_override_form $quizform) {
        $context = $quizform->get_context();
        $cmid = $context->instanceid;
        $quizid = get_module_from_cmid($cmid)[0]->id;

        if (!settings_provider::can_configure_seb($context)) {
            return $errors;
        }

        if (settings_provider::is_seb_settings_locked($quizid)) {
            return $errors;
        }

        if (settings_provider::is_conflicting_permissions($context)) {
            return $errors;
        }

        $settings = settings_provider::filter_plugin_settings((object) $data);

        // Validate basic settings using persistent class.
        $quizsettings = (new seb_quiz_settings())->from_record($settings);
        $quizsettings->set('cmid', $cmid);
        $quizsettings->set('quizid', $quizid);

        // Edge case for filemanager_sebconfig.
        if ($quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_UPLOAD_CONFIG) {
            $errorvalidatefile = settings_provider::validate_draftarea_configfile($data['filemanager_sebconfigfile']);
            if (!empty($errorvalidatefile)) {
                $errors['filemanager_sebconfigfile'] = $errorvalidatefile;
            }
        }

        // Edge case to force user to select a template.
        if ($quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_TEMPLATE) {
            if (empty($data['seb_templateid'])) {
                $errors['seb_templateid'] = get_string('invalidtemplate', 'quizaccess_seb');
            }
        }

        if ($quizsettings->get('requiresafeexambrowser') != settings_provider::USE_SEB_NO) {
            // Global settings may be active which require a quiz password to be set if using SEB.
            if (!empty(get_config('quizaccess_seb', 'quizpasswordrequired')) && empty($data['quizpassword'])) {
                $errors['quizpassword'] = get_string('passwordnotset', 'quizaccess_seb');
            }
        }

        return $errors;
    }

    /**
     * Save any submitted settings when the quiz override settings form is submitted.
     *
     * @param array $override data from the override form.
     * @return void
     */
    public static function save_override_settings($override) {
        global $DB, $USER;

        $defaults = [
            'seb_templateid' => 0,
            'seb_allowedbrowserexamkeys' => '',
            'seb_showsebdownloadlink' => 1,
        ];
        $defaults += settings_provider::get_seb_config_element_defaults();

        foreach ($defaults as $key => $default) {
            if (!isset($override[$key])) {
                $override[$key] = $default;
            }
        }

        $seboverride = (object)[
            'overrideid'             => $override['overrideid'],
            'enabled'                => $override['seb_enabled'],
            'templateid'             => $override['seb_templateid'],
            'requiresafeexambrowser' => $override['seb_requiresafeexambrowser'],
            'showsebtaskbar'         => $override['seb_showsebtaskbar'],
            'showwificontrol'        => $override['seb_showwificontrol'],
            'showreloadbutton'       => $override['seb_showreloadbutton'],
            'showtime'               => $override['seb_showtime'],
            'showkeyboardlayout'     => $override['seb_showkeyboardlayout'],
            'allowuserquitseb'       => $override['seb_allowuserquitseb'],
            'quitpassword'           => $override['seb_quitpassword'],
            'linkquitseb'            => $override['seb_linkquitseb'],
            'userconfirmquit'        => $override['seb_userconfirmquit'],
            'enableaudiocontrol'     => $override['seb_enableaudiocontrol'],
            'muteonstartup'          => $override['seb_muteonstartup'],
            'allowspellchecking'     => $override['seb_allowspellchecking'],
            'allowreloadinexam'      => $override['seb_allowreloadinexam'],
            'activateurlfiltering'   => $override['seb_activateurlfiltering'],
            'filterembeddedcontent'  => $override['seb_filterembeddedcontent'],
            'expressionsallowed'     => $override['seb_expressionsallowed'],
            'regexallowed'           => $override['seb_regexallowed'],
            'expressionsblocked'     => $override['seb_expressionsblocked'],
            'regexblocked'           => $override['seb_regexblocked'],
            'allowedbrowserexamkeys' => $override['seb_allowedbrowserexamkeys'],
            'showsebdownloadlink'    => $override['seb_showsebdownloadlink'],
            'usermodified'           => $USER->id,
            'timemodified'           => time(),
        ];

        if ($seboverrideid = $DB->get_field('quizaccess_seb_override', 'id', ['overrideid' => $override['overrideid']])) {
            $seboverride->id = $seboverrideid;
            $DB->update_record('quizaccess_seb_override', $seboverride);
        } else {
            $seboverride->timecreated = time();
            $DB->insert_record('quizaccess_seb_override', $seboverride);
        }

        // Delete cache.
        $quizid = $DB->get_field('quiz_overrides', 'quiz', ['id' => $override['overrideid']]);
        seb_quiz_settings::delete_cache("$quizid-{$override['overrideid']}");
    }

    /**
     * Delete any rule-specific override settings when the quiz override is deleted.
     *
     * @param array $overrides an array of override objects to be deleted.
     * @return void
     */
    public static function delete_override_settings($overrides) {
        global $DB;
        $ids = array_column($overrides, 'id');
        list($insql, $inparams) = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('quizaccess_seb_override', "id $insql", $inparams);

        foreach ($overrides as $override) {
            $key = "{$override->quiz}-{$override->id}";
            seb_quiz_settings::delete_cache($key);
        }
    }

    /**
     * Provide form field keys in the override form as a string array
     * e.g. ['rule_enabled', 'rule_password'].
     *
     * @return array
     */
    public static function get_override_setting_keys() {
        return [
            'seb_enabled',
            'seb_templateid',
            'seb_requiresafeexambrowser',
            'seb_showsebtaskbar',
            'seb_showwificontrol',
            'seb_showreloadbutton',
            'seb_showtime',
            'seb_showkeyboardlayout',
            'seb_allowuserquitseb',
            'seb_quitpassword',
            'seb_linkquitseb',
            'seb_userconfirmquit',
            'seb_enableaudiocontrol',
            'seb_muteonstartup',
            'seb_allowspellchecking',
            'seb_allowreloadinexam',
            'seb_activateurlfiltering',
            'seb_filterembeddedcontent',
            'seb_expressionsallowed',
            'seb_regexallowed',
            'seb_expressionsblocked',
            'seb_regexblocked',
            'seb_allowedbrowserexamkeys',
            'seb_showsebdownloadlink',
        ];
    }

    /**
     * Provide required form field keys in the override form as a string array
     * e.g. ['rule_enabled'].
     *
     * @return array
     */
    public static function get_override_required_setting_keys() {
        return ['seb_enabled'];
    }

    /**
     * Get components of the SQL query to fetch the access rule components' override
     * settings. To be used as part of a quiz_override query to reference.
     *
     * @param string $overridetablename Name of the table to reference for joins.
     * @return array 'selects', 'joins' and 'params'.
     */
    public static function get_override_settings_sql($overridetablename) {
        return [
            'seb.enabled seb_enabled,seb.templateid seb_templateid,seb.requiresafeexambrowser seb_requiresafeexambrowser,
                seb.showsebtaskbar seb_showsebtaskbar,seb.showwificontrol seb_showwificontrol,
                seb.showreloadbutton seb_showreloadbutton,seb.showtime seb_showtime,seb.showkeyboardlayout seb_showkeyboardlayout,
                seb.allowuserquitseb seb_allowuserquitseb,seb.quitpassword seb_quitpassword,seb.linkquitseb seb_linkquitseb,
                seb.userconfirmquit seb_userconfirmquit,seb.enableaudiocontrol seb_enableaudiocontrol,
                seb.muteonstartup seb_muteonstartup,seb.allowspellchecking seb_allowspellchecking,
                seb.allowreloadinexam seb_allowreloadinexam,seb.activateurlfiltering seb_activateurlfiltering,
                seb.filterembeddedcontent seb_filterembeddedcontent,seb.expressionsallowed seb_expressionsallowed,
                seb.regexallowed seb_regexallowed,seb.expressionsblocked seb_expressionsblocked,seb.regexblocked seb_regexblocked,
                seb.allowedbrowserexamkeys seb_allowedbrowserexamkeys,seb.showsebdownloadlink seb_showsebdownloadlink',
            "JOIN {quizaccess_seb_override} seb ON seb.overrideid = $overridetablename.id",
            [],
        ];
    }

    /**
     * Update fields and values of the override table using the override settings.
     *
     * @param object $override the override data to use to update the $fields and $values.
     * @param array $fields the fields to populate.
     * @param array $values the fields to populate.
     * @param context $context the context of which the override is being applied to.
     * @return array
     */
    public static function add_override_table_fields($override, $fields, $values, $context) {
        if (isset($override->seb_enabled) && !empty($override->seb_enabled)) {
            $fields[] = get_string('seb_requiresafeexambrowser', 'quizaccess_seb');
            $values[] = settings_provider::get_requiresafeexambrowser_options($context)[$override->seb_requiresafeexambrowser];
        }
        return [$fields, $values];
    }

    /**
     * Whether the user should be blocked from starting a new attempt or continuing
     * an attempt now.
     *
     * @return string false if access should be allowed, a message explaining the
     *      reason if access should be prevented.
     */
    public function prevent_access() {
        global $PAGE;

        if (!$this->accessmanager->seb_required()) {
            return false;
        }

        if ($this->accessmanager->can_bypass_seb()) {
            return false;
        }

        // If the rule is active, enforce a secure view whilst taking the quiz.
        $PAGE->set_pagelayout('secure');
        $this->prevent_display_blocks();

        // Access has previously been validated for this session and quiz.
        if ($this->accessmanager->validate_session_access()) {
            return false;
        }

        if (!$this->accessmanager->validate_basic_header()) {
            access_prevented::create_strict($this->accessmanager, $this->get_reason_text('not_seb'))->trigger();
            return $this->get_require_seb_error_message();
        }

        if (!$this->accessmanager->validate_config_key()) {
            if ($this->accessmanager->should_redirect_to_seb_config_link()) {
                $this->accessmanager->redirect_to_seb_config_link();
            }

            access_prevented::create_strict($this->accessmanager, $this->get_reason_text('invalid_config_key'))->trigger();
            return $this->get_invalid_key_error_message();
        }

        if (!$this->accessmanager->validate_browser_exam_key()) {
            access_prevented::create_strict($this->accessmanager, $this->get_reason_text('invalid_browser_key'))->trigger();
            return $this->get_invalid_key_error_message();
        }

        // Set the state of the access for this Moodle session.
        $this->accessmanager->set_session_access(true);

        return false;
    }

    /**
     * Returns a list of finished attempts for the current user.
     *
     * @return array
     */
    private function get_user_finished_attempts(): array {
        global $USER;

        return quiz_get_user_attempts(
            $this->quizobj->get_quizid(),
            $USER->id,
            quiz_attempt::FINISHED,
            false
        );
    }

    /**
     * Prevent block displaying as configured.
     */
    private function prevent_display_blocks() {
        global $PAGE;

        if ($PAGE->has_set_url() && $PAGE->url == $this->quizobj->view_url()) {
            $attempts = $this->get_user_finished_attempts();

            // Don't display blocks before starting an attempt.
            if (empty($attempts) && !get_config('quizaccess_seb', 'displayblocksbeforestart')) {
                $PAGE->blocks->show_only_fake_blocks();
            }

            // Don't display blocks after finishing an attempt.
            if (!empty($attempts) && !get_config('quizaccess_seb', 'displayblockswhenfinished')) {
                $PAGE->blocks->show_only_fake_blocks();
            }
        }
    }

    /**
     * Returns reason for access prevention as a text.
     *
     * @param string $identifier Reason string identifier.
     * @return string
     */
    private function get_reason_text(string $identifier): string {
        if (in_array($identifier, ['not_seb', 'invalid_config_key', 'invalid_browser_key'])) {
            return get_string($identifier, 'quizaccess_seb');
        }

        return get_string('unknown_reason', 'quizaccess_seb');
    }

    /**
     * Return error message when a SEB key is not valid.
     *
     * @return string
     */
    private function get_invalid_key_error_message(): string {
        // Return error message with download link and links to get the seb config.
        return get_string('invalidkeys', 'quizaccess_seb')
            . $this->display_buttons($this->get_action_buttons());
    }

    /**
     * Return error message when a SEB browser is not used.
     *
     * @return string
     */
    private function get_require_seb_error_message(): string {
        $message = get_string('clientrequiresseb', 'quizaccess_seb');

        if ($this->should_display_download_seb_link()) {
            $message .= $this->display_buttons($this->get_download_seb_button());
        }

        // Return error message with download link.
        return $message;
    }

    /**
     * Helper function to display an Exit Safe Exam Browser button if configured to do so and attempts are > 0.
     *
     * @return string empty or a button which has the configured seb quit link.
     */
    private function get_quit_button(): string {
        $quitbutton = '';

        if (empty($this->get_user_finished_attempts())) {
            return $quitbutton;
        }

        // Only display if the link has been configured and attempts are greater than 0.
        if (!empty($this->quiz->seb_linkquitseb)) {
            $quitbutton = html_writer::link(
                $this->quiz->seb_linkquitseb,
                get_string('exitsebbutton', 'quizaccess_seb'),
                ['class' => 'btn btn-secondary']
            );
        }

        return $quitbutton;
    }

    /**
     * Information, such as might be shown on the quiz view page, relating to this restriction.
     * There is no obligation to return anything. If it is not appropriate to tell students
     * about this rule, then just return ''.
     *
     * @return mixed a message, or array of messages, explaining the restriction
     *         (may be '' if no message is appropriate).
     */
    public function description(): array {
        global $PAGE;

        $messages = [get_string('sebrequired', 'quizaccess_seb')];

        // Display download SEB config link for those who can bypass using SEB.
        if ($this->accessmanager->can_bypass_seb() && $this->accessmanager->should_validate_config_key()) {
            $messages[] = $this->display_buttons($this->get_download_config_button());
        }

        // Those with higher level access will be able to see the button if they've made an attempt.
        if (!$this->prevent_access()) {
            $messages[] = $this->display_buttons($this->get_quit_button());
        } else {
            $PAGE->requires->js_call_amd('quizaccess_seb/validate_quiz_access', 'init',
                [$this->quiz->cmid, (bool)get_config('quizaccess_seb', 'autoreconfigureseb')]);
        }

        return $messages;
    }

    /**
     * Sets up the attempt (review or summary) page with any special extra
     * properties required by this rule.
     *
     * @param moodle_page $page the page object to initialise.
     */
    public function setup_attempt_page($page) {
        $page->set_title($this->quizobj->get_course()->shortname . ': ' . $page->title);
        $page->set_popup_notification_allowed(false); // Prevent message notifications.
        $page->set_heading($page->title);
        $page->set_pagelayout('secure');
    }

    /**
     * This is called when the current attempt at the quiz is finished.
     */
    public function current_attempt_finished() {
        $this->accessmanager->clear_session_access();
    }

    /**
     * Prepare buttons HTML code for being displayed on the screen.
     *
     * @param string $buttonshtml Html string of the buttons.
     * @param string $class Optional CSS class (or classes as space-separated list)
     * @param array $attributes Optional other attributes as array
     *
     * @return string HTML code of the provided buttons.
     */
    private function display_buttons(string $buttonshtml, $class = '', ?array $attributes = null): string {
        $html = '';

        if (!empty($buttonshtml)) {
            $html = html_writer::div($buttonshtml, $class, $attributes);
        }

        return $html;
    }

    /**
     * Get buttons to prompt user to download SEB or config file or launch SEB.
     *
     * @return string Html block of all action buttons.
     */
    private function get_action_buttons(): string {
        $buttons = '';

        if ($this->should_display_download_seb_link()) {
            $buttons .= $this->get_download_seb_button();
        }

        // Get config for displaying links.
        $linkconfig = explode(',', get_config('quizaccess_seb', 'showseblinks'));

        // Display links to download config/launch SEB only if required.
        if ($this->accessmanager->should_validate_config_key()) {
            if (in_array('seb', $linkconfig)) {
                $buttons .= $this->get_launch_seb_button();
            }

            if (in_array('http', $linkconfig)) {
                $buttons .= $this->get_download_config_button();
            }
        }

        return $buttons;
    }

    /**
     * Get a button to download SEB.
     *
     * @return string A link to download SafeExam Browser.
     */
    private function get_download_seb_button(): string {
        global $OUTPUT;

        $button = '';

        if (!empty($this->get_seb_download_url())) {
            $button = $OUTPUT->single_button($this->get_seb_download_url(), get_string('sebdownloadbutton', 'quizaccess_seb'));
        }

        return $button;
    }

    /**
     * Get a button to launch Safe Exam Browser.
     *
     * @return string A link to launch Safe Exam Browser.
     */
    private function get_launch_seb_button(): string {
        // Rendering as a href and not as button in a form to circumvent browser warnings for sending to URL with unknown protocol.
        $seblink = \quizaccess_seb\link_generator::get_link($this->quiz->cmid, true, is_https());

        $buttonlink = html_writer::start_tag('div', ['class' => 'singlebutton']);
        $buttonlink .= html_writer::link($seblink, get_string('seblinkbutton', 'quizaccess_seb'),
            ['class' => 'btn btn-secondary', 'title' => get_string('seblinkbutton', 'quizaccess_seb')]);
        $buttonlink .= html_writer::end_tag('div');

        return $buttonlink;
    }

    /**
     * Get a button to download Safe Exam Browser config.
     *
     * @return string A link to launch Safe Exam Browser.
     */
    private function get_download_config_button(): string {
        // Rendering as a href and not as button in a form to circumvent browser warnings for sending to URL with unknown protocol.
        $httplink = \quizaccess_seb\link_generator::get_link($this->quiz->cmid, false, is_https());

        $buttonlink = html_writer::start_tag('div', ['class' => 'singlebutton']);
        $buttonlink .= html_writer::link($httplink, get_string('httplinkbutton', 'quizaccess_seb'),
            ['class' => 'btn btn-secondary', 'title' => get_string('httplinkbutton', 'quizaccess_seb')]);
        $buttonlink .= html_writer::end_tag('div');

        return $buttonlink;
    }

    /**
     * Returns SEB download URL.
     *
     * @return string
     */
    private function get_seb_download_url(): string {
        return get_config('quizaccess_seb', 'downloadlink');
    }

    /**
     * Check if we should display a link to download Safe Exam Browser.
     *
     * @return bool
     */
    private function should_display_download_seb_link(): bool {
        return !empty($this->quiz->seb_showsebdownloadlink);
    }
}
