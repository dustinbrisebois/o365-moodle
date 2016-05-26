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
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

namespace local_o365\feature\usergroups;

class coursegroups {
    protected $graphclient;
    protected $DB;
    protected $debug = false;

    /**
     * Constructor.
     *
     * @param \local_o365\rest\unified $graphclient A graph API client to use.
     * @param \moodle_database $DB An active database connection.
     * @param bool $debug Whether to ouput debug messages.
     */
    public function __construct(\local_o365\rest\unified $graphclient, \moodle_database $DB, $debug = false) {
        $this->graphclient = $graphclient;
        $this->DB = $DB;
        $this->debug = $debug;
    }

    /**
     * Optionally run mtrace() based on $this->debug setting.
     *
     * @param string $msg The debug message.
     */
    protected function mtrace($msg, $eol = "\n") {
        if ($this->debug === true) {
            mtrace($msg, $eol);
        }
    }

    /**
     * Create groups and populate membership for all courses that don't have an associated group recorded.
     */
    public function create_groups_for_new_courses() {
        $siterec = $this->DB->get_record('course', ['id' => SITEID]);
        $groupprefix = (!empty($siterec)) ? $siterec->shortname : '';

        $creategroups = get_config('local_o365', 'creategroups');
        if ($creategroups === 'onall' || $creategroups === 'oncustom') {
            $coursesenabled = \local_o365\feature\usergroups\utils::get_enabled_courses();
            if (empty($coursesenabled)) {
                $this->mtrace('Custom group creation is enabled, but no courses are enabled.');
                return false;
            }
        } else {
            $this->mtrace('Group creation is disabled.');
            return false;
        }

        $sql = 'SELECT crs.*
                  FROM {course} crs
             LEFT JOIN {local_o365_objects} obj ON obj.type = ? AND obj.subtype = ? AND obj.moodleid = crs.id
                 WHERE obj.id IS NULL AND crs.id != ?';
        $params = ['group', 'course', SITEID];
        if (is_array($coursesenabled)) {
            list($coursesinsql, $coursesparams) = $this->DB->get_in_or_equal($coursesenabled);
            $sql .= ' AND crs.id '.$coursesinsql;
            $params = array_merge($params, $coursesparams);
        }
        $courses = $this->DB->get_recordset_sql($sql, $params, 0, 5);
        $coursesprocessed = 0;
        foreach ($courses as $course) {

            $coursesprocessed++;
            try {
                $objectrec = $this->create_group($course, $groupprefix);
            } catch (\Exception $e) {
                $this->mtrace('Could not create group for course #'.$course->id.'. Reason: '.$e->getMessage());
                continue;
            }

            try {
                $this->resync_group_membership($course->id, $objectrec['objectid'], []);
            } catch (\Exception $e) {
                $this->mtrace('Could not sync users to group for course #'.$course->id.'. Reason: '.$e->getMessage());
                continue;
            }
        }
        if (empty($coursesprocessed)) {
            $this->mtrace('All courses have a group recorded.');
        } else {
            $this->mtrace('Processed courses: '.$coursesprocessed);
        }
        $courses->close();
    }

