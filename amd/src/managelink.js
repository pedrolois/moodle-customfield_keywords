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
 * Relabels the generic "Manage standard tags" link the core 'tags' form
 * element renders next to our Keywords field.
 *
 * The element (lib/form/tags.php toHtml()) always uses the core 'tag'
 * component's generic string and gives the link no distinguishing
 * data-attribute, so the only reliable hook is its href, which encodes our
 * own tag collection id (tc=<tagcollid>) since we registered our own
 * collection in db/tag.php. This only ever touches links pointing at that
 * collection - every other tags element on the site (course Tags, forum
 * posts...) points at a different tc and is left untouched.
 *
 * A course can have several Keywords custom fields (see README "Multiple
 * Keywords fields"), all sharing the same tag collection, so this module's
 * init() runs once per field on the same page. Each run only relabels links
 * it hasn't already touched (marked with a data attribute), so every field's
 * link gets updated, not just the first one found.
 *
 * @module     customfield_keywords/managelink
 * @copyright  2026 Pedro Lois
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const RELABELLED_ATTRIBUTE = 'data-customfield-keywords-relabelled';

/**
 * Init.
 *
 * @param {Number} tagcollid this plugin's own tag collection id
 * @param {String} label replacement link text
 */
export function init(tagcollid, label) {
    const links = document.querySelectorAll(
        `a[href*="/tag/manage.php?tc=${tagcollid}"]:not([${RELABELLED_ATTRIBUTE}])`
    );
    links.forEach((link) => {
        link.textContent = label;
        link.setAttribute(RELABELLED_ATTRIBUTE, '1');
    });
}
