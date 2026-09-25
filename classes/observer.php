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
 * Event observers for customfield_keywords.
 *
 * @package   customfield_keywords
 * @author    Pedro Luis Garcia Leiva
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace customfield_keywords;

defined('MOODLE_INTERNAL') || die;

/**
 * Keeps the JSON mirror in customfield_data.value in sync with tag_instance.
 *
 * Keywords behave like core tags: deleting, renaming or combining a keyword on
 * /tag/manage.php (or untagging a course there) must be reflected on every
 * course that uses it. tag_instance - the live source of truth - is already
 * updated by core in all those cases; these observers bring the mirror along.
 * See data_controller::sync_value_from_tags().
 */
class observer {
    /**
     * A keyword was attached to a field instance.
     *
     * @param \core\event\tag_added $event
     */
    public static function tag_added(\core\event\tag_added $event): void {
        self::sync_instance_event($event->other);
    }

    /**
     * A keyword was detached from a field instance, which is also how core
     * reports a deleted keyword (one event per instance it was attached to) and
     * each instance moved to another keyword by "Combine selected".
     *
     * @param \core\event\tag_removed $event
     */
    public static function tag_removed(\core\event\tag_removed $event): void {
        self::sync_instance_event($event->other);
    }

    /**
     * A keyword was renamed (or otherwise updated): resync every field instance using it.
     *
     * @param \core\event\tag_updated $event
     */
    public static function tag_updated(\core\event\tag_updated $event): void {
        global $DB;

        $itemids = $DB->get_fieldset_select('tag_instance', 'DISTINCT itemid',
            'tagid = :tagid AND component = :component AND itemtype = :itemtype', [
                'tagid' => $event->objectid,
                'component' => data_controller::TAG_COMPONENT,
                'itemtype' => data_controller::TAG_ITEMTYPE,
            ]);
        foreach ($itemids as $itemid) {
            data_controller::sync_value_from_tags((int) $itemid);
        }
    }

    /**
     * Resync the field instance a tag_added/tag_removed event refers to, if it belongs to this plugin.
     *
     * The events don't carry the component, only the itemtype, which is unique to this plugin.
     *
     * @param array $other the event's 'other' data
     */
    protected static function sync_instance_event(array $other): void {
        if (($other['itemtype'] ?? null) !== data_controller::TAG_ITEMTYPE || empty($other['itemid'])) {
            return;
        }
        data_controller::sync_value_from_tags((int) $other['itemid']);
    }
}
