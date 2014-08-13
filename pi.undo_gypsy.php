<?php        
$plugin_info = array(
        'pi_name' => 'Undo Gypsy',
        'pi_version' => '1.1',
        'pi_author' => 'Brian Litzinger | BoldMinded.com & Nerdery.com (modified by Justin Jones - work@justinjones.com.au)',
        'pi_description' => 'Undoes Gypsy',
        'pi_usage' => 'BACK EVERYTHING UP FIRST!',
); 
/*
This script is provided as is. There is no warranty or guarantee this will
work for all environments. Please do not contact me regarding support for this
script. It was just the result of several hours of my work to upgrade a single
site from EE 1 to EE 2 and I'm being generous and sharing it.

This script will first undo Gypsy by creating seaparate field groups and cloning 
existing custom fields, migrating data accordingly, then un-installing Gypsy.

Brian Litzinger, BoldMinded, LLC, and The Nerdery are NOT responsible for any muck 
ups you might do to your data. BACK EVERYTHING UP FIRST!

===== USAGE ====

First you will need to create your new custom field groups, then note their IDs
and update the $weblogs_to_groups array accordingly.

Put this into your plugins folder, create a template such as: 
    templates/upgrade/ee1.php
    
In that template put the following:
    {exp:undo_gypsy:run}

Hit the following URL in your browser when you are ready to run the upgrade.
    http://www.site.com/upgrade/ee1
    
When it is done, it will dump an array mapping the old field names to the
new ones it will create. You'll then need to update your templates with the
new field names.

This addon was modified by Justin Jones (work@justinjones.com.au) at the request
of Brendan Underwood (brendan@ipixel.com.au) to work with sites that use the
Matrix field type (not FF Matrix).
    ** Note that if you're running MSM, be sure to run Matrix Tidy Cols (https://github.com/pixelandtonic/matrix_tidy_cols) first.

*/

class Undo_gypsy {
         
