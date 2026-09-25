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

use tool_uploadcourse_course;
use tool_uploadcourse_processor;

/**
 * Tests importing Keywords through tool_uploadcourse (Site administration > Courses > Upload courses).
 *
 * @package   customfield_keywords
 * @author    Pedro Luis Garcia Leiva
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \customfield_keywords\data_controller::instance_form_before_set_data
 */
final class uploadcourse_test extends \advanced_testcase {
    /** @var \core_customfield\field_controller the Keywords field under test */
    protected $field;

    /** @var \stdClass the course being updated */
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
        $this->course = $this->getDataGenerator()->create_course(['shortname' => 'C1']);
    }

    /**
     * Run a one-row update the way the Upload courses page does.
     *
     * The page always passes the step-2 "Default course values" form's value for every custom
     * field as a default; for a Keywords field that form element is a 'tags' element, so the
     * default is always an array ([] when nothing was picked).
     *
     * @param string $cell the CSV cell for the Keywords column
     * @param array $default the step-2 form default for the Keywords field
     */
    protected function upload(string $cell, array $default = []): void {
        $uploader = new tool_uploadcourse_course(
            tool_uploadcourse_processor::MODE_UPDATE_ONLY,
            tool_uploadcourse_processor::UPDATE_ALL_WITH_DATA_ONLY,
            ['shortname' => $this->course->shortname, 'customfield_kw' => $cell],
            ['customfield_kw' => $default]
        );
        $this->assertTrue($uploader->prepare(), json_encode($uploader->get_errors()));
        $uploader->proceed();
        $this->assertEmpty($uploader->get_errors());
    }

    /**
     * The course's keywords as seen by web services.
     *
     * @return array
     */
    protected function get_keywords(): array {
        $fieldid = $this->field->get('id');
        $data = \core_customfield\api::get_instance_fields_data([$fieldid => $this->field], $this->course->id)[$fieldid];
        return json_decode($data->get_value(), true);
    }

    public function test_upload_sets_keywords_from_csv(): void {
        $this->upload('AI Governance, Data');
        $this->assertEquals(['AI Governance', 'Data'], $this->get_keywords());
    }

    /**
     * An empty cell, or one PHP's empty() treats as empty, falls back to the step-2 array default.
     * Before 1.0.7 this aborted the whole import with "clean() can not process arrays".
     *
     * @dataProvider empty_cell_provider
     * @param string $cell
     */
    public function test_upload_with_empty_cell_uses_form_default(string $cell): void {
        $this->upload($cell);
        $this->assertEquals([], $this->get_keywords());

        $this->upload($cell, ['Default one', 'Default two']);
        $this->assertEquals(['Default one', 'Default two'], $this->get_keywords());
    }

    /**
     * Cells tool_uploadcourse treats as empty.
     *
     * @return array
     */
    public static function empty_cell_provider(): array {
        return [
            'empty' => [''],
            'zero' => ['0'],
        ];
    }

    public function test_upload_through_processor_with_csv(): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $csv = "shortname,customfield_kw\nC1,\n";
        $iid = \csv_import_reader::get_new_iid('uploadcourse');
        $cir = new \csv_import_reader($iid, 'uploadcourse');
        $cir->load_csv_content($csv, 'utf-8', 'comma');
        $processor = new tool_uploadcourse_processor($cir, [
            'mode' => tool_uploadcourse_processor::MODE_UPDATE_ONLY,
            'updatemode' => tool_uploadcourse_processor::UPDATE_ALL_WITH_DATA_ONLY,
        ], ['customfield_kw' => []]);

        $tracker = new \tool_uploadcourse_tracker(\tool_uploadcourse_tracker::NO_OUTPUT);
        $processor->execute($tracker);

        $this->assertEmpty($processor->get_errors());
        $this->assertEquals([], $this->get_keywords());
    }
}
