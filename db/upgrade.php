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

    return true;
}
