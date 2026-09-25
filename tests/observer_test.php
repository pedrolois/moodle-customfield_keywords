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

namespace customfield_keywords;

use core_customfield\field_controller;

/**
 * Tests that managing keywords on /tag/manage.php is reflected on every course, like core tags.
 *
 * Each test checks the three places a keyword list can be read from: the JSON mirror in
 * customfield_data.value, get_value() (web services, backup) and the course edit form.
 *
 * @package   customfield_keywords
 * @author    Pedro Luis Garcia Leiva
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \customfield_keywords\observer
 * @covers    \customfield_keywords\data_controller::sync_value_from_tags
 */
final class observer_test extends \advanced_testcase {
    /** @var field_controller the Keywords field under test */
    protected $field;

    /** @var \stdClass[] courses to attach keywords to */
    protected $courses = [];

    /** @var int the Keywords tag collection */
    protected $tagcollid;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category();
        $this->field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'keywords',
            'shortname' => 'kw',
        ]);
        $this->courses[1] = $this->getDataGenerator()->create_course();
        $this->courses[2] = $this->getDataGenerator()->create_course();
        $this->tagcollid = \core_tag_area::get_collection(data_controller::TAG_COMPONENT, data_controller::TAG_ITEMTYPE);
    }

    /**
     * Save keywords through the course handler, as the course edit form does.
     *
     * @param int $courseid
     * @param array $keywords
     */
    protected function save_keywords(int $courseid, array $keywords): void {
        \core_course\customfield\course_handler::create()->instance_form_save(
            (object) ['id' => $courseid, 'customfield_kw' => $keywords]
        );
    }

    /**
     * Get the persisted data controller for a course.
     *
     * @param int $courseid
     * @return data_controller
     */
    protected function get_data(int $courseid): data_controller {
        $fieldid = $this->field->get('id');
        return \core_customfield\api::get_instance_fields_data([$fieldid => $this->field], $courseid)[$fieldid];
    }

    /**
     * Assert every reader of a course's keywords sees exactly the expected list.
     *
     * @param array $expected
     * @param int $courseid
     */
    protected function assert_keywords(array $expected, int $courseid): void {
        global $DB;

        $data = $this->get_data($courseid);
        $instance = new \stdClass();
        $data->instance_form_before_set_data($instance);

        $readers = [
            'mirror' => json_decode($DB->get_field('customfield_data', 'value', ['id' => $data->get('id')]), true),
            'get_value' => json_decode($data->get_value(), true),
            'edit form' => $instance->customfield_kw,
        ];
        sort($expected);
        foreach ($readers as $reader => $keywords) {
            sort($keywords);
            $this->assertEquals($expected, $keywords, "Keywords read through $reader");
        }
    }

    public function test_deleting_keyword_removes_it_from_every_course(): void {
        $this->save_keywords($this->courses[1]->id, ['test', 'AI Governance']);
        $this->save_keywords($this->courses[2]->id, ['test']);

        \core_tag_tag::delete_tags([\core_tag_tag::get_by_name($this->tagcollid, 'test')->id]);

        $this->assert_keywords(['AI Governance'], $this->courses[1]->id);
        $this->assert_keywords([], $this->courses[2]->id);
    }

    public function test_deleted_keyword_is_not_recreated_when_course_is_saved_again(): void {
        $this->save_keywords($this->courses[1]->id, ['test', 'AI Governance']);
        \core_tag_tag::delete_tags([\core_tag_tag::get_by_name($this->tagcollid, 'test')->id]);

        // Resubmit the edit form with whatever it was prefilled with.
        $instance = new \stdClass();
        $this->get_data($this->courses[1]->id)->instance_form_before_set_data($instance);
        $this->save_keywords($this->courses[1]->id, $instance->customfield_kw);

        $this->assertFalse(\core_tag_tag::get_by_name($this->tagcollid, 'test'));
        $this->assert_keywords(['AI Governance'], $this->courses[1]->id);
    }

    public function test_renaming_keyword_updates_every_course(): void {
        $this->save_keywords($this->courses[1]->id, ['Old name', 'Other']);
        $this->save_keywords($this->courses[2]->id, ['Old name']);

        \core_tag_tag::get_by_name($this->tagcollid, 'Old name', '*')->update(['rawname' => 'New name']);

        $this->assert_keywords(['New name', 'Other'], $this->courses[1]->id);
        $this->assert_keywords(['New name'], $this->courses[2]->id);
    }

    public function test_combining_keywords_updates_every_course(): void {
        $this->save_keywords($this->courses[1]->id, ['Main', 'Dup']);
        $this->save_keywords($this->courses[2]->id, ['Dup']);

        $main = \core_tag_tag::get_by_name($this->tagcollid, 'Main', '*');
        $main->combine_tags([\core_tag_tag::get_by_name($this->tagcollid, 'Dup', '*')]);

        $this->assert_keywords(['Main'], $this->courses[1]->id);
        $this->assert_keywords(['Main'], $this->courses[2]->id);
    }

    public function test_untagging_one_course_leaves_the_others(): void {
        $this->save_keywords($this->courses[1]->id, ['shared']);
        $this->save_keywords($this->courses[2]->id, ['shared']);

        $data = $this->get_data($this->courses[1]->id);
        \core_tag_tag::remove_item_tag(
            data_controller::TAG_COMPONENT,
            data_controller::TAG_ITEMTYPE,
            $data->get('id'),
            'shared'
        );

        $this->assert_keywords([], $this->courses[1]->id);
        $this->assert_keywords(['shared'], $this->courses[2]->id);
    }

    public function test_other_tag_areas_are_ignored(): void {
        global $DB;

        $this->save_keywords($this->courses[1]->id, ['kept']);
        $data = $this->get_data($this->courses[1]->id);
        $timemodified = $DB->get_field('customfield_data', 'timemodified', ['id' => $data->get('id')]);
        $this->waitForSecond();

        // A core course tag whose itemid happens to equal this field instance's customfield_data.id.
        \core_tag_tag::set_item_tags(
            'core',
            'course',
            $data->get('id'),
            \context_system::instance(),
            ['unrelated']
        );
        \core_tag_tag::set_item_tags('core', 'course', $data->get('id'), \context_system::instance(), []);

        $this->assertEquals($timemodified, $DB->get_field('customfield_data', 'timemodified', ['id' => $data->get('id')]));
        $this->assert_keywords(['kept'], $this->courses[1]->id);
    }

    public function test_sync_value_from_tags_on_missing_row(): void {
        $this->assertEquals('[]', data_controller::sync_value_from_tags(-1));
    }

    public function test_upgrade_rebuilds_stale_mirrors(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/customfield/field/keywords/db/upgrade.php');

        $this->save_keywords($this->courses[1]->id, ['real']);
        $this->save_keywords($this->courses[2]->id, ['also real']);
        // Mirrors left stale by 1.0.6, which didn't sync them after a keyword was deleted.
        $dataid1 = $this->get_data($this->courses[1]->id)->get('id');
        $dataid2 = $this->get_data($this->courses[2]->id)->get('id');
        $DB->set_field('customfield_data', 'value', '["ghost","real"]', ['id' => $dataid1]);
        $DB->set_field('customfield_data', 'value', '["ghost"]', ['id' => $dataid2]);

        set_config('version', 2026082101, 'customfield_keywords');
        $this->assertTrue(xmldb_customfield_keywords_upgrade(2026082101));

        $this->assert_keywords(['real'], $this->courses[1]->id);
        $this->assert_keywords(['also real'], $this->courses[2]->id);
    }
}
