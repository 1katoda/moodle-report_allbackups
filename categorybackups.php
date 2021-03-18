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
 * A report to display the all backup files on the site.
 *
 * @package    report_allbackups
 * @copyright  2020 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_login();

require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir . '/accesslib.php');

global $PAGE;

$contextid = intval(required_param('contextid', PARAM_INT));
$delete = optional_param('delete', '', PARAM_TEXT);
$filename = optional_param('filename', '', PARAM_TEXT);
$deleteselected = optional_param('deleteselectedfiles', '', PARAM_TEXT);
$fileids = optional_param('fileids', '', PARAM_TEXT);
$currenttab = optional_param('tab', 'core', PARAM_TEXT);

$urlparams = array("contextid" => $contextid, "tab" => $currenttab);
if (!empty($delete)) {
    $urlparams['delete'] = $delete;
}
if (!empty($deleteselected)) {
    $urlparams['deleteselectedfiles'] = $deleteselected;
}
if (!empty($filename)) {
    $urlparams['filename'] = $filename;
}
if (!empty($fileids)) {
    $urlparams['fileids'] = $fileids;
}
$PAGE->set_url(new moodle_url('/report/allbackups/categorybackups.php', $urlparams));
// Set page context as category context, defined by the required param $contextid.
$context = context::instance_by_id($contextid);
$PAGE->set_context($context);
// Set this page as an instance of a category.
$PAGE->set_category_by_id($context->instanceid);
// Set page heading and title.
$PAGE->set_title(get_string('pluginname', 'report_allbackups'));
$PAGE->set_heading(get_string('pluginname', 'report_allbackups'));
// Set page layout as admin.
$PAGE->set_pagelayout('report');
// Add navigation link to the category this page belongs to.
$PAGE->navbar->ignore_active();
$PAGE->navbar->add($context->get_context_name(false), new moodle_url('/course/index.php', array(
    'categoryid' => $context->instanceid
)));
// Check plugin config for category backup management mode.
$pluginconfig = get_config('report_allbackups');
if (!$pluginconfig->categorybackupmgmt) {
    echo $OUTPUT->header();
    \core\notification::error(get_string('error:categorybackupmgmtmodedisabled', 'report_allbackups'));
    echo $OUTPUT->footer();
    exit;
}

require_capability('report/categorybackups:view', $PAGE->context, $USER->id);

$backupdest = get_config('backup', 'backup_auto_destination');
if (empty($backupdest) && $currenttab == 'autobackup') {
    print_error(get_string("autobackupnotset", "report_allbackups"));
}

