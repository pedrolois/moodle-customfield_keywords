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
 * Keywords plugin data controller.
 *
 * @package   customfield_keywords
 * @copyright 2026 Pedro Lois
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace customfield_keywords;

defined('MOODLE_INTERNAL') || die;

/**
 * Data controller for the Keywords custom field type.
 *
 * The customfield_data table only allows a single value per (fieldid,
 * instanceid) pair, so it cannot hold a multi-value field on its own. This
 * controller keeps tag_instance (core_tag_tag) as the live source of truth for
 * reads and autocomplete, under its own component/itemtype so it never mixes
 * with the standard course "Tags" field, while also mirroring the same
 * keyword list as JSON in customfield_data.value purely so that course
 * backup/restore - which serialises data_controller::get_value() straight
 * into the <customfield><value> backup XML node and has no mechanism for a
 * data_controller to add its own XML nodes on restore - has something to
 * round-trip. See restore_define_structure() below for how that JSON is used
 * to rebuild the tag_instance rows after a restore.
 *
 * component/itemtype are the same for every field of this type, so if two
 * "Keywords" custom fields are configured on the same area (e.g. two
 * different course custom fields), they must not share the same tag_instance
 * itemid or set_item_tags() from one would silently overwrite the other's
 * tags (set_item_tags() is destructive per component+itemtype+itemid+context).
 * Using this data_controller's own {customfield_data} row id - a real,
 * persisted row unique per (field, instance) - as the itemid instead of the
 * shared course/instance id keeps each field's keywords in their own,
 * non-colliding tag_instance namespace, the same way core's 'question'
 * tag area uses the real question id as itemid rather than a shared one.
 *
 * Autocomplete *suggestions* are shared across every Keywords field on the
 * site (not just per course): core_tag_area::get_collection() always
 * resolves the same tag collection for this plugin's fixed component +
 * itemtype, and Moodle only reads db/tag.php once, at install/upgrade time -
 * there's no supported way to register a distinct tag_area (and thus tag
 * collection) per field id at runtime, and set_item_tags() always resolves
 * that same fixed collection internally with no way to override it. A lazy
 * per-field core_tag_collection was tried and reverted: passing a distinct
 * 'tagcollid' straight to the 'tags' form element does scope *suggestions*
 * to that collection, but set_item_tags() would still keep writing the real
 * tag rows into the shared one - so distinct per-field collections would
 * always show empty suggestions, since nothing ever creates tags in them.
 * Isolating suggestions per field, not just per course, would need Moodle to
 * support a tag_area per field id, which it doesn't; only the itemid
 * namespacing above (storage isolation) was achievable without that.
 */
class data_controller extends \core_customfield\data_controller {
    /** @var string component used to namespace tag_instance rows for this field type */
    const TAG_COMPONENT = 'customfield_keywords';

    /** @var string itemtype used to namespace tag_instance rows for this field type */
    const TAG_ITEMTYPE = 'course_keyword';

    /**
     * Return the name of the field where the information is stored.
     *
     * Only used by the framework as the backup marker/mirror (see class
     * docblock); the live values used for reads and autocomplete come from
     * tag_instance via get_keywords(), not from this column.
     *
     * @return string
     */
    public function datafield(): string {
        return 'value';
    }

    /**
     * Returns the default value as it would be stored in the database.
     *
     * @return string
     */
    public function get_default_value() {
        return '';
    }

