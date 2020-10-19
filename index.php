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
require_once($CFG->libdir . '/adminlib.php');

$delete = optional_param('delete', 0, PARAM_INT);
$filename = optional_param('filename', '', PARAM_TEXT);
$deleteselected = optional_param('deleteselectedfiles', '', PARAM_TEXT);
$fileids = optional_param('fileids', '', PARAM_TEXT);

admin_externalpage_setup('reportallbackups', '', null, '', array('pagelayout' => 'report'));
$context = context_system::instance();
if (has_capability('report/allbackups:delete', $context)) {
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
                foreach ($post as $k => $v) {
                    if (preg_match('/^item(\d+)$/', $k, $m)) {
                        $fileids[] = (int)$m[1];
                    }
                }
            }

            // Display confirmation box - are you really sure you want to delete this file?
            echo $OUTPUT->header();
            $params = array('deleteselectedfiles' => 1, 'confirm' => 1, 'fileids' => implode(',', $fileids));
            $deleteurl = new moodle_url($PAGE->url, $params);
            $numfiles = count($fileids);
            echo $OUTPUT->confirm(get_string('areyousurebulk', 'report_allbackups', $numfiles),
                $deleteurl, $CFG->wwwroot . '/report/allbackups/index.php');

            echo $OUTPUT->footer();
            exit;
        } else if (optional_param('confirm', false, PARAM_BOOL) && confirm_sesskey()) {
            $count = 0;
            $fileids = explode(',', $fileids);
            foreach ($fileids as $id) {
                $fs = new file_storage();
                $file = $fs->get_file_by_id((int)$id);
                if (!empty($file)) {
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
            \core\notification::add(get_string('filesdeleted', 'report_allbackups', $count), \core\notification::SUCCESS);
        }
    }
}
$table = new \report_allbackups\output\allbackups_table('allbackups');
$ufiltering = new \report_allbackups\output\filtering();
if (!$table->is_downloading()) {
    // Only print headers if not asked to download data
    // Print the page header.
    $PAGE->set_title(get_string('pluginname', 'report_allbackups'));
    echo $OUTPUT->header();
    echo $OUTPUT->box(get_string('plugindescription', 'report_allbackups'));

    // Add old JS for selectall/deselectall.
    $PAGE->requires->js_amd_inline("
                        require(['jquery'], function($) {
                            $('#checkusers').click(function(e) {
                                $('#allbackupsform').find('input:checkbox').prop('checked', true);
                                e.preventDefault();
                            });
                            $('#uncheckusers').click(function(e) {
                                $('#allbackupsform').find('input:checkbox').prop('checked', false);
                                e.preventDefault();
                            });
                        });");

    $ufiltering->display_add();
    $ufiltering->display_active();

    echo '<form action="index.php" method="post" id="allbackupsform">';
    echo html_writer::start_div();
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::tag('input', '', array('type' => 'hidden', 'name' => 'returnto', 'value' => s($PAGE->url->out(false))));
} else {
    // Trigger downloaded event.
    $event = \report_allbackups\event\report_downloaded::create();
    $event->trigger();
}
list($extrasql, $params) = $ufiltering->get_sql_filter();
$fields = 'f.id, f.contextid, f.component, f.filearea, f.filename, f.userid, f.filesize, f.timecreated, f.filepath, f.itemid, ';
$fields .= get_all_user_name_fields(true, 'u');
$from = '{files} f JOIN {user} u on u.id = f.userid';
$where = "f.filename like '%.mbz' and f.filename <> '.' and f.component <> 'tool_recyclebin' and f.filearea <> 'draft'";
if (!empty($extrasql)) {
    $where .= " and ".$extrasql;
}

// Work out the sql for the table.
$table->set_sql($fields, $from, $where, $params);

$table->define_baseurl($PAGE->url);

$table->out(40, true);

if (!$table->is_downloading()) {
    echo html_writer::tag('input', "", array('name' => 'deleteselectedfiles', 'type' => 'submit',
        'id' => 'deleteallselected', 'class' => 'btn btn-secondary',
        'value' => get_string('deleteselectedfiles', 'report_allbackups')));
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    $event = \report_allbackups\event\report_viewed::create();
    $event->trigger();
    echo $OUTPUT->footer();
}