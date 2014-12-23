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
 * Library for the quizaccess_group plugin.
 *
 * @package   quizaccess_group
 * @copyright 2014 The University of Wisconsin
 * @author    Matt Petro
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Library for the quizaccess_group plugin.
 *
 * Other plugins can access group quiz functions by first checking that
 * this class exists and then by calling the static methods.
 *
 * @copyright 2014 The University of Wisconsin
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_group_lib {

    /**
     * No instances allowed.  This is a class of static functions.
     */
    private function __construct() {}

    /**
     * Is the quiz in group mode?
     */
    public static function is_group_quiz($quiz) {
        return $quiz->qa_group_groupmode;
    }

    public static function update_all_grades($quiz) {
        if (!self::is_group_quiz()) {
            return;
        }
    }


/*
    public static function save_best_grade($quiz, $attempt = null, $allattempts = array()) {
        global $USER, $DB;
        if (!self::is_group_quiz($quiz)) {
            return;
        }

        $userids = self::get_attempt_userids($quiz, $attempt);
        if (!$allattempts) {
            // Get all finished group attempts for anyone in the group.
            $allattempts = self::get_group_attempts($quiz, $userids);
        }

        // Calculate the best grade.
        // These internal quiz functions don't care that the attempts have differing users.
        $bestgrade = quiz_calculate_best_grade($quiz, $allattempts);
        $bestgrade = quiz_rescale_grade($bestgrade, $quiz, false);

        // Save the best grade in the database.
        if (is_null($bestgrade)) {
            $DB->delete_records('quizaccess_group_grades', array('quiz' => $quiz->id, 'userid' => $userid));
        } else if ($grade = $DB->get_record('quizaccess_group_grades', array('quiz' => $quiz->id, 'userid' => $userid))) {
            $grade->grade = $bestgrade;
            $grade->timemodified = time();
            $DB->update_record('quizaccess_group_grades', $grade);
        } else {
            $grade = new \stdClass();
            $grade->quiz = $quiz->id;
            $grade->userid = $userid;
            $grade->grade = $bestgrade;
            $grade->timemodified = time();
            $DB->insert_record('quizaccess_group_grades', $grade);
        }

        self::update_grades($quiz, $userid);
    }
*/

    public static function get_attempt_userids($quizid, $attemptids) {
        global $DB;
        if (!is_array($attemptids)) {
            $attemptids = array($attemptids);
        }
        list($attemptsql, $attemptparams) = $DB->get_in_or_equal($attemptids);
        $userids = $DB->get_records_sql_menu(
                     "SELECT DISTINCT userid AS id, userid FROM {quizaccess_group_attempts} WHERE attempt $attemptsql", $attemptparams);
        return array_values($userids);
    }

    public static function get_attempt_group($quizid, $attemptid) {
        global $DB;
        $groupid = $DB->get_field('quizaccess_group_state', 'groupid', array('quiz'=>$quizid, 'attempt'=>$attemptid), IGNORE_MISSING);
        if (!$groupid) {
            return null;
        }
        return groups_get_group($groupid, '*', IGNORE_MISSING);
    }

    public static function get_user_grades($quiz, $userids = null) {
        global $CFG, $DB;

        $params = array();
        $params['quiz'] = $quiz->id;
        if (!is_null($userids)) {
            // When given userids, select users regardless of whether they have a grade on the quiz or not.
            // This handles the case a deleted attempt where we need to clear grades for everyone in the group.
            if (empty($userids)) {
                return array();
            }
            list ($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $params += $userparams;
            $where = "WHERE u.id $usersql";
        } else {
            // When userids is null, only select users with a grade on the quiz.
            $where = "WHERE qg.id IS NOT NULL";
        }

        return $DB->get_records_sql("
                SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

                FROM {user} u
                LEFT JOIN {quiz_grades} qg ON u.id = qg.userid AND qg.quiz = :quiz
                LEFT JOIN {quizaccess_group_attempts} ga ON u.id = ga.userid AND qg.quiz = ga.quiz
                LEFT JOIN {quiz_attempts} qa ON qa.id = ga.attempt
                $where
                GROUP BY u.id, qg.grade, qg.timemodified", $params);
    }

    /**
     * Create or update the grade item for given quiz
     *
     * @category grade
     * @param object $quiz object with extra cmidnumber
     * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
     * @return int 0 if ok, error code otherwise
     */
    public static function grade_item_update($quiz, $grades = null) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitems = \grade_get_grades($quiz->course, 'mod', 'quiz', $quiz->id);
        $primary = $gradeitems->items[0];
        $params = array();
//        $params['itemname'] = get_string('gradeitemname', 'quizaccess_group', $primary->itemname);
//        $params['idnumber'] = $primary->idnumber;
//        $params['gradetype'] = $primary->gradetype;
        $params['grademin'] = $primary->grademin;
        $params['grademax'] = $primary->grademax;
       return \grade_update('mod/quiz', $quiz->course, 'mod', 'quiz', $quiz->id, 1, $grades, $params);
    }


    public static function get_group_attempts($quiz, $userids) {
        global $DB, $USER;
        if (empty($userids)) {
            return array();
        }
        list ($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $sql = "SELECT a.*, ga.groupid
                  FROM {quiz_attempts} a
                  JOIN {quizaccess_group_attempts} ga ON a.id = ga.attempt
                 WHERE a.quiz = :quizid
                   AND a.state IN (:state1, :state2)
                   AND ga.userid $usersql
                 ORDER BY a.timefinish ASC";
        $params['quizid'] = $quiz->id;
        $params['state1'] = \quiz_attempt::FINISHED;
        $params['state2'] = \quiz_attempt::ABANDONED;
        return $DB->get_records_sql($sql, $params);
    }

    public static function save_best_grade_for_attempt($quiz, $attemptid) {
        global $DB;

        $users = self::get_attempt_userids($quiz, $attemptid);
        if (!empty($users)) {
            return self::save_best_grade($quiz, $users);
        } else {
            return array();
        }
    }

    public static function save_best_grade($quiz, $users = null) {
        global $DB;

        if (!$quiz->sumgrades) {
            return;
        }

        if (is_null($users)) {
            $users = array();
        } else if (!is_array($users)) {
            $users = array($users);
        }

        $param = array('iquizid' => $quiz->id, 'istatefinished' => quiz_attempt::FINISHED);
        switch ($quiz->grademethod) {
            case QUIZ_ATTEMPTFIRST:
            case QUIZ_ATTEMPTLAST:
                if ($users) {
                    // Restrict records considered in first/last subquery.
                    list($usersql, $userparams) = $DB->get_in_or_equal($users, SQL_PARAMS_NAMED);
                    $redundantflsql = "igroupa.userid $usersql AND";
                    $param += $userparams;
                } else {
                    $redundantflsql = '';
                }
                $firstlastattemptjoin = "JOIN (
                    SELECT
                        iquiza.userid,
                        MIN(igroupa.attempt) AS firstattempt,
                        MAX(igroupa.attempt) AS lastattempt

                    FROM {quiz_attempts} iquiza
                    JOIN {quizaccess_group_attempts} igroupa ON igroupa.attempt = iquiza.id

                    WHERE
                        $redundantflsql
                        iquiza.state = :istatefinished AND
                        iquiza.preview = 0 AND
                        iquiza.quiz = :iquizid

                    GROUP BY igroupa.userid
                ) first_last_attempts ON first_last_attempts.userid = quiza.userid";

                // Because of the where clause, there will only be one row, but we
                // must still use an aggregate function.
                $select = 'MAX(quiza.sumgrades)';
                $join = $firstlastattemptjoin;
                if ($quiz->grademethod == QUIZ_ATTEMPTFIRST) {
                    $where = 'quiza.attempt = first_last_attempts.firstattempt AND';
                } else {
                    $where = 'quiza.attempt = first_last_attempts.lastattempt AND';
                }
                break;

            case QUIZ_GRADEAVERAGE:
                $select = 'AVG(quiza.sumgrades)';
                $join = '';
                $where = '';
                break;

            default:
            case QUIZ_GRADEHIGHEST:
                $select = 'MAX(quiza.sumgrades)';
                $join = '';
                $where = '';
                break;
        }

        if ($quiz->sumgrades >= 0.000005) {
            $finalgrade = $select . ' * ' . ($quiz->grade / $quiz->sumgrades);
        } else {
            $finalgrade = '0';
        }
        if ($users) {
            // Restrict records considered in finalgrade subquery.
            list($usersql, $userparams) = $DB->get_in_or_equal($users, SQL_PARAMS_NAMED);
            $redundantfgsql = "groupa.userid $usersql AND";
            $param += $userparams;
        } else {
            $redundantfgsql = '';
        }
        $finalgradesubquery = "
                SELECT groupa.userid, $finalgrade AS newgrade
                FROM {quiz_attempts} quiza
                JOIN {quizaccess_group_attempts} groupa ON groupa.attempt = quiza.id
                $join
                WHERE
                    $where
                    $redundantfgsql
                    quiza.state = :statefinished AND
                    quiza.preview = 0 AND
                    quiza.quiz = :quizid3
                GROUP BY groupa.userid";

        $usertablesql = '
                SELECT userid
                FROM {quiz_grades} qg
                WHERE quiz = :quizid
            UNION
                SELECT DISTINCT userid
                FROM {quizaccess_group_attempts} groupa2
                WHERE
                    groupa2.quiz = :quizid1';

        if ($users) {
            list($usersql, $userparams) = $DB->get_in_or_equal($users, SQL_PARAMS_NAMED);
            $usersql = "users.userid $usersql AND";
            $param += $userparams;
        } else {
            $usersql = "";
        }

        $param['quizid3'] = $quiz->id;
        $param['quizid4'] = $quiz->id;
        $param['quizid'] = $quiz->id;
        $param['quizid1'] = $quiz->id;
        $param['statefinished2'] = quiz_attempt::FINISHED;

        $param['quizid3'] = $quiz->id;
        $param['quizid4'] = $quiz->id;
        $param['statefinished'] = quiz_attempt::FINISHED;
        $changedgrades = $DB->get_records_sql("
                SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

                FROM ($usertablesql) users
                LEFT JOIN {quiz_grades} qg ON qg.userid = users.userid AND qg.quiz = :quizid4

                LEFT JOIN (
                    $finalgradesubquery
                ) newgrades ON newgrades.userid = users.userid

                WHERE
                    $usersql
                    ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                    ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                              (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                    // The mess on the previous line is detecting where the value is
                    // NULL in one column, and NOT NULL in the other, but SQL does
                    // not have an XOR operator, and MS SQL server can't cope with
                    // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
                $param);

        $timenow = time();
        $todelete = array();
        foreach ($changedgrades as $changedgrade) {

            if (is_null($changedgrade->newgrade)) {
                $todelete[] = $changedgrade->userid;

            } else if (is_null($changedgrade->grade)) {
                $toinsert = new stdClass();
                $toinsert->quiz = $quiz->id;
                $toinsert->userid = $changedgrade->userid;
                $toinsert->timemodified = $timenow;
                $toinsert->grade = $changedgrade->newgrade;
                $DB->insert_record('quiz_grades', $toinsert);

            } else {
                $toupdate = new stdClass();
                $toupdate->id = $changedgrade->id;
                $toupdate->grade = $changedgrade->newgrade;
                $toupdate->timemodified = $timenow;
                $DB->update_record('quiz_grades', $toupdate);
            }
        }

        if (!empty($todelete)) {
            list($test, $params) = $DB->get_in_or_equal($todelete);
            $DB->delete_records_select('quiz_grades', 'quiz = ? AND userid ' . $test,
                    array_merge(array($quiz->id), $params));
        }

        $toreturn = array();
        foreach ($changedgrades as $changedgrade) {
            $toreturn[] = $changedgrade->userid;
        }
        return $toreturn;
    }
}