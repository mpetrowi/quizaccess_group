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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
/**
* Strings for the quizaccess_group plugin.
*
* @package quizaccess_group
* @copyright 2014 The University of Wisconsin
* @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
defined('MOODLE_INTERNAL') || die();
$string['pluginname'] = 'Group quiz access';

$string['adduser'] = 'Add selected user';
$string['attemptusers'] = 'Attempt group';
$string['attendance'] = 'Ask attendance (moodle groups)';
$string['attendance_help'] = 'Enabling this while in moodle group mode will allow students to mark who is present.';
$string['attendanceheader'] = 'Please take attendance';
$string['currentusernotattending'] = 'You have to add yourself too!';
$string['descriptionadhoc'] = 'Group work: Students can take the quiz in groups.';
$string['descriptionadhocwithsize'] = 'Group work: Students can take the quiz in groups of size at most {$a}.';
$string['descriptionmoodlegroup'] = 'Group work: Students take the quiz in pre-assigned groups.';
$string['groupsettingsheader'] = 'Group work';
$string['grouping'] = 'Grouping (moodle groups)';
$string['grouping_help'] = 'Use groups from this grouping, or the default grouping if none selected.';
$string['groupmode'] = 'Students worked in groups';
$string['groupmode_help'] = 'Enable this if students take the quiz in groups.  All students in a group willl be able to review any other attempt by a member of the group, and grades will be assigned uniformly to all members of a group. Standard quiz restictions (e.g. number of attempts) are restricted based on the group as a whole.  This setting cannot be changed if the quiz already has attempts.';
$string['groupmode_none'] = 'No groups';
$string['groupmode_moodle'] = 'Moodle groups';
$string['groupmode_adhoc'] = 'Ad hoc groups';
$string['grademethod'] = 'Group grading method';
$string['grademethod_help'] = 'Method for aggregating user grades into group grades.';
$string['gradehighest'] = 'Highest student grade';
$string['gradeitemname'] = '{$a} - group grade';
$string['gradeaverage'] = 'Average student grade';
$string['groupheader'] = 'Group quiz attempt';
$string['maxgroupsize'] = 'Max group size (ad hoc groups)';
$string['maxgroupsize_help'] = 'For ad hoc groups, this is the maximum number of students that can work together.';
$string['mustchoosegroup'] = 'You must select a group';
$string['nogroup'] = 'This quiz is setup for group access, but you are not in any group.';
$string['nomoreattempsfor'] = 'No more attempts allowed for:';
$string['selectagroup'] = 'Select a group';
$string['selectstudents'] = 'Select up to {$a} people';
$string['selectstudentsnolimit'] = 'Select people';
$string['summaryofgroupattempts'] = 'Summary of previous attempts made by others';
$string['takeattendance'] = 'Please take attendance';
$string['toomanyusers'] = 'Too many people selected';