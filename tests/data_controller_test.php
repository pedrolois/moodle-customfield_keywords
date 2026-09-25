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
 * Tests for the Keywords data controller.
 *
 * @package   customfield_keywords
 * @author    Pedro Luis Garcia Leiva
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \customfield_keywords\data_controller
 */
final class data_controller_test extends \advanced_testcase {
    /** @var field_controller the Keywords field under test */
    protected $field;

    /** @var \stdClass a course to attach keywords to */
    protected $course;

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
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Save keywords through the course handler, as the course edit form does.
     *
     * @param int $courseid
     * @param array|string $keywords
     * @param string $shortname
     */
    protected function save_keywords(int $courseid, $keywords, string $shortname = 'kw'): void {
        \core_course\customfield\course_handler::create()->instance_form_save(
            (object) ['id' => $courseid, 'customfield_' . $shortname => $keywords]
        );
    }

    /**
     * Get the persisted data controller for a course.
     *
     * @param int $courseid
     * @param field_controller|null $field defaults to the field under test
     * @return data_controller
     */
    protected function get_data(int $courseid, ?field_controller $field = null): data_controller {
        $field = $field ?? $this->field;
        return \core_customfield\api::get_instance_fields_data([$field->get('id') => $field], $courseid)[$field->get('id')];
    }

    /**
     * What the course edit form would be prefilled with.
     *
     * @param data_controller $data
     * @return array
     */
    protected function get_form_value(data_controller $data): array {
        $instance = new \stdClass();
        $data->instance_form_before_set_data($instance);
        return $instance->{$data->get_form_element_name()};
    }

    public function test_save_stores_tags_and_json_mirror(): void {
        global $DB;

        $this->save_keywords($this->course->id, ['AI Governance', 'Data']);

        $data = $this->get_data($this->course->id);
        $this->assertEquals(
            '["AI Governance","Data"]',
            $DB->get_field('customfield_data', 'value', ['id' => $data->get('id')])
        );
        $this->assertEquals(['AI Governance', 'Data'], json_decode($data->get_value(), true));
        $this->assertEquals('AI Governance, Data', $data->export_value());
        $this->assertEquals(['AI Governance', 'Data'], $this->get_form_value($data));

        // The keywords are marked standard so they are suggested on every course.
        $tagcollid = \core_tag_area::get_collection(data_controller::TAG_COMPONENT, data_controller::TAG_ITEMTYPE);
        $this->assertEquals(1, \core_tag_tag::get_by_name($tagcollid, 'Data', '*')->isstandard);
    }

    public function test_save_accepts_comma_separated_string(): void {
        $this->save_keywords($this->course->id, 'one, two ,three');
        $this->assertEquals(['one', 'two', 'three'], json_decode($this->get_data($this->course->id)->get_value(), true));
    }

    public function test_save_replaces_previous_keywords(): void {
        global $DB;

        $this->save_keywords($this->course->id, ['a', 'b']);
        $this->save_keywords($this->course->id, ['c']);

        $data = $this->get_data($this->course->id);
        $this->assertEquals(['c'], json_decode($data->get_value(), true));
        $this->assertEquals('["c"]', $DB->get_field('customfield_data', 'value', ['id' => $data->get('id')]));

        $this->save_keywords($this->course->id, []);
        $data = $this->get_data($this->course->id);
        $this->assertNull($data->export_value());
        $this->assertEquals('[]', $DB->get_field('customfield_data', 'value', ['id' => $data->get('id')]));
    }

    public function test_keywords_are_isolated_per_field_and_from_course_tags(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $otherfield = $generator->create_field([
            'categoryid' => $this->field->get('categoryid'),
            'type' => 'keywords',
            'shortname' => 'kw2',
        ]);
        \core_course\customfield\course_handler::create()->instance_form_save((object) [
            'id' => $this->course->id,
            'customfield_kw' => ['first'],
            'customfield_kw2' => ['second'],
        ]);
        \core_tag_tag::set_item_tags(
            'core',
            'course',
            $this->course->id,
            \context_course::instance($this->course->id),
            ['coursetag']
        );

        $this->assertEquals(['first'], json_decode($this->get_data($this->course->id)->get_value(), true));
        $this->assertEquals(['second'], json_decode($this->get_data($this->course->id, $otherfield)->get_value(), true));
        $this->assertEquals(
            ['coursetag'],
            array_values(\core_tag_tag::get_item_tags_array('core', 'course', $this->course->id))
        );
    }

    /**
     * tool_uploadcourse's helper sets the value on a placeholder controller (id=1) and then calls
     * instance_form_before_set_data(): every shape it can set must come back as a keyword list.
     *
     * @dataProvider preset_value_provider
     * @param mixed $value
     * @param array $expected
     */
    public function test_form_value_prefers_preset_value($value, array $expected): void {
        $controller = data_controller::create(0, null, $this->field);
        $controller->set('id', 1);
        $controller->set('value', $value);

        $this->assertEquals($expected, $this->get_form_value($controller));
    }

    /**
     * Values tool_uploadcourse may set before calling instance_form_before_set_data().
     *
     * @return array
     */
    public static function preset_value_provider(): array {
        return [
            'comma-separated string from the CSV' => ['alpha, beta', ['alpha', 'beta']],
            'JSON written by instance_form_save()' => ['["alpha","beta"]', ['alpha', 'beta']],
            'empty array default from the tags element' => [[], []],
            'array default from the tags element' => [['x' => 'alpha', 'y' => 'beta'], ['alpha', 'beta']],
        ];
    }

    public function test_delete_removes_tag_instances(): void {
        global $DB;

        $this->save_keywords($this->course->id, ['gone']);
        $data = $this->get_data($this->course->id);
        $dataid = $data->get('id');

        $data->delete();

        $this->assertFalse($DB->record_exists('customfield_data', ['id' => $dataid]));
        $this->assertFalse($DB->record_exists('tag_instance', [
            'component' => data_controller::TAG_COMPONENT,
            'itemtype' => data_controller::TAG_ITEMTYPE,
            'itemid' => $dataid,
        ]));
    }
}
