<?php
// This file is part of local/coursedeletion
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
 * @package local/coursedeletion
 * @copyright 2014-2018 Liip AG <https://www.liip.ch/>
 * @author Brian King <brian.king@liip.ch>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class CourseDeletionTest extends PHPUnit_Framework_TestCase {

    protected $testcourse;

    public function testNewCourse() {
        global $DB;
        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED, $rec->status, "status: scheduled");
        $this->assertEquals(CourseDeletion::default_course_end_date(), $rec->enddate, "enddate set");
    }

    public function testCourseStatusChangesToNotified() {
        global $DB;
        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED, $rec->status, "status scheduled");
        $rec->enddate = 1;
        $DB->update_record('local_coursedeletion', $rec);
        $this->run_cron();
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED_NOTIFIED, $rec->status, "status changed to notified");
    }

    /**
     * Test updates to a course in phase 1 (not yet notified).
     */
    public function testUpdateFromFormForNewCourse() {
        global $DB;

        $cd = new CourseDeletion();
        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $baseenddate = CourseDeletion::midnight(null, $rec->enddate);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);

        // Setting the enddate back one week should work
        $enddate = clone($baseenddate);
        $newenddate = $enddate->sub(CourseDeletion::interval('P1W'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertEquals($newenddate, $rec->enddate, "enddate set to requested value");

        // Setting the enddate forward one month should work
        $enddate = clone($baseenddate);
        $newenddate = $enddate->add(CourseDeletion::interval('P1M'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertEquals($newenddate, $rec->enddate, "enddate set to requested value");

        // Setting the enddate back to a date earlier than the date that when the expiry
        // notification mail would be sent:
        // * minimum date should be enforced
        // * enddate should be set so that phase 2 will be entered on the next day
        $newenddate = CourseDeletion::midnight(CourseDeletion::interval_until_staging())
            ->sub(CourseDeletion::interval('P1W'))->getTimestamp();
        $expectedenddate = CourseDeletion::midnight(CourseDeletion::interval_until_staging())
            ->add(CourseDeletion::interval('P1D'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertNotEmpty($info['minimum_date_forced'], "minimum_date_forced should be set to a unix timestamp");
        $this->assertEquals($expectedenddate, $rec->enddate,
            "enddate: expected: " . strftime('%Y-%m-%d', $expectedenddate) . ", actual: " . strftime('%Y-%m-%d', $rec->enddate)
        );
    }

    /**
     * Test updates to a course in phase 2 (already notified, not yet moved to trash).
     */
    public function testUpdateFromFormForNotifiedCourse() {
        global $DB;
        $cd = new CourseDeletion(null, 0);

        $this->testcourse = create_course($this->default_course_data('testtest'));
        $baserec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));

        // setup: make today be seven days before deletion staging and adjust status
        $baseenddate = CourseDeletion::midnight(CourseDeletion::interval_until_staging())
            ->sub(CourseDeletion::interval('P1W'));
        $baserec->enddate = $baseenddate->getTimestamp();
        $baserec->status = CourseDeletion::STATUS_SCHEDULED_NOTIFIED;

        // Test update to a past date.
        //
        // * minimum date should be forced
        // * enddate should not change
        // * status should remain STATUS_SCHEDULED_NOTIFIED
        // * no mail should be triggered
        $rec = clone($baserec);
        $enddate = clone($baseenddate);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);
        $oldendate = $rec->enddate;
        $formvalues->deletionstagedate = $enddate->sub(CourseDeletion::interval('P3M'))->getTimestamp();
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertNotEmpty($info['minimum_date_forced'], "minimum_date_forced should be set to a unix timestamp (2)");
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED_NOTIFIED, $rec->status, "Past");
        $this->assertEquals($oldendate, $rec->enddate, "Date not changed");
        $this->assertEmpty($info['trigger_mail'], "No mail should be triggered");

        // Test update to a future date that is before the current end date.
        // to near future date:
        // * minimum date should be forced
        // * enddate should not change
        // * status should remain STATUS_SCHEDULED_NOTIFIED
        // * no mail should be triggered
        $rec = clone($baserec);
        $enddate = clone($baseenddate);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);
        $oldendate = $rec->enddate;
        $formvalues->deletionstagedate = $enddate->sub(CourseDeletion::interval('P3D'))->getTimestamp();
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertNotEmpty($info['minimum_date_forced'], "minimum_date_forced should be set to a unix timestamp");
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED_NOTIFIED, $rec->status, "Near future");
        $this->assertEquals($oldendate, $rec->enddate, "Date not changed");
        $this->assertEmpty($info['trigger_mail'], "No mail should be triggered");

        // Test update to a future date that is after the current end date, but less
        // <number of days in phase 2> in the future.
        //
        // * enddate should be set to requested end date
        // * status should still be STATUS_SCHEDULED_NOTIFIED
        // * mail should be triggered
        $rec = clone($baserec);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);
        $newenddate = CourseDeletion::date_course_will_be_staged_for_deletion($rec->enddate)->add(CourseDeletion::interval('P1D'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertEquals($rec->status, CourseDeletion::STATUS_SCHEDULED_NOTIFIED, "Near future 2");
        $this->assertEquals(CourseDeletion::MAIL_WILL_BE_STAGED_FOR_DELETION, $info['trigger_mail'], "Mail would be triggered");
        $this->assertEquals($newenddate, $rec->enddate,
            sprintf("enddate: requested: %s, actual: %s", strftime('%Y-%m-%d', $newenddate), strftime('%Y-%m-%d', $rec->enddate)));

        // Test update to a future date that is after the current end date, and at least
        // <number of days in phase 2> in the future.
        //
        // * enddate should be set to requested end date
        // * status should be set to STATUS_SCHEDULED
        $rec = clone($baserec);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);
        $newenddate = CourseDeletion::date_course_will_be_staged_for_deletion($rec->enddate)
            ->add(CourseDeletion::interval_until_staging())->add(CourseDeletion::interval('P2D'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertEquals($newenddate, $rec->enddate,
            sprintf("enddate: requested: %s, actual: %s", strftime('%Y-%m-%d', $newenddate), strftime('%Y-%m-%d', $rec->enddate)));
        $this->assertEquals($rec->status, CourseDeletion::STATUS_SCHEDULED, "Far future: status should be reset to scheduled");

        // Setting the enddate back to a date earlier than the date that when the expiry
        // notification mail would be sent:
        // * minimum date should be enforced
        // * enddate should be set so that phase 2 will be entered on the next day
        $newenddate = CourseDeletion::midnight(CourseDeletion::interval_until_staging())
            ->sub(CourseDeletion::interval('P1W'))->getTimestamp();
        $expectedenddate = CourseDeletion::midnight(CourseDeletion::interval_until_staging())
            ->add(CourseDeletion::interval('P1D'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertNotEmpty($info['minimum_date_forced'], "minimum_date_forced should be set to a unix timestamp");
        $this->assertEquals($expectedenddate, $rec->enddate,
            "enddate: expected: " . strftime('%Y-%m-%d', $expectedenddate) . ", actual: " . strftime('%Y-%m-%d', $rec->enddate)
        );
    }

    /**
     * Test updates to a course in phase 3 (already moved to trash).
     */
    public function testUpdateFromFormForStagedCourse() {
        global $DB;
        $cd = new CourseDeletion(null, 0);

        $this->testcourse = create_course($this->default_course_data('testtest'));
        $baserec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));

        // setup: make today be seven days before final deletion
        $baseenddate = CourseDeletion::midnight(CourseDeletion::interval_before_deletion(true))
            ->add(CourseDeletion::interval('P1W'));
        $baserec->enddate = $baseenddate->getTimestamp();
        $baserec->status = CourseDeletion::STATUS_STAGED_FOR_DELETION;

        // put the course in the deletion staging category
        move_courses(array($this->testcourse->id), $cd->deletion_staging_category_id());

        // Test update to to future date:
        // * status should stay as STATUS_STAGED_FOR_DELETION
        // * mail should be triggered
        // * new date should be accepted
        $rec = clone($baserec);
        $enddate = clone($baseenddate);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);
        $newenddate = $enddate->add(CourseDeletion::interval('P3M'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertEquals(CourseDeletion::STATUS_STAGED_FOR_DELETION, $rec->status, "Future");
        $this->assertEquals('mail_will_be_deleted_soon', $info['trigger_mail'], "Mail would be triggered");
        $this->assertEquals($newenddate, $rec->enddate,
            sprintf("enddate: requested: %s, actual: %s", strftime('%Y-%m-%d', $newenddate), strftime('%Y-%m-%d', $rec->enddate)));

        // Test update to a date before the current end date
        // * status should stay as STATUS_STAGED_FOR_DELETION
        // * no mail should be triggered
        // * enddate should not change
        $rec = clone($baserec);
        $enddate = clone($baseenddate);
        $formvalues = $this->form_values_from_coursedeletion_record($rec);
        $oldenddate = $rec->enddate;
        $newenddate = $enddate->sub(CourseDeletion::interval('P2D'))->getTimestamp();
        $formvalues->deletionstagedate = $newenddate;
        $info = CourseDeletion::update_from_form($rec, $formvalues, $cd);
        $this->assertEquals(CourseDeletion::STATUS_STAGED_FOR_DELETION, $rec->status, "Past");
        $this->assertEquals($oldenddate, $rec->enddate,
            sprintf("enddate should not change: requested: %s, actual: %s, previous: %s",
                strftime('%Y-%m-%d', $newenddate), strftime('%Y-%m-%d', $rec->enddate), strftime('%Y-%m-%d', $oldenddate)));
        $this->assertEmpty($info['trigger_mail'], "No mail should be triggered");

    }


    /**
     * Test a course in phase 3 (already moved to trash), that is removed from the trash.
     * After cron run, it should have it's status reset.
     */
    public function testUnstagedCourse() {
        global $DB;

        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));

        // setup: make today be seven days after deletion staging and adjust status
        $baseenddate = CourseDeletion::midnight(CourseDeletion::interval('P1W', true));
        $rec->enddate = $baseenddate->getTimestamp();
        $rec->status = CourseDeletion::STATUS_STAGED_FOR_DELETION;
        $DB->update_record('local_coursedeletion', $rec);

        // The course is now not in the deletion staging category, but has STATUS_STAGED_FOR_DELETION.
        // This is the same situation it would be in if it was staged for deletion, but someone
        // moved it out to another category.
        $this->run_cron();
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED, $rec->status, "status is scheduled");
        $this->assertEquals(CourseDeletion::default_course_end_date(), $rec->enddate, "enddate reset");
    }

    /**
     * Test that a course in the Trash, that has auto-deletion turned off, doesn't
     * get deleted when the cron runs.
     */
    public function testNoAutoDeleteTrashedCourse() {
        global $DB;
        $cd = new CourseDeletion(null, 0);

        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));

        // setup: make today be seven days after deletion date and adjust status
        $baseenddate = CourseDeletion::midnight(CourseDeletion::interval_before_deletion(true))->sub(CourseDeletion::interval('P1W'));
        $rec->enddate = $baseenddate->getTimestamp();
        $rec->status = CourseDeletion::STATUS_NOT_SCHEDULED;

        // put the course in the deletion staging category
        move_courses(array($this->testcourse->id), $cd->deletion_staging_category_id());

        $this->run_cron();
        $course = $DB->get_record('course', array('id' => $this->testcourse->id));
        $this->assertNotEmpty($course, "course still exists");
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED, $rec->status, "Status was reset");
    }

    public function testCourseDeletionCron() {
        global $DB;
        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));

        // When the end of phase 1 is reached:
        // * status should change to notified
        // * enddate should be 3 weeks (or as config-ed) from now
        $rec->enddate = CourseDeletion::midnight_timestamp(CourseDeletion::interval_until_staging(true));
        $DB->update_record('local_coursedeletion', $rec);
        $this->run_cron();
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED_NOTIFIED, $rec->status, "Status was changed to notified");
        $expectedenddate = CourseDeletion::midnight_timestamp(CourseDeletion::interval_until_staging());
        $this->assertEquals($expectedenddate, $rec->enddate,
            sprintf("enddate: expected: %s, actual: %s", strftime('%Y-%m-%d', $expectedenddate), strftime('%Y-%m-%d', $rec->enddate)));

        // When the end of phase 2 is reached:
        // * course status -> staged_for_deletion
        // * course is moved to trash category
        // * full notification period remains before course is scheduled for deletion
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $newenddate = CourseDeletion::midnight_timestamp();
        $rec->enddate = $newenddate;
        $DB->update_record('local_coursedeletion', $rec);
        $this->run_cron();
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_STAGED_FOR_DELETION, $rec->status, "Status was changed to staged");
        $this->assertEquals($newenddate, $rec->enddate,
            sprintf("enddate: expected: %s, actual: %s", strftime('%Y-%m-%d', $newenddate), strftime('%Y-%m-%d', $rec->enddate)));

        // When the end of phase 3 is reached:
        // course is deleted
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $newenddate = CourseDeletion::midnight_timestamp(CourseDeletion::interval_before_deletion(true));
        $rec->enddate = $newenddate;
        $DB->update_record('local_coursedeletion', $rec);
        $this->run_cron();
        $course = $DB->get_record('course', array('id' => $rec->courseid));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEmpty($course, "Course should have been deleted");
        $this->assertEmpty($rec, "Coursedeletion record should have been deleted");
    }

    /**
     * Test that the notification periods are respected even if the course enddate is earlier than
     * expected.  This can happen if the cron didn't run for a while.
     */
    public function testCourseDeletionLateCron() {
        global $DB;
        $this->testcourse = create_course($this->default_course_data('testtest'));
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));

        // When the end of phase 1 is reached:
        // * status should change to notified
        // * enddate should be 3 weeks (or as config-ed) from now
        $rec->enddate = CourseDeletion::midnight_timestamp();
        $DB->update_record('local_coursedeletion', $rec);
        $this->run_cron();
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_SCHEDULED_NOTIFIED, $rec->status, "Status was changed to notified");
        $expectedenddate = CourseDeletion::midnight_timestamp(CourseDeletion::interval_until_staging());
        $this->assertEquals($expectedenddate, $rec->enddate,
            sprintf("enddate: expected: %s, actual: %s", strftime('%Y-%m-%d', $expectedenddate), strftime('%Y-%m-%d', $rec->enddate)));

        // When the end of phase 2 is reached:
        // * course status -> staged_for_deletion
        // * course is moved to trash category
        // * enddate should be today
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $newenddate = CourseDeletion::midnight(CourseDeletion::interval_before_deletion(true))->sub(CourseDeletion::interval('P1M'));
        $expectedenddate = CourseDeletion::midnight_timestamp();
        $rec->enddate = $newenddate->getTimestamp();
        $DB->update_record('local_coursedeletion', $rec);
        $this->run_cron();
        $rec = $DB->get_record('local_coursedeletion', array('courseid' => $this->testcourse->id));
        $this->assertEquals(CourseDeletion::STATUS_STAGED_FOR_DELETION, $rec->status, "Status was changed to staged");
        $this->assertEquals($expectedenddate, $rec->enddate,
            sprintf("enddate: expected: %s, actual: %s", strftime('%Y-%m-%d', $expectedenddate), strftime('%Y-%m-%d', $rec->enddate)));
    }

    public function setUp() {
        // hacky stuff to make it work without using a separate test db.
        // Recreate the db connection before each test.
        unset ($GLOBALS['DB']);
        setup_DB();
    }

    public function tearDown() {
        global $DB;
        if ($this->testcourse && $DB->record_exists('course', array('id' => $this->testcourse->id))) {
            delete_course($this->testcourse, false);
        }
    }

    static function tearDownAfterClass() {
        // hacky stuff to make it work without using a separate test db.
        // recreate db connection before moodle runs it's shutdown, which may access the db.
        unset ($GLOBALS['DB']);
        setup_DB();
    }

    protected function default_course_data($shortname) {
        global $CDTESTCONFIG;
        $course = new stdClass;
        $course->fullname = 'Test';
        $course->shortname = $shortname . uniqid();
        $course->category = $CDTESTCONFIG->category;
        return $course;
    }

    protected function form_values_from_coursedeletion_record($rec) {
        $formvalues = new stdClass();
        $formvalues->courseid = $rec->courseid;
        $formvalues->deletionstagedate = $rec->enddate;
        $formvalues->scheduledeletion = $rec->status == CourseDeletion::STATUS_NOT_SCHEDULED ? 0 : 1;
        return $formvalues;
    }

    protected function run_cron() {
        $task = new local_coursedeletion\task\workflow();
        $task->execute(true, 0);
    }
}
