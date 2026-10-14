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
 * Upgrade steps for customfield_keywords.
 *
 * @package   customfield_keywords
 * @author    Pedro Luis Garcia Leiva
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_customfield_keywords_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026092500) {
        // Before 1.0.7 nothing kept the JSON mirror in customfield_data.value in sync when
        // keywords were deleted, renamed or combined on /tag/manage.php, so it may still list
        // keywords that no longer exist. Rebuild every mirror from its live tag_instance rows.
        // The resync reads through data_controller::TAG_ITEMTYPE, which is 'customfield_data'
        // since 1.0.9, so rename the rows first or every mirror would be rebuilt as empty.
        customfield_keywords_rename_tag_itemtype();
        $dataids = $DB->get_fieldset_sql(
            "SELECT d.id
               FROM {customfield_data} d
               JOIN {customfield_field} f ON f.id = d.fieldid
              WHERE f.type = :type",
            ['type' => 'keywords']
        );
        foreach ($dataids as $dataid) {
            \customfield_keywords\data_controller::sync_value_from_tags((int) $dataid);
        }

        upgrade_plugin_savepoint(true, 2026092500, 'customfield', 'keywords');
    }

    if ($oldversion < 2026101400) {
        // Already done by the step above when upgrading from before 1.0.7; it is idempotent.
        customfield_keywords_rename_tag_itemtype();
        upgrade_plugin_savepoint(true, 2026101400, 'customfield', 'keywords');
    }

    return true;
}

/**
 * Renames this plugin's tag itemtype from 'course_keyword' to 'customfield_data'.
 *
 * core's tag_cron_task::cleanup() LEFT JOINs {<itemtype>} on tag_instance.itemid to find
 * orphaned instances, so 'course_keyword' (no such table) made the "Background processing
 * for tags" scheduled task fail on every run. The itemid already is customfield_data.id.
 *
 * The tag_area row is renamed in place rather than left for
 * core_tag_area::reset_definitions_for_component() to recreate, so the area keeps its id,
 * tag collection and enabled flag.
 */
function customfield_keywords_rename_tag_itemtype(): void {
    global $DB;

    $old = ['component' => 'customfield_keywords', 'itemtype' => 'course_keyword'];
    $new = ['component' => 'customfield_keywords', 'itemtype' => 'customfield_data'];

    if ($DB->record_exists('tag_area', $old) && !$DB->record_exists('tag_area', $new)) {
        $DB->set_field('tag_area', 'itemtype', $new['itemtype'], $old);
    }
    $DB->set_field('tag_instance', 'itemtype', $new['itemtype'], $old);

    cache::make('core', 'tags')->delete_many(['tag_area', 'tag_coll']);
}
