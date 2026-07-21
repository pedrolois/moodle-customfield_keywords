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
 * Tag area definition for customfield_keywords.
 *
 * Registers its own tag collection so keyword suggestions never mix with the
 * standard course "Tags" field (component=core, itemtype=course) or any other
 * tag area on the site.
 *
 * @package   customfield_keywords
 * @copyright 2026 Pedro Lois
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tagareas = [
    [
        'itemtype' => 'course_keyword',
        'component' => 'customfield_keywords',
        'collection' => 'customfield_keywords',
        'callback' => 'customfield_keywords_get_tagged_courses',
        'callbackfile' => '/customfield/field/keywords/lib.php',
    ],
];
