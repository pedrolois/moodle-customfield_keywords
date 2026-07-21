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
 * Library functions for customfield_keywords, including the tag area callback.
 *
 * @package   customfield_keywords
 * @copyright 2026 Pedro Lois
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns courses tagged with a given keyword, for the tag area
 * (component=customfield_keywords, itemtype=course_keyword).
 *
 * core_course_category::search_courses()/search_courses_count() and
 * course renderer's tagged_courses(), which core's own
 * course_get_tagged_courses() delegates to, only ever search
 * tag_instance rows for component='core', itemtype='course' - the
 * 'tagid' search criterion hardcodes that pair internally, with no way
 * to override it. So unlike course_get_tagged_courses(), this callback
 * queries tag_instance directly for our own component/itemtype and
 * builds the course list itself.
 *
 * @param core_tag_tag $tag
 * @param bool $exclusivemode
 * @param int $fromctx
 * @param int $ctx
 * @param bool $rec
 * @param int $page
 * @return \core_tag\output\tagindex
 */
function customfield_keywords_get_tagged_courses($tag, $exclusivemode = false, $fromctx = 0, $ctx = 0, $rec = 1, $page = 0) {
    global $DB, $OUTPUT, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');

    $perpage = $exclusivemode ? $CFG->coursesperpage : 5;

    $ctxselect = context_helper::get_preload_record_columns_sql('ctx');
    $sql = "SELECT c.*, $ctxselect
              FROM {course} c
              JOIN {tag_instance} ti ON ti.itemid = c.id
              JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextcourse
             WHERE ti.tagid = :tagid AND ti.itemtype = :itemtype AND ti.component = :component
               AND c.id <> :siteid
          ORDER BY c.sortorder";
    $params = [
        'contextcourse' => CONTEXT_COURSE,
        'tagid' => $tag->id,
        'itemtype' => 'course_keyword',
        'component' => 'customfield_keywords',
        'siteid' => SITEID,
    ];

    $totalcourses = $DB->get_records_sql($sql, $params);
    $totalcount = count($totalcourses);
    $records = array_slice($totalcourses, $page * $perpage, $perpage, true);

    $courselist = [];
    foreach ($records as $record) {
        $courselist[$record->id] = new core_course_list_element($record);
    }

    if (empty($courselist)) {
        return null;
    }

    // Core_course_renderer::coursecat_courses(), which produces the fuller card-style
    // listing core uses for its own native course Tags area, is a protected method with
    // no public equivalent - so this uses the same simple tagfeed template core itself
    // falls back to for non-exclusive mode, in both modes here.
    $tagfeed = new \core_tag\output\tagfeed();
    $img = $OUTPUT->pix_icon('i/course', '');
    foreach ($courselist as $course) {
        $url = course_get_url($course);
        $imgwithlink = html_writer::link($url, $img);
        $coursename = html_writer::link($url, $course->get_formatted_name());
        $details = get_string('category') . ': ' . html_writer::link(
            new moodle_url('/course/index.php', ['categoryid' => $course->category]),
            core_course_category::get($course->category, IGNORE_MISSING)?->get_formatted_name() ?? ''
        );
        $tagfeed->add($imgwithlink, $coursename, $details);
    }
    $content = $OUTPUT->render_from_template('core_tag/tagfeed', $tagfeed->export_for_template($OUTPUT));

    $totalpages = ceil($totalcount / $perpage);

    return new core_tag\output\tagindex(
        $tag,
        'customfield_keywords',
        'course_keyword',
        $content,
        $exclusivemode,
        $fromctx,
        $ctx,
        $rec,
        $page,
        $totalpages
    );
}
