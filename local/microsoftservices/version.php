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
 * @package local_microsoftservices
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version = 2015012712;
$plugin->requires = 2014111000;
$plugin->component = 'local_microsoftservices';
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '28.0.0.12';
$plugin->dependencies = [
    'block_microsoft' => 2015080415,
    'local_msaccount' => 2015012706,
    'local_onenote' => 2015012712,
    'assignfeedback_onenote' => 2015012708,
    'assignsubmission_onenote' => 2015012708,
    'repository_onenote' => 2015012706,
    'filter_oembed' => 2015012710,
];
