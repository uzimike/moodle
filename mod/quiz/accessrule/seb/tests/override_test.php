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

use mod_quiz\quiz_settings;
use quizaccess_seb\helper;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/test_helper_trait.php');

/**
 * Tests for Safe Exam Browser access rules
 *
 * @package    quizaccess_seb
 * @copyright  2024 Michael Kotlyar <michael.kotlyar@catalyst-eu.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class override_test extends \advanced_testcase {

    use \quizaccess_seb_test_helper_trait;

    /**
     * Test quiz override for SEB configs.
     */
    public function test_override_over_no_seb(): void {
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $overrideuser = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);
        $this->getDataGenerator()->enrol_user($overrideuser->id, $this->course->id);

        // Make a quiz.
        $this->setAdminUser();
        $this->quiz = $this->create_test_quiz($this->course);

        // Check SEB settings for user (no SEB settings).
        $this->setUser($this->user);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);

        // Check SEB settings for overrideuser (no SEB settings).
        $this->setUser($overrideuser);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);

        // Create an override for overrideuser.
        $this->setAdminUser();
        $this->save_override($overrideuser);

        // Check SEB settings for user (still no SEB settings).
        $this->setUser($this->user);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);
    }

    /**
     * Test quiz override for SEB configs.
     * 
     * The same test as test_override_over_no_seb, but repeating the retieval of the settings to test if any caching is interferring
     * with getting the right settings.
     */
    public function test_cached_override_over_no_seb(): void {
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $overrideuser = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);
        $this->getDataGenerator()->enrol_user($overrideuser->id, $this->course->id);

        // Make a quiz.
        $this->setAdminUser();
        $this->quiz = $this->create_test_quiz($this->course);

        // Check SEB settings for user (no SEB settings).
        $this->setUser($this->user);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);

        // Check SEB settings for overrideuser (no SEB settings).
        $this->setUser($overrideuser);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);

        // Create an override for overrideuser.
        $this->setAdminUser();
        $this->save_override($overrideuser);

        // Check SEB settings for user (still no SEB settings).
        $this->setUser($this->user);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);
        
        // Check SEB settings for overrideuser.
        $this->setUser($overrideuser);
        $sebconfig = helper::get_seb_config_content($this->quiz->cmid);
        $this->assertIsString($sebconfig);

        // Cache should be set.
        
        // Checking SEB configs again.
        // Check SEB settings for user (still no SEB settings).
        $this->setUser($this->user);
        $raised = false;
        try {
            helper::get_seb_config_content($this->quiz->cmid);
        } catch (\moodle_exception $e) {
            $raised = true;
            $this->assertMatchesRegularExpression(
                '@' . 'No SEB config could be found for quiz with cmid: ' . $this->quiz->cmid . '@', 
                $e->getMessage()
            );
        }
        $this->assertTrue($raised);
        
        // Check SEB settings for overrideuser.
        $this->setUser($overrideuser);
        $sebconfig = helper::get_seb_config_content($this->quiz->cmid);
        $this->assertIsString($sebconfig);
    }

    /**
     * Test quiz override for SEB configs.
     */
    public function test_override_settings_changed(): void {
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $normaluser = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);
        $this->getDataGenerator()->enrol_user($normaluser->id, $this->course->id);

        // Make a quiz.
        $this->setAdminUser();
        $this->quiz = $this->create_test_quiz($this->course, settings_provider::USE_SEB_CONFIG_MANUALLY);

        // Check SEB settings for user.
        $this->setUser($this->user);
        $sebconfig = seb_quiz_settings::get_by_quiz_id($this->quiz->id);

        $this->assertEquals(1, $sebconfig->get('requiresafeexambrowser'));
        $this->assertEquals(1, $sebconfig->get('showsebtaskbar'));
        $this->assertEquals(0, $sebconfig->get('showwificontrol'));
        $this->assertEquals(1, $sebconfig->get('showreloadbutton'));
        $this->assertEquals(1, $sebconfig->get('showtime'));
        $this->assertEquals(1, $sebconfig->get('showkeyboardlayout'));
        $this->assertEquals(1, $sebconfig->get('allowuserquitseb'));
        $this->assertEquals('', $sebconfig->get('quitpassword'));
        $this->assertEquals('', $sebconfig->get('linkquitseb'));
        $this->assertEquals(1, $sebconfig->get('userconfirmquit'));
        $this->assertEquals(0, $sebconfig->get('enableaudiocontrol'));
        $this->assertEquals(0, $sebconfig->get('muteonstartup'));
        $this->assertEquals(0, $sebconfig->get('allowspellchecking'));
        $this->assertEquals(1, $sebconfig->get('allowreloadinexam'));
        $this->assertEquals(0, $sebconfig->get('activateurlfiltering'));
        $this->assertEquals(0, $sebconfig->get('filterembeddedcontent'));
        $this->assertEquals('', $sebconfig->get('expressionsallowed'));
        $this->assertEquals('', $sebconfig->get('regexallowed'));
        $this->assertEquals('', $sebconfig->get('expressionsblocked'));
        $this->assertEquals('', $sebconfig->get('regexblocked'));
        $this->assertEquals(1, $sebconfig->get('showsebdownloadlink'));
        $this->assertEquals([], $sebconfig->get('allowedbrowserexamkeys'));

        // Test cache.
        $quizconfigkey = \cache::make('quizaccess_seb', 'configkey')->get($this->quiz->id);
        $this->assertEquals("dc355fc028396158b14d3f383bdc1388039f5749d4d8b15242b243998b4f230f", $quizconfigkey);

        $loadedquizconfigkey = $sebconfig::get_config_key_by_quiz_id($this->quiz->id);
        $this->assertEquals($loadedquizconfigkey, $quizconfigkey);

        // Override.
        $this->setAdminUser();
        $overrideid = $this->save_override($this->user, [
            'seb_requiresafeexambrowser' => 1,
            'seb_showsebtaskbar' => 0,
            'seb_showwificontrol' => 1,
            'seb_showreloadbutton' => 0,
            'seb_showtime' => 0,
            'seb_showkeyboardlayout' => 0,
            'seb_allowuserquitseb' => 0,
            'seb_quitpassword' => 'test',
            'seb_linkquitseb' => 'https://example.com/quit',
            'seb_userconfirmquit' => 0,
            'seb_enableaudiocontrol' => 1,
            'seb_muteonstartup' => 1,
            'seb_allowspellchecking' => 1,
            'seb_allowreloadinexam' => 0,
            'seb_activateurlfiltering' => 1,
            'seb_filterembeddedcontent' => 1,
            'seb_expressionsallowed' => 'test.com',
            'seb_regexallowed' => '^allow$',
            'seb_expressionsblocked' => 'bad.com',
            'seb_regexblocked' => '^bad$',
            'seb_showsebdownloadlink' => 0,
        ]);

        // Test settings are overriden.
        $this->setUser($this->user);
        $sebconfig = seb_quiz_settings::get_by_quiz_id($this->quiz->id);

        $this->assertEquals(1, $sebconfig->get('requiresafeexambrowser'));
        $this->assertEquals(0, $sebconfig->get('showsebtaskbar'));
        $this->assertEquals(1, $sebconfig->get('showwificontrol'));
        $this->assertEquals(0, $sebconfig->get('showreloadbutton'));
        $this->assertEquals(0, $sebconfig->get('showtime'));
        $this->assertEquals(0, $sebconfig->get('showkeyboardlayout'));
        $this->assertEquals(0, $sebconfig->get('allowuserquitseb'));
        $this->assertEquals('test', $sebconfig->get('quitpassword'));
        $this->assertEquals('https://example.com/quit', $sebconfig->get('linkquitseb'));
        $this->assertEquals(0, $sebconfig->get('userconfirmquit'));
        $this->assertEquals(1, $sebconfig->get('enableaudiocontrol'));
        $this->assertEquals(1, $sebconfig->get('muteonstartup'));
        $this->assertEquals(1, $sebconfig->get('allowspellchecking'));
        $this->assertEquals(0, $sebconfig->get('allowreloadinexam'));
        $this->assertEquals(1, $sebconfig->get('activateurlfiltering'));
        $this->assertEquals(1, $sebconfig->get('filterembeddedcontent'));
        $this->assertEquals('test.com', $sebconfig->get('expressionsallowed'));
        $this->assertEquals('^allow$', $sebconfig->get('regexallowed'));
        $this->assertEquals('bad.com', $sebconfig->get('expressionsblocked'));
        $this->assertEquals('^bad$', $sebconfig->get('regexblocked'));
        $this->assertEquals(0, $sebconfig->get('showsebdownloadlink'));
        $this->assertEquals([], $sebconfig->get('allowedbrowserexamkeys'));

        // Test override cache.
        $configid = $sebconfig->get('id');
        $cachekey = "{$this->quiz->id}-$configid";
        $quizconfigkey = \cache::make('quizaccess_seb', 'configkey')->get($cachekey);
        $this->assertEquals("dc355fc028396158b14d3f383bdc1388039f5749d4d8b15242b243998b4f230f", $quizconfigkey);

        $loadedquizconfigkey = $sebconfig::get_config_key_by_quiz_id($this->quiz->id);
        $this->assertEquals($loadedquizconfigkey, $quizconfigkey);

    }

    /**
     * Create initial SEB settings data for quiz_override db table.
     *
     * @param bool|Array $settings Override settings with this array
     * @return string
     */
    private function save_override($user = false, $settings = false) {
        $user = $user ?: $this->user;

        $initialsettings = [
            'seb_enabled' => '1',
            'seb_requiresafeexambrowser' => '1',
            'seb_showsebtaskbar' => '1',
            'seb_showwificontrol' => '0',
            'seb_showreloadbutton' => '1',
            'seb_showtime' => '0',
            'seb_showkeyboardlayout' => '1',
            'seb_allowuserquitseb' => '1',
            'seb_quitpassword' => 'test',
            'seb_linkquitseb' => '',
            'seb_userconfirmquit' => '1',
            'seb_enableaudiocontrol' => '1',
            'seb_muteonstartup' => '0',
            'seb_allowspellchecking' => '0',
            'seb_allowreloadinexam' => '1',
            'seb_activateurlfiltering' => '1',
            'seb_filterembeddedcontent' => '0',
            'seb_expressionsallowed' => 'test.com',
            'seb_regexallowed' => '',
            'seb_expressionsblocked' => '',
            'seb_regexblocked' => '',
            'seb_showsebdownloadlink' => '1',
        ];

        if (is_array($settings)) {
            $initialsettings = array_merge($initialsettings, $settings);
        }

        $quizobj = quiz_settings::create($this->quiz->id);
        $manager = $quizobj->get_override_manager();
        return $manager->save_override([
            'userid' => $user->id,
            ...$initialsettings,
        ]);
    }
}