if (has_capability('report/categorybackups:delete', $PAGE->context)) {
    if (!empty($deleteselected) || !empty($delete)) { // Delete action.
        if (empty($fileids)) {
            $fileids = array();
            // First time form submit - get list of ids from checkboxes or from single delete action.
            if (!empty($delete)) {
                // This is a single delete action.
                $fileids[] = $delete;
            } else {
                // Get list of ids from checkboxes.
                $post = data_submitted();
                if ($currenttab == "autobackup") {
                    foreach ($post as $k => $v) {
                        if (preg_match('/^item(.*)/', $k, $m)) {
                            $fileids[] = $v; // Use value (filename) in array.
                        }
                    }
                } else {
                    foreach ($post as $k => $v) {
                        if (preg_match('/^item(\d+)$/', $k, $m)) {
                            $fileids[] = $m[1];
                        }
                    }
                }
            }
            // Display confirmation box - are you really sure you want to delete this file?
            echo $OUTPUT->header();
            $params = array(
                'deleteselectedfiles' => 1,
                'confirm' => 1,
                'fileids' => implode(',', $fileids),
                'tab' => $currenttab,
                'contextid' => $PAGE->context->id);
            $deleteurl = new moodle_url($PAGE->url, $params);
            $numfiles = count($fileids);
            echo $OUTPUT->confirm(get_string('areyousurebulk', 'report_allbackups', $numfiles),
                $deleteurl, $CFG->wwwroot . '/report/allbackups/categorybackups.php?contextid='.$PAGE->context->id);

            echo $OUTPUT->footer();
            exit;
        } else if (optional_param('confirm', false, PARAM_BOOL) && confirm_sesskey()) {
            $count = 0;
            $fileids = explode(',', $fileids);
            foreach ($fileids as $id) {
                if ($currenttab == 'autobackup') {
                    // Check nothing weird passed in filename - protect against directory traversal etc.
                    // Check to make sure this is an mbz file.
                    if ($id == clean_param($id, PARAM_FILE) &&
                            pathinfo($id, PATHINFO_EXTENSION) == 'mbz' &&
                            is_readable($backupdest .'/'. $id) &&
                            has_capability('moodle/course:delete', $PAGE->context)) {

                        unlink($backupdest .'/'. $id);
                        $event = \report_allbackups\event\autobackup_deleted::create(array(
                            'context' => $PAGE->context,
                            'objectid' => null,
                            'other' => array('filename' => $id)));
                        $event->trigger();
                        $count++;
                    } else {
                        \core\notification::add(get_string('couldnotdeletefile', 'report_allbackups', $id));
                    }
                } else {
                    $fs = new file_storage();
                    $file = $fs->get_file_by_id((int)$id);
                    $fileext = pathinfo($file->get_filename(), PATHINFO_EXTENSION);
                    // Make sure the file exists, and it is a backup file we are deleting.
                    if (!empty($file) &&
                            $fileext == 'mbz' &&
                            has_capability('moodle/course:delete', context::instance_by_id($file->get_contextid()))) {
                        $file->delete();
                        $event = \report_allbackups\event\backup_deleted::create(array(
                            'context' => context::instance_by_id($file->get_contextid()),
                            'objectid' => $file->get_id(),
                            'other' => array('filename' => $file->get_filename())));
                        $event->trigger();
                        $count++;
                    } else {
                        \core\notification::add(get_string('couldnotdeletefile', 'report_allbackups', $id));
                    }

                }
            }
            \core\notification::add(get_string('filesdeleted', 'report_allbackups', $count), \core\notification::SUCCESS);
        }
    }
}
if ($currenttab == 'autobackup') {
    $filters = array('filename' => 0, 'timecreated' => 0);
} else {
    $filters = array('filename' => 0, 'realname' => 0, 'coursecategory' => 0, 'filearea' => 0, 'timecreated' => 0);
}
if ($currenttab == 'autobackup') {
    $table = new \report_allbackups\output\autobackups_table('autobackups', $PAGE->context);
} else {
    $table = new \report_allbackups\output\allbackups_table('allbackups', $PAGE->context);
    $table->define_baseurl($PAGE->url);
}

