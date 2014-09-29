<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class General
{
    /* Store caches of frequently queried tables
     *
     * - Channels
     * - Categories
     * - Field Groups
     * - Member Groups
     * - Channel Fields
     * - Member Fields
     * - Matrix Fields
     * 
     */

    public function map_fields($fields, $data)
    {
        $formatted_fields = array();
        $output = array();

        foreach($fields as $field)
        {
            $formatted_fields[$field->field_name] = $field->field_id;
        }
        
        foreach($data as $key => $value)
        {
            $output['field_id_'.$formatted_fields[$key]] = $value;
        }

        return $output;
    }

    public function map_categories($categories, $data)
    {
        $formatted_categories = array();
        $output = array();

        foreach($categories as $category)
        {
            $formatted_categories[$category->cat_url_title] = $category->cat_id;
        }
        
        foreach($data as $value)
        {
            $output[] = $formatted_categories[$value];
        }

        return $output;
    }

    public function map_member_fields($member_fields, $mode = 'read')
    {
        $output = array();

        foreach($member_fields as $field_key => $field_data)
        {
            if($mode == 'read')
                $lookup_table = 'id_to_field';
            elseif($mode == 'write')
                $lookup_table = 'field_to_id';
            else
                return "Invalid mode specified.";

            
            if(isset($this->{$lookup_table}[$field_key]))
                $output[$this->{$lookup_table}[$field_key]] = $field_data;

            // We only want to preserve unknown keys when reading, otherwise we'll get unknown column errors from SQL
            elseif($mode == 'read')
                $output[$field_key] = $field_data;
        }

        return $output;
    }

    private function _get_entry($entry_id, $channel_id = false)
    {
        ee()->db->where('entry_id', $entry_id);
        ee()->db->from('channel_titles');
        $results = ee()->db->get()->result();
        $output = array();

        foreach($results[0] as $field => $data)
        {
            $output[$field] = $data;
        }

        $output['categories'] = $this->_get_categories($entry_id);

        // Set the channel ID if none is set
        if(!$channel_id)
            $channel_id = $output['channel_id'];
            
        ee()->db->select('field_group');
        ee()->db->where('channel_id', $channel_id);
        ee()->db->from('channels');
        ee()->db->limit(1);
        $result = ee()->db->get()->result();
        $group_id = $result[0]->field_group;
        
        ee()->db->select('field_id, field_name, field_type');
        ee()->db->where('group_id', $group_id);
        ee()->db->from('channel_fields');
        $results = ee()->db->get()->result();
        $field_names = array();
        $field_types = array();
        $field_ids = array();
        $field_select = array();

        // Loop through fields to determine column names
        foreach($results as $result)
        {
            $field_col = 'field_id_'.$result->field_id;
            $field_names[$field_col] = $result->field_name;
            $field_types[$field_col] = $result->field_type;
            $field_ids[$field_col] = $result->field_id;
            $field_select[] = $field_col;
        }

        $field_select = implode(",", $field_select);

        ee()->db->select($field_select);
        ee()->db->from('channel_data');
        ee()->db->where('entry_id', $entry_id);
        $result = ee()->db->get()->result();
        $channel_data = $result[0];

        foreach($channel_data as $field => $data)
        {
            $field_name = $field_names[$field];
            $field_type = $field_types[$field];
            $field_id = $field_ids[$field];
            
            $output[$field_name] = $data;

            if($field_type == "matrix")
            {
                $output[$field_name] = $this->_get_matrix($entry_id, $field_id);
            }
        }

        return $output;
    }

    private function _get_categories($entry_id)
    {
        ee()->db->from('categories');
        $results = ee()->db->get()->result();
        $categories = array();

        foreach($results as $result)
        {
            $categories[$result->cat_id] = $result->cat_url_title;
        }
        
        ee()->db->where('entry_id', $entry_id);
        ee()->db->from('category_posts');
        $results = ee()->db->get()->result();
        $output = array();

        foreach($results as $result)
        {
            $output[] = $categories[$result->cat_id];
        }
        
        return $output;
    }

    private function _get_matrix($entry_id, $field_id)
    {
        // Get all matrix cols by field ID
        ee()->db->select('col_id, col_name, col_type');
        ee()->db->from('matrix_cols');
        ee()->db->where('field_id', $field_id);
        $columns = ee()->db->get()->result();

        $selected_columns = array('row_id');
        $output = array();

        foreach($columns as $column)
        {
            $selected_columns[] = "col_id_".$column->col_id;
        }

        $selected_columns = implode(', ', $selected_columns);

        // Then select those fields from matrix data by entry ID
        ee()->db->select($selected_columns);
        ee()->db->from('matrix_data');
        ee()->db->where('entry_id', $entry_id);
        ee()->db->where('field_id', $field_id);
        $rows = ee()->db->get()->result();
        
        foreach($rows as $row)
        {
            $row_data = array();
            
            foreach($columns as $column)
            {
                $property = "col_id_".$column->col_id;
                
                $row_data[$column->col_name] = $row->$property;
                $row_data['row_id'] = $row->row_id;
            }

            $output[] = $row_data;
        }

        return $output;
    }

    private function _get_member($member_id)
    {        
        ee()->db->select('m_field_id, m_field_name, m_field_type');
        ee()->db->from('member_fields');
        $results = ee()->db->get()->result();
        $field_names = array();
        $field_types = array();
        $field_ids = array();
        $field_select = array();

        // Loop through fields to determine column names
        foreach($results as $result)
        {
            $field_col = 'm_field_id_'.$result->m_field_id;
            $field_names[$field_col] = $result->m_field_name;
            $field_types[$field_col] = $result->m_field_type;
            $field_ids[$field_col] = $result->m_field_id;
            $field_select[] = $field_col;
        }

        $field_select = implode(",", $field_select);

        ee()->db->select($field_select);
        ee()->db->from('member_data');
        ee()->db->where('member_id', $member_id);
        $result = ee()->db->get()->result();
        $member_data = $result[0];

        foreach($member_data as $field => $data)
        {
            $field_name = $field_names[$field];
            $field_type = $field_types[$field];
            $field_id = $field_ids[$field];
            
            $output[$field_name] = $data;
        }

        ee()->db->select('username, email');
        ee()->db->from('members');
        ee()->db->where('member_id', $member_id);
        $result = ee()->db->get()->result();
        $member = $result[0];

        foreach($member as $field => $data)
        {
            $output[$field] = $data;
        }

        return $output;
    }
}