    public function run()
    {
        global $DB;

        $fields_q = $DB->query("select * from exp_weblog_fields");
        $weblogs_q = $DB->query("select * from exp_weblogs");
        $entries = $DB->query("select * from exp_weblog_data");

        $weblogs = array();
        $id_mappings = array();
        $name_mappings = array();

        /*
        Map the weblogs/channels to which field group_id is assigned to it. You should have
        created these groups within EE and noted their IDs.
        
        The prefix is used to prefix all the custom fields within each group so they are unique.
        */
        $weblogs_to_groups = array(
            1 => array('prefix' => 't9t_', 'group_id' => 13),
            2 => array('prefix' => 'news_', 'group_id' => 12),
            3 => array('prefix' => 'about_', 'group_id' => 9),
            5 => array('prefix' => 'wheel_', 'group_id' => 2),
            7 => array('prefix' => 'contact_', 'group_id' => 10),
            9 => array('prefix' => 'tyre_', 'group_id' => 4),
            12 => array('prefix' => 'hero_', 'group_id' => 7),
            13 => array('prefix' => 'sus_', 'group_id' => 14),
            14 => array('prefix' => 'finance_', 'group_id' => 15),
            15 => array('prefix' => 'image_', 'group_id' => 8),
            16 => array('prefix' => 'gear_', 'group_id' => 11)
        );

        // Get the data into an easily accessed array by key
        foreach($weblogs_q->result as $row)
        {
            $weblogs_by_id[$row['weblog_id']] = $row;
            $weblogs_by_group[$row['field_group']] = $row;
        }

        echo '<pre>'; 

        foreach($fields_q->result as $field)
        {
            $cloned = array();
            $updated_field = $field;
            unset($updated_field['field_id']);

            $gypsy_weblogs = explode(' ', ltrim(rtrim($field['gypsy_weblogs'])));

            var_dump('======= '. $field['field_name'] .' / '. $field['group_id'] .' =======');

            if($field['field_is_gypsy'] == 'y' AND count($gypsy_weblogs) > 1)
            {
                foreach($gypsy_weblogs as $weblog_id)
                {
                    $updated_field['group_id'] = isset($weblogs_to_groups[$weblog_id]) ? $weblogs_to_groups[$weblog_id]['group_id'] : $field['group_id'];
                    $updated_field['field_name'] = $this->rename_field($weblogs_to_groups[$weblog_id]['prefix'], $field['field_name']);

                    $name_mappings[$field['field_name']][] = $updated_field['field_name'];

                    // Update original, only the first occurance of the field in the loop, otherwise we end up with X clones, and the original one still in place.
                    if(empty($cloned))
                    {
                        var_dump('multiple updating: '. $field['field_name'] .' to '. $updated_field['field_name'] .' group_id '. $updated_field['group_id']);
                        $DB->query($DB->update_string('exp_weblog_fields', $updated_field, array('field_id' => $field['field_id'])));
                    }
                    // insert new row for the cloned field
                    else
                    {
                        var_dump('duplicating: '. $field['field_name'] . ' to '. $updated_field['field_name'] .' group_id '. $updated_field['group_id']);
                        $DB->query($DB->insert_string('exp_weblog_fields', $updated_field));
                        $new_field_id = $DB->insert_id;
                        $id_mappings[$new_field_id] = array('weblog_id' => $weblog_id, 'old_field_id' => $field['field_id'], 'field_type' => $field['field_type']);

                        // Add new columns before moving the data
                        $DB->query("ALTER TABLE exp_weblog_data ADD COLUMN field_id_$new_field_id TEXT NULL");
                        $DB->query("ALTER TABLE exp_weblog_data ADD COLUMN field_ft_$new_field_id TINYTEXT NULL");

                        // Set the formatting type first
                        $fmt = $field['field_fmt'];
                        $DB->query("UPDATE exp_weblog_data SET field_ft_$new_field_id = '$fmt'");


                    }

                    $cloned[] = $field['field_id'];
                }
            }
            else
            {
                // We have a field related to just 1 weblog
                $weblog_id = isset($field['gypsy_weblogs']) ? rtrim(ltrim($field['gypsy_weblogs'])) : false;

                if($weblog_id)
                {
                    var_dump('single updating: '. $field['field_name'] .' to '. $updated_field['field_name'] .' group_id '. $updated_field['group_id']);

                    $updated_field['group_id'] = (isset($weblogs_to_groups[$weblog_id]) AND $weblog_id) ? $weblogs_to_groups[$weblog_id]['group_id'] : $updated_field['group_id'];
                    $updated_field['field_name'] = $weblogs_to_groups[$weblog_id]['prefix'] . $field['field_name'];

                    $name_mappings[$field['field_name']][] = $updated_field['field_name'];

                    $DB->query($DB->update_string('exp_weblog_fields', $updated_field, array('field_id' => $field['field_id'])));
                }
            }
        }

        // Update entries, move the actual data from the old field to the new one.
        foreach($entries->result as $entry)
        {
            $weblog_id = $entry['weblog_id'];
            $entry_id = $entry['entry_id'];

            foreach($id_mappings as $new_field_id => $data)
            {
                if($weblog_id == $data['weblog_id'])
                {
                    $updated_data = array(
                        'field_id_'. $new_field_id => $entry['field_id_'. $data['old_field_id']],
                        'field_id_'. $data['old_field_id'] => ''
                    );

                    $DB->query($DB->update_string('exp_weblog_data', $updated_data, array('entry_id' => $entry_id)));
                    
                    $matrix_data = array(
                      'field_id' => $new_field_id
                    );
                    
                    $DB->query($DB->update_string('exp_matrix_data', $matrix_data, array('entry_id' => $entry_id, 'field_id' => $data['old_field_id'])));
                }
            }
        }

        // Update the weblogs so they now have the correct field group assignment
        foreach($weblogs_to_groups as $weblog_id => $data)
        {
            $update_data = array(
                'field_group' => $data['group_id']
            );

            $DB->query($DB->update_string('exp_weblogs', $update_data, array('weblog_id' => $weblog_id)));
        }

        // Cleanup, remove Gypsy
        $DB->query("DELETE FROM exp_extensions WHERE class = 'Gypsy'");
        $DB->query("ALTER TABLE exp_weblog_fields DROP column `field_is_gypsy`");
        $DB->query("ALTER TABLE exp_weblog_fields DROP column `gypsy_weblogs`");

        var_dump($id_mappings);
        var_dump($name_mappings);

        die('Done!');
    }
        
    private function rename_field($prefix, $old)
    {
        $length = strlen($prefix);
        return (substr($old, 0, $length) !== $prefix) ? $prefix . $old : $old;
    }
}       


?>