    /**
     * Create an Office 365 unified group for a Moodle course.
     *
     * @param stdClass $course A course record.
     * @param string $groupprefix A string to prefix group names and mailNicknames.
     * @return array Array form of the created local_o365_objects record.
     */
    public function create_group($course, $groupprefix = null) {
        $now = time();
        $groupname = $course->fullname;
        if (!empty($groupprefix)) {
            $groupname = $groupprefix.': '.$groupname;
        }

        $groupshortname = $course->shortname;
        if (!empty($groupprefix)) {
            $mailnickprefix = \core_text::strtolower($groupprefix);
            $mailnickprefix = preg_replace('/[^a-z0-9]+/iu', '', $mailnickprefix);
            $groupshortname = $mailnickprefix.'_'.$groupshortname;
        }

        $extra = null;
        if (!empty($course->summary)) {
            $extra = [
                'description' => $course->summary,
            ];
        }
        try {
            $response = $this->graphclient->create_group($groupname, $groupshortname, $extra);
        } catch (\Exception $e) {
            $this->mtrace('Could not create group for course #'.$course->id.'. Reason: '.$e->getMessage());
            return false;
        }

        $this->mtrace('Created group '.$response['id'].' for course #'.$course->id);
        $objectrec = [
            'type' => 'group',
            'subtype' => 'course',
            'objectid' => $response['id'],
            'moodleid' => $course->id,
            'o365name' => $groupname,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $objectrec['id'] = $this->DB->insert_record('local_o365_objects', (object)$objectrec);
        $this->mtrace('Recorded group object ('.$objectrec['objectid'].') into object table with record id '.$objectrec['id']);
        return $objectrec;
    }

    /**
     * Resync the membership of a course group based on the users enrolled in the associated course.
     *
     * @param int $courseid The ID of the course.
     * @param string $groupobjectid The object ID of the office 365 group.
     */
    public function resync_group_membership($courseid, $groupobjectid = null, $currentmembers = null) {
        $this->mtrace('Syncing group membership for course #'.$courseid);

        if ($groupobjectid === null) {
            $params = [
                'type' => 'group',
                'subtype' => 'course',
                'moodleid' => $courseid,
            ];
            $objectrec = $this->DB->get_record('local_o365_objects', $params);
            if (empty($objectrec)) {
                $errmsg = 'Could not find group object ID in local_o365_objects for course '.$courseid.'. ';
                $errmsg .= 'Please ensure group exists first.';
                $this->mtrace($errmsg);
                return false;
            }
            $groupobjectid = $objectrec->objectid;
        }

        $this->mtrace('Syncing to group "'.$groupobjectid.'"');

        // Get current group membership (if not already provided).
        if ($currentmembers === null || !is_array($currentmembers)) {
            $members = $this->graphclient->get_group_members($groupobjectid);
            $currentmembers = [];
            foreach ($members['value'] as $member) {
                $currentmembers[$member['id']] = $member['id'];
            }
        }

        // Get list of users enrolled in the course. These are our intended group members.
        $intendedmembers = [];
        $coursecontext = \context_course::instance($courseid);
        list($esql, $params) = get_enrolled_sql($coursecontext);
        $sql = "SELECT u.id,
                       tok.oidcuniqid as userobjectid
                  FROM {user} u
                  JOIN ($esql) je ON je.id = u.id
                  JOIN {auth_oidc_token} tok ON tok.username = u.username AND tok.resource = :tokresource
                 WHERE u.deleted = 0";
        $params['tokresource'] = 'https://graph.windows.net';
        $enrolled = $this->DB->get_recordset_sql($sql, $params);
        foreach ($enrolled as $user) {
            $intendedmembers[$user->userobjectid] = $user->id;
        }
        $enrolled->close();

        if (!empty($currentmembers)) {
            // Diff current and intended members in each direction to determine toadd and toremove lists.
            $toadd = array_diff_key($intendedmembers, $currentmembers);
            $toremove = array_diff_key($currentmembers, $intendedmembers);
        } else {
            // No current group members. Add all intended members, no need to remove anyone.
            $toadd = $intendedmembers;
            $toremove = [];
        }

        // Remove users.
        $this->mtrace('Users to remove: '.count($toremove));
        foreach ($toremove as $userobjectid) {
            $this->mtrace('... Removing '.$userobjectid.'...', '');
            $result = $this->graphclient->remove_member_from_group($groupobjectid, $userobjectid);
            if ($result === true) {
                $this->mtrace('Success!');
            } else {
                $this->mtrace('Error!');
                $this->mtrace('...... Received: '.\local_o365\utils::tostring($result));
            }
        }

        // Add users.
        $this->mtrace('Users to add: '.count($toadd));
        foreach ($toadd as $userobjectid => $moodleuserid) {
            $this->mtrace('... Adding '.$userobjectid.' (muserid: '.$moodleuserid.')...', '');
            $result = $this->graphclient->add_member_to_group($groupobjectid, $userobjectid);
            if ($result === true) {
                $this->mtrace('Success!');
            } else {
                $this->mtrace('Error!');
                $this->mtrace('...... Received: '.\local_o365\utils::tostring($result));
            }
        }

        $this->mtrace('Done');

        return [$toadd, $toremove];
    }

    /**
     * Helper function to retrieve study group object.
     *
     * @param int $groupid Id of Moodle group.
     * @return object Object containing o365 object id.
     */
    public static function get_study_group_object($groupid) {
        global $DB;
        $params = [
            'type' => 'group',
            'subtype' => 'usergroup',
            'moodleid' => $groupid
        ];
        $object = $DB->get_record('local_o365_objects', $params);
        if (empty($object)) {
            return false;
        }
        return $object;
    }

    /**
     * Update a study group from a Moodle group.
     *
     * @param int $moodlegroupid Id of Moodle course group.
     * @return boolean True on success.
     */
    public function update_study_group($moodegroupid) {
        global $DB;
        $caller = 'update_study_group';
        if (\local_o365\utils::is_configured() !== true || \local_o365\feature\usergroups\utils::is_enabled() !== true) {
            return false;
        }

        if (empty($this->graphclient)) {
            return false;
        }

        $grouprec = $DB->get_record('groups', ['id' => $moodegroupid]);
        if (empty($grouprec)) {
            \local_o365\utils::debug('Could not find group with id "'.$usergroupid.'"', $caller);
            return false;
        }

        $courserec = $DB->get_record('course', ['id' => $grouprec->courseid]);
        if (empty($courserec)) {
            $msg = 'Could not find course with id "'.$grouprec->courseid.'" for group with id "'.$moodegroupid.'"';
            \local_o365\utils::debug($msg, $caller);
            return false;
        }

        // Keep local_o365_coursegroupdata in sync with groups table.
        $o365grouprec = $DB->get_record('local_o365_coursegroupdata', ['groupid' => $moodegroupid, 'courseid' => $grouprec->courseid]);
        if (empty($o365grouprec)) {
            $msg = 'Could not find local_o365_coursegroupdata record with with course "'.$grouprec->courseid.'" for group with id "'.$moodegroupid.'"';
            \local_o365\utils::debug($msg, $caller);
            return false;
        }
        $o365grouprec->displayname = $grouprec->name;
        $o365grouprec->description = $grouprec->description;
        $o365grouprec->descriptionformat = $grouprec->descriptionformat;
        $o365grouprec->timemodified = $grouprec->timemodified;
        $updatephoto = false;
        if ($o365grouprec->picture != $grouprec->picture) {
            // Picture has changed.
            $updatephoto = true;
            $o365grouprec->picture = $grouprec->picture;
        }
        $DB->update_record('local_o365_coursegroupdata', $o365grouprec);

        $o365groupname = $courserec->shortname.': '.$grouprec->name;

        $object = self::get_study_group_object($moodegroupid);
        if (empty($object->objectid)) {
            \local_o365\utils::debug('Could not find o365 object for moodle group with id "'.$usergroupid.'"', $caller);
            return false;
        }

        $groupdata = [
            'id'          => $object->objectid,
            'displayName' => $o365groupname,
            'description' => $grouprec->description,
        ];

        // Update o365 group.
        try {
            $o365group = $this->graphclient->update_group($groupdata);
        } catch (\Exception $e) {
            \local_o365\utils::debug('Updating of study group for Moodle group "'.$usergroupid.'" failed: '.$e->getMessage(), $caller);
            return false;
        }

        if ($updatephoto) {
            $this->update_study_group_photo($grouprec, $object->objectid);
        }
        return true;
    }

    /**
     * Update study group photo.
     *
     * @param object $group Moodle group object.
     * @param string $o365groupid Office 365 object id for group to update.
     * @return boolean True on success.
     */
    public function update_study_group_photo($group, $o365groupid) {
        $caller = 'update_study_group_photo';
        // Update o365 group photo.
        try {
             // Get file.
            $context = \context_course::instance($group->courseid);
            $fs = get_file_storage();
            $fileinfo = [
                'component' => 'group',
                'filearea' => 'icon',
                'itemid' => $group->id,
                'contextid' => $context->id,
                'filepath' => '/',
                'filename' => 'f3.jpg'
            ];
            $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                                  $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
            if ($file) {
                $photo = $file->get_content();
            } else {
                $fileinfo['filename'] = 'f3.png';
                $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                                      $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
                if ($file) {
                    $photo = $file->get_content();
                } else {
                    // Photo will be set to the default.
                    $photo = '';
                }
            }

            $result = $this->graphclient->upload_group_photo($o365groupid, $photo);
            if (!empty($result)) {
                // If a response has returned than an error has occured.
                \local_o365\utils::debug('Update study group photo: "'.$group->id.'" '.$result, $caller);
                return false;
            }
        } catch (\Exception $e) {
            \local_o365\utils::debug('Update study group photo: "'.$group->id.'" Error:'.$e->getMessage(), $caller);
            return false;
        }
        return true;
    }

    /**
     * Create a study group from a Moodle group.
     *
     * @param int $moodlegroupid Id of Moodle course group.
     * @return object|boolean False on failure, o365 object on success.
     */
    public function create_study_group($moodegroupid) {
        global $DB;
        $caller = 'create_study_group';
        if (\local_o365\utils::is_configured() !== true || \local_o365\feature\usergroups\utils::is_enabled() !== true) {
            return false;
        }

        if (empty($this->graphclient)) {
            return false;
        }

        $grouprec = $DB->get_record('groups', ['id' => $moodegroupid]);
        if (empty($grouprec)) {
            \local_o365\utils::debug('Could not find group with id "'.$usergroupid.'"', $caller);
            return false;
        }

        $courserec = $DB->get_record('course', ['id' => $grouprec->courseid]);
        if (empty($courserec)) {
            $msg = 'Could not find course with id "'.$grouprec->courseid.'" for group with id "'.$usergroupid.'"';
            \local_o365\utils::debug($msg, $caller);
            return false;
        }

        $o365groupname = $courserec->shortname.': '.$grouprec->name;

        $extra = [
            'description' => $grouprec->description
        ];

        // Create o365 group.
        try {
            $o365group = $this->graphclient->create_group($o365groupname, null, $extra);
        } catch (\Exception $e) {
            return false;
        }

        // Create course group data.
        $data = new \stdClass();
        $now = time();
        $data->displayname = $grouprec->name;
        $data->description = $grouprec->description;
        $data->descriptionformat = $grouprec->descriptionformat;
        $data->groupid = $grouprec->id;
        $data->courseid = $grouprec->courseid;
        // Pictures will be synced on a cron job after the group is provisioned on office 365.
        $data->picture = 0;
        $data->timecreated = $now;
        $data->timemodified = $now;
        $DB->insert_record('local_o365_coursegroupdata', $data);

        // Store group in database.
        $now = time();
        $rec = [
            'type' => 'group',
            'subtype' => 'usergroup',
            'moodleid' => $moodegroupid,
            'objectid' => $o365group['id'],
            'o365name' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('local_o365_objects', $rec);
        return (object)$rec;
    }

    /**
     * When a Moodle group is created the profile photo cannot be uploaded as the group is not provisioned.
     */
    public function sync_group_profile_photo() {
        global $DB;
        $siterec = $this->DB->get_record('course', ['id' => SITEID]);
        $groupprefix = (!empty($siterec)) ? $siterec->shortname : '';

        $sql = 'SELECT g.*, obj.objectid, cgd.id cgdid
                  FROM {groups} g,
                       {local_o365_objects} obj,
                       {local_o365_coursegroupdata} cgd
                 WHERE obj.type = ?
                       AND obj.subtype = ?
                       AND obj.moodleid = g.id
                       AND cgd.groupid = g.id
                       AND cgd.picture != g.picture';
        $params = ['group', 'usergroup'];
        $groups = $this->DB->get_recordset_sql($sql, $params, 0, 5);
        $count = 0;
        foreach ($groups as $group) {
            // If the upload fails, it will not reattempt unless user modifies the photo.
            if ($this->update_study_group_photo($group, $group->objectid)) {
                $count++;
            }
            $DB->set_field('local_o365_coursegroupdata', 'picture', $group->picture, array('id' => $group->cgdid));
        }
        if ($count) {
            $this->mtrace('Synced '.$count.' group profile photos.');
        }
    }
}
