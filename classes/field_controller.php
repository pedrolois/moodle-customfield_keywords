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

/**
 * Field controller for the Keywords custom field type.
 *
 * Keyword values are free-text tags, not admin-defined options. Each field can limit how many keywords a course
 * may have, and choose whether keywords are shown as links to the list of courses with the same keyword.
 *
 * @package customfield_keywords
 * @author    Pedro Luis Garcia Leiva
 * @copyright 2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_controller extends \core_customfield\field_controller {
    /** Customfield type. */
    const TYPE = 'keywords';

    /**
     * Settings of a Keywords field: the maximum number of keywords and how they are shown.
     *
     * @param \MoodleQuickForm $mform
     */
    public function config_form_definition(\MoodleQuickForm $mform) {
        $mform->addElement('header', 'header_specificsettings', get_string('specificsettings', 'customfield_keywords'));
        $mform->setExpanded('header_specificsettings', true);

        $mform->addElement('text', 'configdata[maxkeywords]', get_string('maxkeywords', 'customfield_keywords'), ['size' => 6]);
        $mform->setType('configdata[maxkeywords]', PARAM_INT);
        $mform->setDefault('configdata[maxkeywords]', 0);
        $mform->addHelpButton('configdata[maxkeywords]', 'maxkeywords', 'customfield_keywords');

        $mform->addElement('selectyesno', 'configdata[displayaslinks]', get_string('displayaslinks', 'customfield_keywords'));
        $mform->setType('configdata[displayaslinks]', PARAM_BOOL);
        $mform->setDefault('configdata[displayaslinks]', 1);
        $mform->addHelpButton('configdata[displayaslinks]', 'displayaslinks', 'customfield_keywords');
    }

    /**
     * The maximum number of keywords cannot be negative.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function config_form_validation(array $data, $files = []): array {
        $errors = parent::config_form_validation($data, $files);
        if ((int) ($data['configdata']['maxkeywords'] ?? 0) < 0) {
            $errors['configdata[maxkeywords]'] = get_string('maxkeywordsinvalid', 'customfield_keywords');
        }
        return $errors;
    }

    /**
     * Maximum number of keywords a course may have, 0 for no limit.
     *
     * @return int
     */
    public function get_max_keywords(): int {
        return max(0, (int) $this->get_configdata_property('maxkeywords'));
    }

    /**
     * Whether keywords are shown as links to the courses with the same keyword. Fields created before this
     * setting existed show links.
     *
     * @return bool
     */
    public function display_as_links(): bool {
        $value = $this->get_configdata_property('displayaslinks');
        return $value === null || (bool) $value;
    }
}
