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
 * Privacy Subsystem implementation for customfield_keywords.
 *
 * @package    customfield_keywords
 * @copyright  2026 Pedro Lois
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace customfield_keywords\privacy;

use core_customfield\data_controller;
use core_customfield\privacy\customfield_provider;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem for customfield_keywords implementing null_provider.
 *
 * Keywords are course-level classification metadata, not personal data about
 * any individual user, the same as the standard core course "Tags" field
 * (component=core, itemtype=course). set_item_tags() is always called here
 * without a tiuserid, so the resulting tag_instance rows are never
 * attributed to an individual user.
 *
 * @copyright  2026 Pedro Lois
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider, customfield_provider {
    /**
     * Get the language string identifier explaining why this plugin stores no personal data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }

    /**
     * Preprocesses data object that is going to be exported.
     *
     * @param data_controller $data
     * @param \stdClass $exportdata
     * @param array $subcontext
     */
    public static function export_customfield_data(data_controller $data, \stdClass $exportdata, array $subcontext) {
        $exportdata->value = $data->export_value();
        writer::with_context($data->get_context())
            ->export_data($subcontext, $exportdata);
    }

    /**
     * Allows plugins to delete everything they store related to the data.
     *
     * @param string $dataidstest
     * @param array $params
     * @param array $contextids
     */
    public static function before_delete_data(string $dataidstest, array $params, array $contextids) {
    }

    /**
     * Allows plugins to delete everything they store related to the field configuration.
     *
     * @param string $fieldidstest
     * @param array $params
     * @param array $contextids
     */
    public static function before_delete_fields(string $fieldidstest, array $params, array $contextids) {
    }
}
