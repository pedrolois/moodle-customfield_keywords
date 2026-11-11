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

use core_course\customfield\course_handler;

/**
 * Tests of the field settings (maximum number of keywords, links), backup and restore, and the tag page.
 *
 * @package   customfield_keywords
 * @category  test
 * @covers    \customfield_keywords\data_controller
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(data_controller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(field_controller::class)]
final class field_settings_test extends \advanced_testcase {
    /**
     * Create a course custom field of type Keywords.
     *
     * @param string $shortname
     * @param array $configdata
     * @return field_controller
     */
    private function create_field(string $shortname, array $configdata = []): field_controller {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category(['component' => 'core_course', 'area' => 'course', 'itemid' => 0]);
        return $generator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'keywords',
            'shortname' => $shortname,
            'name' => ucfirst($shortname),
            'configdata' => $configdata,
        ]);
    }

    /**
     * The data controller of a field for a course.
     *
     * @param int $courseid
     * @param string $shortname
     * @return data_controller
     */
    private function get_data(int $courseid, string $shortname): data_controller {
        foreach (course_handler::create()->get_instance_data($courseid, true) as $data) {
            if ($data->get_field()->get('shortname') === $shortname) {
                return $data;
            }
        }
        $this->fail("No data for field {$shortname}");
    }

    /**
     * Keywords are shown as escaped links to the courses with the same keyword, or as plain text.
     *
     * @covers \customfield_keywords\data_controller
     */
    public function test_export_value(): void {
        $this->resetAfterTest();
        $this->create_field('topics');
        $this->create_field('plain', ['displayaslinks' => 0]);
        $course = $this->getDataGenerator()->create_course([
            'customfield_topics' => ['Leadership', 'R&D'],
            'customfield_plain' => ['R&D'],
        ]);

        $html = $this->get_data($course->id, 'topics')->export_value();
        $this->assertStringContainsString('class="customfield_keywords-keyword"', $html);
        $this->assertStringContainsString('/tag/index.php', $html);
        $this->assertStringContainsString('>R&amp;D</a>', $html);
        $this->assertSame('R&amp;D', $this->get_data($course->id, 'plain')->export_value());

        $empty = $this->getDataGenerator()->create_course();
        $this->assertNull($this->get_data($empty->id, 'topics')->export_value());
    }

    /**
     * More keywords than the field allows are refused; a negative limit is refused in the field settings.
     *
     * @covers \customfield_keywords\data_controller
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $field = $this->create_field('topics', ['maxkeywords' => 2]);
        $course = $this->getDataGenerator()->create_course();
        $data = $this->get_data($course->id, 'topics');
        $element = $data->get_form_element_name();

        $this->assertSame([], $data->instance_form_validation([$element => ['A', 'B']], []));
        $errors = $data->instance_form_validation([$element => ['A', 'B', 'C']], []);
        $this->assertSame(get_string('toomanykeywords', 'customfield_keywords', 2), $errors[$element]);

        $this->assertSame(2, $field->get_max_keywords());
        $this->assertTrue($field->display_as_links());
        $errors = $field->config_form_validation(['configdata' => ['maxkeywords' => -1]]);
        $this->assertArrayHasKey('configdata[maxkeywords]', $errors);
    }

    /**
     * Keywords survive a course backup and restore into a new course.
     *
     * @covers \customfield_keywords\data_controller
     */
    public function test_backup_and_restore(): void {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_field('topics');
        $course = $this->getDataGenerator()->create_course(['customfield_topics' => ['Leadership', 'Finance']]);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Copy', 'copy', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $copy = $this->get_data($newcourseid, 'topics');
        $this->assertEqualsCanonicalizing(['Leadership', 'Finance'], json_decode($copy->get_value(), true));
        // The original keeps its own keywords.
        $this->assertCount(2, json_decode($this->get_data($course->id, 'topics')->get_value(), true));
    }

    /**
     * The tag page lists the courses that have a keyword.
     *
     * @covers \customfield_keywords\data_controller
     */
    public function test_tagged_courses(): void {
        global $CFG;
        require_once($CFG->dirroot . '/customfield/field/keywords/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_field('topics');
        $this->getDataGenerator()->create_course(['fullname' => 'Leading teams', 'customfield_topics' => ['Leadership']]);
        $this->getDataGenerator()->create_course(['fullname' => 'Budgets', 'customfield_topics' => ['Finance']]);

        $tagcollid = \core_tag_area::get_collection(data_controller::TAG_COMPONENT, data_controller::TAG_ITEMTYPE);
        $tag = \core_tag_tag::get_by_name($tagcollid, 'Leadership', '*');
        $index = customfield_keywords_get_tagged_courses($tag, true);
        $html = $index->export_for_template($GLOBALS['OUTPUT'])->content;
        $this->assertStringContainsString('Leading teams', $html);
        $this->assertStringNotContainsString('Budgets', $html);
    }
}