    /**
     * Add the tags-style form element for editing keywords.
     *
     * Reuses Moodle's native 'tags' form element, which already provides
     * multi-value input with ajax autocomplete suggesting values already
     * used elsewhere in this tag area (i.e. keywords used on other courses).
     *
     * @param \MoodleQuickForm $mform
     */
    public function instance_form_definition(\MoodleQuickForm $mform) {
        global $PAGE;

        $elementname = $this->get_form_element_name();
        $mform->addElement('tags', $elementname, $this->get_field()->get_formatted_name(), [
            'itemtype' => self::TAG_ITEMTYPE,
            'component' => self::TAG_COMPONENT,
        ]);

        if ($this->get_field()->get_configdata_property('required')) {
            $mform->addRule($elementname, null, 'required', null, 'client');
        }

        // The core 'tags' element always labels its admin link with the generic
        // "Manage standard tags" string (lib/form/tags.php toHtml()), since that
        // string isn't parameterised per tag area. Relabel it client-side instead
        // of overriding the core 'tag' language string, which would rename the
        // link on every other tags element on the site (course Tags, forum
        // posts...), not just this one.
        $tagcollid = \core_tag_area::get_collection(self::TAG_COMPONENT, self::TAG_ITEMTYPE);
        $PAGE->requires->js_call_amd('customfield_keywords/managelink', 'init', [
            $tagcollid,
            get_string('managekeywords', 'customfield_keywords'),
        ]);
    }

    /**
     * Prepares the custom field data to pass to mform->set_data().
     *
     * Loads the current keyword list from core_tag_tag instead of from
     * customfield_data, since that is where the live values are read from.
     *
     * @param \stdClass $instance
     */
    public function instance_form_before_set_data(\stdClass $instance) {
        $instance->{$this->get_form_element_name()} = $this->get_keywords((int) $this->get('id'));
    }

    /**
     * Saves the data coming from the form.
     *
     * Writes the submitted keyword list to core_tag_tag under this field's
     * own component/itemtype, and mirrors the same list as JSON in
     * customfield_data.value (see class docblock for why).
     *
     * @param \stdClass $datanew data coming from the form
     */
    public function instance_form_save(\stdClass $datanew) {
        $elementname = $this->get_form_element_name();
        if (!property_exists($datanew, $elementname)) {
            return;
        }

        $keywords = $datanew->{$elementname};
        if (!is_array($keywords)) {
            $keywords = $keywords === '' ? [] : preg_split('/\s*,\s*/', trim($keywords), -1, PREG_SPLIT_NO_EMPTY);
        }
        $keywords = array_values($keywords);

        // Save first so a new instance gets its customfield_data.id - see class docblock
        // for why that id, not the course/instance id, is used as the tag_instance itemid.
        $this->data->set('value', json_encode($keywords));
        $this->data->set('valueformat', FORMAT_PLAIN);
        $this->save();

        $this->set_keywords((int) $this->get('id'), $this->get_context(), $keywords);
    }

    /**
     * Links the given tag_instance itemid (this data_controller's own
     * customfield_data.id - see class docblock) to the given keywords in
     * core_tag_tag, and marks any newly-used keyword as standard so it
     * becomes an autocomplete suggestion everywhere. Shared by
     * instance_form_save() and restore_define_structure().
     *
     * @param int $itemid this field instance's customfield_data.id
     * @param \context $context course context
     * @param string[] $keywords
     */
    protected function set_keywords(int $itemid, \context $context, array $keywords): void {
        \core_tag_tag::set_item_tags(
            self::TAG_COMPONENT,
            self::TAG_ITEMTYPE,
            $itemid,
            $context,
            $keywords
        );

        // Keywords are meant to be suggested site-wide as soon as they exist, unlike core
        // tag areas where set_item_tags() always creates new tags as non-standard. Marking
        // them standard here (scoped to this field's own tag collection only) is what makes
        // them appear in the autocomplete suggestion list for every other course.
        $this->mark_keywords_as_standard($keywords);
    }

    /**
     * Marks the given keyword names as standard tags within this field's own
     * tag collection, so they show up as autocomplete suggestions everywhere
     * without requiring an admin to flip "Standard" manually on /tag/manage.php.
     *
     * Scoped to TAG_COMPONENT/TAG_ITEMTYPE's collection only: it does not touch
     * core course tags or any other tag area's standard/non-standard status.
     *
     * @param string[] $keywords
     */
    protected function mark_keywords_as_standard(array $keywords): void {
        if (empty($keywords)) {
            return;
        }
        $tagcollid = \core_tag_area::get_collection(self::TAG_COMPONENT, self::TAG_ITEMTYPE);
        $tags = \core_tag_tag::get_by_name_bulk($tagcollid, $keywords, 'id, name, rawname, isstandard');
        foreach ($tags as $tag) {
            if ($tag !== null && !$tag->isstandard) {
                $tag->update(['isstandard' => 1]);
            }
        }
    }

