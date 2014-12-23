<?php
use Alpha\A;
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
 * Implementaton of the quizaccess_group plugin.
 *
 * @package   quizaccess_group
 * @copyright 2014 The University of Wisconsin
 * @author    Matt Petro
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');


/**
 * A rule to allow quiz attempts on a per-group basis.
 *
 * @copyright 2014 The University of Wisconsin
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_group extends quiz_access_rule_base {

    const GROUPMODE_NONE = 0;
    const GROUPMODE_MOODLE = 1;
    const GROUPMODE_ADHOC = 2;

    protected $_usergroups;    // Cache of user's groups.

    public function is_preflight_check_required($attemptid) {
        return empty($attemptid) && !$this->quizobj->is_preview_user();
    }

    public function add_preflight_check_form_fields(mod_quiz_preflight_check_form $quizform,
            MoodleQuickForm $mform, $attemptid) {
        global $COURSE, $OUTPUT, $USER, $CFG;

        $cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
        $context = context_module::instance($cm->id);
        $groups = $this->get_user_groups();
        $allgroups = groups_get_all_groups($COURSE->id);

        $passedgroupid = optional_param('groupchoice', 0, PARAM_INT);
        if ($passedgroupid && array_search($passedgroupid, $groups) !== false) {
            $groupid = $passedgroupid;
        } else {
            $groupid = null;
        }

        if ($this->is_group_mode_moodle()) {
            $onlycheckgroup = !$this->should_take_attendance() && !$this->is_group_mode_adhoc();

            if (!$groupid || $onlycheckgroup) {
                // Show group choice.
                $groupmenu = array();
                if (count($groups) > 1) {
                    $groupmenu[''] = get_string('choose');
                }
                foreach ($groups as $groupid) {
                    $groupmenu[$groupid] = format_string($allgroups[$groupid]->name);
                }

                $mform->addElement('header', 'groupheader', get_string('groupheader', 'quizaccess_group'));
                $mform->addElement('select', 'groupchoice', get_string('selectagroup', 'quizaccess_group'), $groupmenu);

            } else if ($this->should_take_attendance()) {
                // Show attendance choice.
                $mform->addElement('hidden', 'groupchoice', $groupid);
                $mform->setType('groupchoice', PARAM_INT);

                $mform->addElement('header', 'attendanceheader', get_string('attendanceheader', 'quizaccess_group'));
                $members = groups_get_members($groupid, user_picture::fields('u'));

                $attributes = array('group'=>1);

                foreach ($members as $student) {
                    $userpicture = new user_picture($student);
                    $userpicture->courseid = $this->quiz->course;
                    $username = fullname($student);
                    $itemid = 'attendance_'.$student->id;
                    $mform->addElement('advcheckbox', $itemid, null,
                            $OUTPUT->render($userpicture).$username, $attributes,
                            array(0, $student->id));
                }
                $quizform->add_checkbox_controller(1);
            }
        } else if ($this->is_group_mode_adhoc()) {

            // Prepare the list of users.
            $users = array();
            list($sort, $sortparams) = users_order_by_sql('u');
            if (!empty($sortparams)) {
                throw new coding_exception('users_order_by_sql returned some query parameters. ' .
                        'This is unexpected, and a problem because there is no way to pass these ' .
                        'parameters to get_users_by_capability. See MDL-34657.');
            }
            if (!empty($CFG->enablegroupmembersonly) && $cm->groupmembersonly) {
                // Only users from the grouping.
                $groups = groups_get_all_groups($cm->course, 0, $cm->groupingid);
                if (!empty($groups)) {
                    $users = get_users_by_capability($context, 'mod/quiz:attempt',
                            'u.id, u.email, ' . get_all_user_name_fields(true, 'u'),
                            $sort, '', '', array_keys($groups),
                            '', false, true);
                }
            } else {
                $users = get_users_by_capability($context, 'mod/quiz:attempt',
                        'u.id, u.email, ' . get_all_user_name_fields(true, 'u'),
                        $sort, '', '', '', '', false, true);
            }

            $userchoices = array();
            $canviewemail = in_array('email', get_extra_user_fields($context));
            foreach ($users as $id => $user) {
                if ($canviewemail) {
                    $userchoices[$id] = fullname($user) . ', ' . $user->email;
                } else {
                    $userchoices[$id] = fullname($user);
                }
            }
            unset($users);

            if (count($userchoices) == 0) {
                $userchoices[0] = get_string('none');
            }

            if (!empty($this->quiz->qa_group_maxgroupsize)) {
                $selectstr = get_string('selectstudents', 'quizaccess_group', $this->quiz->qa_group_maxgroupsize);
            } else {
                $selectstr = get_string('selectstudentsnolimit', 'quizaccess_group');
            }

            // TODO: Add a JS module to replace this during display.
            $mform->addElement('header', 'attendanceheader', get_string('attendanceheader', 'quizaccess_group'));
            $mform->addElement('select', 'attendance', $selectstr, $userchoices, array('multiple'=>1, 'size'=>20));
            $mform->setDefault('attendance', $USER->id);

            // Attempt to always add the current user.  This is probably not the right place to do this.
            $selected = optional_param_array('attendance', array(), PARAM_INT);
            if (!in_array($USER->id, $selected)) {
                $selected[] = (int) $USER->id;
                $mform->setConstant('attendance', $selected);
            }

            $mform->addRule('attendance', get_string('required'), 'required', null, 'client');
        }
    }

    public function validate_preflight_check($data, $files, $errors, $attemptid) {
        global $USER, $DB;
        $memberstocheck = array();  // Array of attending users to check for access to the quiz.
        if ($this->is_group_mode_moodle()) {
            if (empty($data['groupchoice'])) {
                // No group selected, so return an error.
                $errors['groupchoice'] = get_string('mustchoosegroup', 'quizaccess_group');
            } else {
                $members = groups_get_members($data['groupchoice'], 'u.id');
                if ($this->should_take_attendance()) {
                    // See if at least one person is marked present.
                    foreach (array_keys($members) as $userid) {
                        if (!empty($data['attendance_'.$userid])) {
                            $memberstocheck[] = $userid;
                        }
                    }
                    if (empty($memberstocheck)) {
                        // Need to take attendence, so return an 'error' to force form redisplay.
                        $errors['attendance'] = get_string('takeattendance', 'quizaccess_group');
                    }
                } else {
                    $memberstocheck = array_keys($members);
                }
            }
        }

        if ($this->is_group_mode_adhoc()) {
            if (!is_array($data['attendance'])) {
                $data['attendance'] = array();
            }
            // The current user has to be there.
            if (!in_array($USER->id, $data['attendance'])) {
                // This shouldn't happen since we add the current user in the form definition.
                $errors['attendance'] = get_string('currentusernotattending', 'quizaccess_group');
            } else if ($this->quiz->qa_group_maxgroupsize && count($data['attendance']) > $this->quiz->qa_group_maxgroupsize) {
                // Group is too large.
                $errors['attendance'] = get_string('toomanyusers', 'quizaccess_group');
            }
            $memberstocheck = $data['attendance'];
        }

        // Validate that everyone in the group can make a new attempt.
        if ($memberstocheck && $this->quiz->attempts) {
            list($usersql, $params) = $DB->get_in_or_equal($memberstocheck, SQL_PARAMS_NAMED);
            $params['quiz'] = $this->quiz->id;
            $params['numattempts'] = $this->quiz->attempts;
            $sql = "SELECT userid, COUNT(attempt) AS numattempts
                    FROM {quizaccess_group_attempts}
                    WHERE userid $usersql
                    AND quiz = :quiz
                    GROUP BY userid
                    HAVING COUNT(attempt) >= :numattempts";
            $erroruserids = array_keys($DB->get_records_sql_menu($sql, $params));
            if ($erroruserids) {
                $errorusers = $DB->get_records_list('user', 'id', $erroruserids, '', 'id,'.get_all_user_name_fields(true));
                $usernames = array();
                foreach ($errorusers as $user) {
                    $usernames[] = s(fullname($user));
                }
                sort($usernames);
                $errors['attendance'] = get_string('nomoreattempsfor', 'quizaccess_group').html_writer::alist($usernames);;
            }
        }

        return $errors;
    }


    public function save_preflight_data_for_attempt($attemptid, $data) {
        global $USER;

        $attendance = null;
        $groupid = null;

        if ($this->is_group_mode_moodle()) {
            $groupid = $data->groupchoice;
            if ($this->should_take_attendance()) {
                $attendance = array();
                $members = groups_get_members($groupid, user_picture::fields('u'));
                foreach ($members as $student) {
                    if (!empty($data->{'attendance_'.$student->id})) {
                        $attendance[] = $student->id;
                    }
                }
                // Current user is present, no matter what they answer.
                if (!array_search($USER->id, $attendance)) {
                    $attendance[] = $USER->id;
                }
            }
        } else if ($this->is_group_mode_adhoc()) {
            $attendance = $data->attendance;
        }

        $this->initialize_attempt($attemptid, $groupid, $attendance);
    }

    public function prevent_new_attempt($numprevattempts, $lastattempt) {
        global $USER, $DB;
        // See if the current user has made too many attempts.
        if ($this->quiz->attempts) {
            $numattempts = $DB->count_records('quizaccess_group_attempts',
                        array('userid' => $USER->id, 'quiz' => $this->quiz->id));
            if ($numattempts >= $this->quiz->attempts) {
                return get_string('nomoreattempts', 'quiz');
            }
        }
        // See if the user is in a group, when required.
        if ($this->is_group_mode_moodle()) {
            if (0 == count($this->get_user_groups())) {
                return get_string('nogroup', 'quizaccess_group');
            }
        }
        return false;
    }

    public function allow_review_attempt(stdClass $attempt) {
        global $DB, $USER;
        if ($DB->record_exists('quizaccess_group_attempts', array('attempt'=>$attempt->id, 'userid'=>$USER->id))) {
            return true;
        }
        return null;
    }

    public function get_attempt_summary_data($attempt) {
        global $DB, $OUTPUT;

        $result = array();
        $group = quizaccess_group_lib::get_attempt_group($this->quiz->id, $attempt->id);
        if ($group) {
            $groupurl = new moodle_url('/user/index.php', array('id' => $this->quiz->course, 'group' => $group->id));
            $grouplink = html_writer::tag('a', format_string($group->name), array('href'=>$groupurl->out(false)));
            $result['quizaccess_group_group'] = array('title' => get_string('group'), 'content' => $grouplink);
        }
        $userids = quizaccess_group_lib::get_attempt_userids($this->quiz->id, $attempt->id);
        if ($userids) {
            $profileurl = new moodle_url('/user/view.php', array('course' => $this->quiz->course));
            $users = $DB->get_records_list('user', 'id', $userids,
                    'lastname,firstname', user_picture::fields());
            $title = get_string('attemptusers', 'quizaccess_group');
            foreach ($users as $student) {
                $userpicture = new user_picture($student);
                $userpicture->courseid = $this->quiz->course;
                $userlink = html_writer::tag('a', fullname($student, true), array('href'=>$profileurl->out(false, array('id'=>$student->id))));
                $result['quizaccess_group_members'.$student->id] = array(
                            'title'   => $title,
                            'content' => $OUTPUT->render($userpicture).$userlink
                    );
                // Only diplay title once.
                $title = '';
            }
        }
        return $result;
    }

    public function get_attempts_for_review() {
        global $DB, $USER;
        $sql = 'SELECT a.*, gs.groupid
                  FROM {quiz_attempts} a
                  JOIN {quizaccess_group_attempts} ga ON a.id = ga.attempt
             LEFT JOIN {quizaccess_group_state} gs ON a.id = gs.attempt
                 WHERE a.quiz = :quizid
                   AND a.state IN (:state1, :state2)
                   AND ga.userid = :userid1
                   AND a.userid != :userid2
                 ORDER BY a.timefinish ASC';
        $params = array( 'quizid'  => $this->quiz->id,
                         'userid1' => $USER->id,
                         'userid2' => $USER->id,
                         'state1'  => quiz_attempt::FINISHED,
                         'state2'  => quiz_attempt::ABANDONED);
        return $DB->get_records_sql($sql, $params);
    }

    public static function make(quiz $quizobj, $timenow, $canignoretimelimits) {

        if (empty($quizobj->get_quiz()->qa_group_groupmode)) {
            return null;
        }

        return new self($quizobj, $timenow);
    }

    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {
        global $DB, $COURSE;

        $element = $mform->createElement('header', 'groupattempts', get_string('groupsettingsheader', 'quizaccess_group'));
        $mform->insertElementBefore($element, 'security');
        unset($element); // Note that createElement returns a reference!

        // Group mode.
        $element = $mform->createElement('select', 'qa_group_groupmode', get_string('groupmode', 'quizaccess_group'),
                array(self::GROUPMODE_NONE => get_string('groupmode_none', 'quizaccess_group'),
                        self::GROUPMODE_MOODLE => get_string('groupmode_moodle', 'quizaccess_group'),
                        self::GROUPMODE_ADHOC => get_string('groupmode_adhoc', 'quizaccess_group')
                ));
        $mform->insertElementBefore($element, 'security');
        unset($element);
        $mform->addHelpButton('qa_group_groupmode', 'groupmode', 'quizaccess_group');
        $mform->setDefault('qa_group_groupmode', self::GROUPMODE_NONE);
        //if ($quizid = $quizform->get_quizid()) {
            // Existing quiz.  If there are attempts, then the group mode shouldn't be changed.
        //    if (quiz_has_attempts($quizid)) {
        //        $mform->freeze('qa_group_groupmode');
        //    }
        //}
        $options = array();
        $options[0] = get_string('none');
        if ($groupings = $DB->get_records('groupings', array('courseid'=>$COURSE->id))) {
            foreach ($groupings as $grouping) {
                $options[$grouping->id] = format_string($grouping->name);
            }
        }
        $element = $mform->createElement('select', 'qa_group_grouping', get_string('grouping', 'quizaccess_group'), $options);
        $mform->insertElementBefore($element, 'security');
        unset($element);
        $mform->addHelpButton('qa_group_grouping', 'grouping', 'quizaccess_group');
        $mform->disabledIf('qa_group_grouping', 'qa_group_groupmode', 'neq', self::GROUPMODE_MOODLE);

        $element = $mform->createElement('checkbox', 'qa_group_attendance', get_string('attendance', 'quizaccess_group'));
        $mform->insertElementBefore($element, 'security');
        unset($element);
        $mform->addHelpButton('qa_group_attendance', 'attendance', 'quizaccess_group');
        $mform->disabledIf('qa_group_attendance', 'qa_group_groupmode', 'neq', self::GROUPMODE_MOODLE);

        $options = array();
        $options[0] = get_string('none');
        for ($i = 2 ; $i <= 20; ++$i) {
            $options[$i] = $i;
        }
        $element = $mform->createElement('select', 'qa_group_maxgroupsize', get_string('maxgroupsize', 'quizaccess_group'), $options);
        $mform->insertElementBefore($element, 'security');
        unset($element);
        $mform->addHelpButton('qa_group_maxgroupsize', 'maxgroupsize', 'quizaccess_group');
        $mform->disabledIf('qa_group_maxgroupsize', 'qa_group_groupmode', 'neq', self::GROUPMODE_ADHOC);
    }

    public static function settings_form_definition_after_data(
            mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {

        $quizid = $mform->getElementValue('instance');
        if ($quizid) {
            // Don't allow groupmode to change if there are existing (nonpreview) attempts.
            if (quiz_has_attempts($quizid)) {
                $mform->freeze('qa_group_groupmode');
            }
        }
    }

    public static function save_settings($quiz) {
        global $DB;
        if (empty($quiz->qa_group_groupmode)) {
            $DB->delete_records('quizaccess_group', array('quiz' => $quiz->id));
        } else {
            $settings = $DB->get_record('quizaccess_group', array('quiz'=>$quiz->id), '*', IGNORE_MISSING);
            if (!$settings) {
                $settings = new stdClass();
                $settings->quiz = $quiz->id;
            }
            $settings->groupmode = $quiz->qa_group_groupmode;
            if (!empty($quiz->qa_group_grouping)) {
                $settings->grouping = $quiz->qa_group_grouping;
            } else {
                $settings->grouping = null;
            }
            $settings->attendance = !empty($quiz->qa_group_attendance)? 1 : 0;
            $settings->maxgroupsize = !empty($quiz->qa_group_maxgroupsize)? $quiz->qa_group_maxgroupsize : 0;
            if (empty($settings->id)) {
                $DB->insert_record('quizaccess_group', $settings);
            } else {
                $DB->update_record('quizaccess_group', $settings);
            }
        }
    }

    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_group', array('quizid' => $quiz->id));
        $DB->delete_records('quizaccess_group_attempts', array('quizid' => $quiz->id));
        $DB->delete_records('quizaccess_group_state', array('quizid' => $quiz->id));
    }

    public static function get_settings_sql($quizid) {
        return array(
            'qa_group.groupmode as qa_group_groupmode,
             qa_group.grouping as qa_group_grouping,
             qa_group.maxgroupsize as qa_group_maxgroupsize,
             qa_group.attendance as qa_group_attendance',
            'LEFT JOIN {quizaccess_group} qa_group ON qa_group.quiz = quiz.id',
            array());
    }

    public function is_group_mode_moodle() {
        return $this->quiz->qa_group_groupmode == self::GROUPMODE_MOODLE;
    }

    public function is_group_mode_adhoc() {
        return $this->quiz->qa_group_groupmode == self::GROUPMODE_ADHOC;
    }

    public function should_take_attendance() {
        return $this->is_group_mode_moodle() && !empty($this->quiz->qa_group_attendance);
    }

    protected function get_user_groups() {
        if (!isset($this->_usergroups)) {
            if (!empty($this->quiz->qa_group_grouping)) {
                $groupingid = $this->quiz->qa_group_grouping;
            } else {
                $groupingid = 0;
            }
            $groupings = groups_get_user_groups($this->quiz->course);
            if (isset($groupings[$groupingid])) {
                $this->_usergroups = $groupings[$groupingid];
            } else {
                $this->_usergroups = array();
            }
        }
        return $this->_usergroups;
    }

    protected function initialize_attempt($attemptid, $groupid = null, $attendance = null) {
        global $DB;

        if (empty($attendance) && $groupid === null) {
            throw new coding_exception('Need either groupid or attendance.');
        }

        // Use group membership if attendance is null.
        if  ($attendance === null) {
            $members = groups_get_members($groupid, 'u.id');
            $attendance = array_keys($members);
        } else {
            $attendance = array_unique($attendance);
        }

        foreach ($attendance as $userid) {
            $record = new stdClass();
            $record->quiz = $this->quiz->id;
            $record->attempt = $attemptid;
            $record->userid = $userid;
            $DB->insert_record('quizaccess_group_attempts', $record);
        }
        $state = new stdClass();
        $state->quiz = $this->quiz->id;
        $state->attempt = $attemptid;
        $state->groupid = $groupid;
        $DB->insert_record('quizaccess_group_state', $state);
    }

    public function description() {
        if ($this->is_group_mode_moodle()) {
            return get_string('descriptionmoodlegroup', 'quizaccess_group');
        } else if ($this->is_group_mode_adhoc()) {
            if (!empty($this->quiz->qa_group_maxgroupsize)) {
                return get_string('descriptionadhocwithsize', 'quizaccess_group', $this->quiz->qa_group_maxgroupsize);
            } else {
                return get_string('descriptionadhoc', 'quizaccess_group');
            }
        }

    }

    public function get_attempts_page_html() {
        global $USER, $DB, $PAGE;
        $quiz = $this->quiz;
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $course = $DB->get_record('course', array('id' => $cm->course));
        $context = context_module::instance($cm->id);
        $quizrenderer = $PAGE->get_renderer('mod_quiz');

        // This is a bit circular, but we need the access manager to construct the review links
        $accessmanager = new quiz_access_manager($this->quizobj, $this->timenow,
                has_capability('mod/quiz:ignoretimelimits', $context, null, false));

        // Get attempts by others that we can view.
        $attempts = $this->get_attempts_for_review();

        // Assemble attempt objects.
        $attemptobjs = array();
        foreach ($attempts as $attempt) {
            $attemptobjs[] = new quiz_attempt($attempt, $quiz, $cm, $course, false);
        }

        // Fetch all attempt users.
        if (!empty($attempts)) {
            $userids = array();
            foreach ($attempts as $attempt) {
                $userids[$attempt->userid] = 1;
            }
            $userids = array_keys($userids);
            $users = $DB->get_records_list('user', 'id', $userids);
        } else {
            $users = array();
        }

        // Fetch all attempt groups.
        $groups = array();
        if (!empty($attempts)) {
            $groupids = array();
            foreach ($attempts as $attempt) {
                if ($attempt->groupid) {
                    $groupids[$attempt->groupid] = 1;
                }
            }
            $groupids = array_keys($groupids);
            if ($groupids) {
                $groups = $DB->get_records_list('groups', 'id', $groupids);
            }
        }


        // Work out which columns we need, taking account what data is available in each attempt.
        list($someoptions, $alloptions) = quiz_get_combined_reviewoptions($quiz, $attempts);

        $groupcolumn    = $this->is_group_mode_moodle();
        $gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX && quiz_has_grades($quiz);
        $markcolumn     = $gradecolumn && ($quiz->grade != $quiz->sumgrades);
        $feedbackcolumn = quiz_has_feedback($quiz) && $alloptions->overallfeedback;
        $numattempts    = count($attempts);
        $canreviewmine  = has_capability('mod/quiz:reviewmyattempts', $context);
        $canpreview = has_capability('mod/quiz:preview', $context);

        // Work out the final grade, checking whether it was overridden in the gradebook.
        if (!$canpreview) {
            $mygrade = quiz_get_best_grade($quiz, $USER->id);
        } else {
            $mygrade = null;
        }

        // Prepare table header.
        $table = new html_table();
        $table->attributes['class'] = 'generaltable quizattemptsummary';
        $table->head = array();
        $table->align = array();
        $table->size = array();

        $table->head[] = get_string('pictureofuser');
        $table->align[] = 'left';
        $table->size[] = '';
        $table->head[] = get_string('user');
        $table->align[] = 'left';
        $table->size[] = '';
        if ($groupcolumn) {
            $table->head[] = get_string('group');
            $table->align[] = 'left';
            $table->size[] = '';
        }

        $table->head[] = get_string('attemptstate', 'quiz');
        $table->align[] = 'left';
        $table->size[] = '';
        if ($markcolumn) {
            $table->head[] = get_string('marks', 'quiz') . ' / ' .
                    quiz_format_grade($quiz, $quiz->sumgrades);
            $table->align[] = 'center';
            $table->size[] = '';
        }
        if ($gradecolumn) {
            $table->head[] = get_string('grade') . ' / ' .
                    quiz_format_grade($quiz, $quiz->grade);
            $table->align[] = 'center';
            $table->size[] = '';
        }
        if ($canreviewmine) {
            $table->head[] = get_string('review', 'quiz');
            $table->align[] = 'center';
            $table->size[] = '';
        }
        if ($feedbackcolumn) {
            $table->head[] = get_string('feedback', 'quiz');
            $table->align[] = 'left';
            $table->size[] = '';
        }

        // One row for each attempt.
        foreach ($attemptobjs as $attemptobj) {
            $attempt = $attemptobj->get_attempt();
            $attemptoptions = $attemptobj->get_display_options(true);
            $row = array();

            $attemptuser = $users[$attemptobj->get_userid()];
            $row[] = $quizrenderer->user_picture($attemptuser, array('size' => 50, 'courseid'=>$course->id));
            $row[] = fullname($attemptuser);
            if ($groupcolumn) {
                $row[] = format_string($groups[$attempt->groupid]->name);
            }

            $row[] = get_string('statefinished', 'quiz') . html_writer::tag('span',
                        get_string('statefinisheddetails', 'quiz',
                                userdate($attemptobj->get_submitted_date())),
                        array('class' => 'statedetails'));

            if ($markcolumn) {
                if ($attemptoptions->marks >= question_display_options::MARK_AND_MAX &&
                            $attemptobj->is_finished()) {
                        $row[] = quiz_format_grade($quiz, $attemptobj->get_sum_marks());
                    } else {
                        $row[] = '';
                    }
            }

            // Ouside the if because we may be showing feedback but not grades.
            $attemptgrade = quiz_rescale_grade($attemptobj->get_sum_marks(), $quiz, false);

            if ($gradecolumn) {
                if ($attemptoptions->marks >= question_display_options::MARK_AND_MAX &&
                        $attemptobj->is_finished()) {
                            $row[] = quiz_format_grade($quiz, $attemptgrade);
                        } else {
                            $row[] = '';
                        }
            }

            if ($canreviewmine) {
                $row[] = $accessmanager->make_review_link($attemptobj->get_attempt(),
                        $attemptoptions, $quizrenderer);
            }

            if ($feedbackcolumn && $attemptobj->is_finished()) {
                if ($attemptoptions->overallfeedback) {
                    $row[] = quiz_feedback_for_grade($attemptgrade, $quiz, $context);
                } else {
                    $row[] = '';
                }
            }

            $table->data[] = $row;
        } // End of loop over attempts.

        $output = '';
        $output .= html_writer::start_div('qa_group_review');
        if (!empty($attempts)) {
            $heading = get_string('summaryofgroupattempts', 'quizaccess_group');
            $output .= $quizrenderer->heading($heading, 3);
            $output .= html_writer::table($table);
        }
        $output .= html_writer::end_div();
        return $output;
    }

    public static function notify_quiz_update_grades($quizid, $userid = 0) {
        global $DB;
        $timenow = time();

        $quiz = \quiz_access_manager::load_quiz_and_settings($quizid);

        // Check for deleted attempts by comparing with our group attempts table.
        $sql = "SELECT gs.id, gs.attempt
                  FROM {quizaccess_group_state} gs
                  LEFT JOIN {quiz_attempts} a ON a.id = gs.attempt
                  WHERE gs.quiz = ? AND a.id IS NULL";
        $deletedattempts = $DB->get_records_sql_menu($sql, array($quizid));

        // Check for modified attempts by user which need grading.
        // In all cases with $userid != 0, the grading/state change that results in this call will be for an attempt
        // made by $userid, so we only consider those attempts.
        $params = array();
        if ($userid) {
            $usersql = "AND a.userid = :userid";
            $params['userid'] = $userid;
        } else {
            $usersql = '';
        }
        $sql = "SELECT a.id, state.id as stateid, a.sumgrades as sumgrades
                      FROM {quiz_attempts} a
                      LEFT JOIN {quizaccess_group_state} state ON state.attempt = a.id
                      WHERE a.quiz = :quizid
                            $usersql
                            AND a.state = :finished
                            AND (state.id IS NULL OR state.sumgrades IS NULL OR state.sumgrades != a.sumgrades)";
        $params['quizid'] = $quizid;
        $params['finished'] = quiz_attempt::FINISHED;
        $modifiedattempts = $DB->get_records_sql($sql, $params);

        if ($userid) {
            // Combine all additional users which need to be regraded.
            $attemptstograde = array_merge(array_keys($modifiedattempts), $deletedattempts);
            if ($attemptstograde) {
                $users = quizaccess_group_lib::get_attempt_userids($quizid, $attemptstograde);
            }
            $users[] = $userid;
        } else {
            // Otherwise we're updating all users, so we'll pass null to update_grades.
            $users = null;
        }

        $quiz = \quiz_access_manager::load_quiz_and_settings($quizid);

        // Recompute quiz grades for userids, or all.
        $updated = quizaccess_group_lib::save_best_grade($quiz, $users);

        // Remove any dangling quizaccess_group_attempts records.
        if ($deletedattempts) {
            $DB->delete_records_list('quizaccess_group_attempts', 'attempt', $deletedattempts);
            $DB->delete_records_list('quizaccess_group_state', 'attempt', $deletedattempts);
        }
        // Record attempts as graded.
        foreach ($modifiedattempts as $attemptid => $attemptstate) {
            if ($attemptstate->stateid) {
                $DB->set_field('quizaccess_group_state', 'sumgrades', $attemptstate->sumgrades, array('id'=>$attemptstate->stateid));
            } else {
                try {
                    $state = new stdClass();
                    $state->quiz = $quizid;
                    $state->attempt = $attemptid;
                    $state->sumgrades = $attemptstate->sumgrades;
                    $DB->insert_record('quizaccess_group_state', $state);
                } catch (moodle_exception $e) {
                    // Ignore duplicate key errors since there's a potential race condition in adding the state record for newly
                    // finished attempts.
                }
            }
        }
        // Now update the central gradebook.
        if ($updated && $quiz->grade) {
            if ($grades = quizaccess_group_lib::get_user_grades($quiz, $updated)) {
                quiz_grade_item_update($quiz, $grades);
            }
        }
    }
}