$ufiltering = new \report_allbackups\output\filtering($filters, $PAGE->url, null, true);
if (!$table->is_downloading()) {
    // Only print headers if not asked to download data.
    // Print the page header.
    $PAGE->set_title(get_string('pluginname', 'report_allbackups'));
    echo $OUTPUT->header();
    if (!empty(get_config('backup', 'backup_auto_destination'))) {
        $row = $tabs = array();
        $row[] = new tabobject('core',
            $CFG->wwwroot.'/report/allbackups/categorybackups.php?contextid='.$PAGE->context->id,
            get_string('standardbackups', 'report_allbackups'));
        $row[] = new tabobject('autobackup',
            $CFG->wwwroot.'/report/allbackups/categorybackups.php?contextid='.$PAGE->context->id.'&tab=autobackup',
            get_string('autobackup', 'report_allbackups'));
        $tabs[] = $row;
        print_tabs($tabs, $currenttab);
    }
    if ($currenttab == 'autobackup') {
        echo $OUTPUT->box(get_string('autobackup_description', 'report_allbackups'));
    } else {
        echo $OUTPUT->box(get_string('plugindescription', 'report_allbackups'));
    }
    $ufiltering->display_add();
    $ufiltering->display_active();

    echo '<form action="categorybackups.php?contextid='.$PAGE->context->id.'" method="post" id="allbackupsform">';
    echo html_writer::start_div();
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'returnto', 'value' => s($PAGE->url->out(false))));
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'tab', 'value' => $currenttab));
} else {
    // Trigger downloaded event.
    $event = \report_allbackups\event\report_downloaded::create();
    $event->trigger();
}
if ($currenttab == 'autobackup') {
    // Get list of files from backup.
    $table->adddata($ufiltering, $PAGE->context);
} else {
    list($extrasql, $params) = $ufiltering->get_sql_filter();
    $fields = "f.id, f.contextid, f.component, f.filearea, f.filename, f.userid, f.filesize, f.timecreated, f.filepath, f.itemid";
    if ($CFG->branch >= 311) {
        // get_all_user_name_fields() is deprecated in 3.11; use user_fields class.
        $fields .= \core\user_fields::for_name()->get_sql('u')->selects;
    } else {
        $fields .= ", " . get_all_user_name_fields(true, 'u');
    }

    $pluginconfig = get_config('report_allbackups');

    if ($pluginconfig->mdlbkponly) {
        if ($pluginconfig->enableactivities) {
            $from = "(
                SELECT *
                FROM {files}
                WHERE component=:cmpbackup
                    OR (component=:cmpcourse AND filearea=:falegacy)
                ) f
                JOIN {user} u on u.id = f.userid";
        } else {
            $from = "(
                SELECT *
                FROM {files}
                WHERE filearea IN (:facourse, :faautomated, :falegacy)
                    AND component IN (:cmpbackup, :cmpcourse)
                ) f
                JOIN {user} u on u.id = f.userid";

            $params['facourse'] = 'course';
            $params['faautomated'] = 'automated';
        }

        $from .= " JOIN {context} cx ON cx.id = f.contextid AND cx.contextlevel = :contextlevel";

        if (strpos($extrasql, 'c.category') !== false) {
            // Category filter included, Join with course table.
            $from .= " JOIN {course} c ON c.id = cx.instanceid";
        }

        $where = "f.filename LIKE :backupsuffix AND cx.path LIKE '".$context->path."%'";

        $params['falegacy'] = 'legacy';
        $params['cmpbackup'] = 'backup';
        $params['cmpcourse'] = 'course';
        $params['contextlevel'] = CONTEXT_COURSE;
        $params['backupsuffix'] = '%.mbz';

    } else {
        $from = "{files} f
            JOIN {user} u on u.id = f.userid
            JOIN {context} cx ON cx.id = f.contextid
                AND cx.contextlevel = :contextlevel";
        if (strpos($extrasql, 'c.category') !== false) {
            // Category filter included, Join with course table.
            $from .= " JOIN {course} c ON c.id = cx.instanceid";
        }
        $where = "f.filename LIKE :backupsuffix
            AND f.component <> :cmprecycle
            AND f.filearea <> :fadraft
            AND cx.path LIKE '".$context->path."%'";

        $params['cmprecycle'] = 'tool_recyclebin';
        $params['fadraft'] = 'draft';
        $params['contextlevel'] = CONTEXT_COURSE;
        $params['backupsuffix'] = '%.mbz';
    }

    if (!empty($extrasql)) {
        $where .= " and ".$extrasql;
    }

    $table->set_sql($fields, $from, $where, $params);
    $table->out(40, true);
}

if (!$table->is_downloading() && has_capability('moodle/course:delete', $PAGE->context)) {
    echo html_writer::tag('input', "", array('name' => 'deleteselectedfiles', 'type' => 'submit',
        'id' => 'deleteallselected', 'class' => 'btn btn-secondary',
        'value' => get_string('deleteselectedfiles', 'report_allbackups')));
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    $event = \report_allbackups\event\report_viewed::create();
    $event->trigger();
    echo $OUTPUT->footer();
}