    /**
     * Returns the keyword names currently attached to this field instance
     * (this data_controller's own customfield_data.id - see class docblock),
     * read live from tag_instance.
     *
     * @param int $itemid this field instance's customfield_data.id
     * @return string[]
     */
    protected function get_keywords(int $itemid): array {
        if (!$itemid) {
            return [];
        }
        return array_values(\core_tag_tag::get_item_tags_array(
            self::TAG_COMPONENT,
            self::TAG_ITEMTYPE,
            $itemid
        ));
    }

    /**
     * Returns value in a human-readable format.
     *
     * @return string|null comma-separated keyword list, or null if empty
     */
    public function export_value() {
        $keywords = $this->get_keywords((int) $this->get('id'));
        if (empty($keywords)) {
            return null;
        }
        return implode(', ', $keywords);
    }

    /**
     * Returns the value as it will be written into customfield_data.value.
     *
     * This is what core_course\customfield\course_handler::get_instance_data_for_backup()
     * puts straight into the <customfield><value> backup XML node (see class
     * docblock), so it must return the JSON-encoded keyword list — a scalar,
     * unlike most other data_controller consumers of this method which expect
     * the live value. Runtime code that wants the keyword list itself should
     * call get_keywords() instead.
     *
     * @return string JSON-encoded array of keyword names
     */
    public function get_value() {
        return json_encode($this->get_keywords((int) $this->get('id')));
    }

    /**
     * Delete data. Also removes the associated tag instances.
     *
     * @return bool
     */
    public function delete() {
        $id = $this->get('id');
        if ($id) {
            \core_tag_tag::remove_all_item_tags(self::TAG_COMPONENT, self::TAG_ITEMTYPE, $id);
        }
        return parent::delete();
    }

    /**
     * Backup callback for the custom field element.
     *
     * No override needed: course_handler::get_instance_data_for_backup() already
     * writes get_value()'s JSON-encoded keyword list into the standard
     * <customfield><value> XML node (see class docblock), so the base no-op
     * implementation is sufficient here.
     *
     * @param \backup_nested_element $customfieldelement
     */
    public function backup_define_structure(\backup_nested_element $customfieldelement): void {
    }

    /**
     * Restore callback for the custom field element.
     *
     * By the time this runs, $newid already points at a persisted
     * customfield_data row (course_handler::restore_instance_data_from_backup()
     * has just inserted/updated it) whose 'value' column holds the
     * JSON-encoded keyword list from the backup XML. Decode it and rebuild the
     * tag_instance rows - keyed by this same $newid, per the class docblock -
     * for the restored/duplicated course, since core's course backup only
     * restores tag_instance rows for the native component=core/itemtype=course
     * tag area, never third-party tag areas like this one.
     *
     * @param \restore_structure_step $step
     * @param int $newid the new customfield_data id after restore; also this
     *   field instance's tag_instance itemid going forward
     * @param int $oldid the original customfield_data id before backup (unused)
     */
    public function restore_define_structure(\restore_structure_step $step, int $newid, int $oldid): void {
        global $DB;

        $record = $DB->get_record('customfield_data', ['id' => $newid], 'value, instanceid');
        if (!$record || !$record->value) {
            return;
        }
        $keywords = json_decode($record->value, true);
        if (!is_array($keywords) || empty($keywords)) {
            return;
        }

        $context = $this->get_field()->get_handler()->get_instance_context((int) $record->instanceid);
        $this->set_keywords($newid, $context, $keywords);
    }
}
