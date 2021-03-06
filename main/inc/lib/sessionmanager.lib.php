<?php
/* For licensing terms, see /license.txt */
/**
* This is the session library for Chamilo.
* All main sessions functions should be placed here.
* This class provides methods for sessions management.
* Include/require it in your code to use its features.
* @package chamilo.library
*/
/**
 * The SessionManager class manages all the Chamilo sessions (as in course
 * groups).
 * @package chamilo.library.session
 */
class SessionManager
{
    //See BT#4871
    CONST SESSION_CHANGE_USER_REASON_SCHEDULE = 1;
    CONST SESSION_CHANGE_USER_REASON_CLASSROOM = 2;
    CONST SESSION_CHANGE_USER_REASON_LOCATION = 3;
    CONST SESSION_CHANGE_USER_REASON_ENROLLMENT_ANNULATION = 4;
    CONST DEFAULT_VISIBILITY = 4;  //SESSION_AVAILABLE

    /**
     * Fetches a session from the database
     * @param  int     Session ID
     * @return array   Session details
     * @assert (-1) === array()
     */
    public static function fetch($id) {
        $t = Database::get_main_table(TABLE_MAIN_SESSION);
        if ($id != strval(intval($id))) { return array(); }
        $s = "SELECT * FROM $t WHERE id = $id";
        $r = Database::query($s);
        if (Database::num_rows($r) != 1) { return array(); }
        return Database::fetch_array($r,'ASSOC');
    }
    /**
     * Creates a session
     * @param array Fields to use in the creation of the session
     * @param boolean Whether to allow for same-name sessions or not.
     * @assert (array()) === false
     */
    public static function add($params, $allow_homonyms=false) {
        global $_configuration;

        //just in case
        if (isset($params['id'])) {
            unset($params['id']);
        }

        //Check portal limits
        $access_url_id = 1;

        if (api_is_multiple_url_enabled()) {
            $access_url_id = api_get_current_access_url_id();
        }
        if (is_array($_configuration[$access_url_id]) && isset($_configuration[$access_url_id]['hosting_limit_sessions']) && $_configuration[$access_url_id]['hosting_limit_sessions'] > 0) {
            $num = self::count_sessions();
            if ($num >= $_configuration[$access_url_id]['hosting_limit_sessions']) {
                return get_lang('PortalSessionsLimitReached');
            }
        }
        if (!$allow_homonyms) {
            $my_session_result = SessionManager::get_session_by_name($params['name']);
            $session_id = null;

            if ($my_session_result == false) {
                $session_model = new SessionModel();
                $session_id = $session_model->save($params);
            } else {
                error_log('Session already exists with name: '.$params['name']." session_id: ".$my_session_result['id']);
            }
        } else {
            //with the allow_homonyms option, two sessions can share names
            $session_model = new SessionModel();
            $session_id = $session_model->save($params);
        }

        if (!empty($session_id)) {
            /*
            Sends a message to the user_id = 1

            $user_info = api_get_user_info(1);
            $complete_name = $user_info['firstname'].' '.$user_info['lastname'];
            $subject = api_get_setting('siteName').' - '.get_lang('ANewSessionWasCreated');
            $message = get_lang('ANewSessionWasCreated')." <br /> ".get_lang('NameOfTheSession').' : '.$name;
            api_mail_html($complete_name, $user_info['email'], $subject, $message);
            *
            */

            //Saving extra fields
            $session_field_value = new SessionFieldValue();
            $params['session_id'] = $session_id;
            $session_field_value->save_field_values($params);

            //Adding to the correct URL
            $access_url_id = api_get_current_access_url_id();
            UrlManager::add_session_to_url($session_id, $access_url_id);

            // Add event to system log
            event_system(LOG_SESSION_CREATE, LOG_SESSION_ID, $session_id, api_get_utc_datetime(), api_get_user_id());

            if (isset($params['course_code'])) {
                $courseInfo = api_get_course_info($params['course_code']);

                self::add_courses_to_session($session_id, array($courseInfo['real_id']));

                //Update default course gradebook to the session if exists
                $create_gradebook_evaluation = isset($params['create_gradebook_evaluation']) ? $params['create_gradebook_evaluation']  : false;
                if ($create_gradebook_evaluation) {
                    require_once api_get_path(SYS_CODE_PATH).'gradebook/lib/gradebook_functions.inc.php';
                    $category_id = create_default_course_gradebook($params['course_code'], false, $session_id);
                    /*
                    if ($category_id && isset($params['gradebook_params'])) {
                        $eval = new Evaluation();
                        $eval->set_name($params['gradebook_params']['name']);
                        $eval->set_user_id($params['gradebook_params']['user_id']);
                        $eval->set_course_code($params['course_code']);
                        $eval->set_category_id($category_id);
                        $eval->set_weight($params['gradebook_params']['weight']);
                        $eval->set_max($params['gradebook_params']['max']);
                        $eval->set_visible(0);
                        $eval->add();
                    }*/

                    /*$tbl_gradebook_category = Database :: get_main_table(TABLE_MAIN_GRADEBOOK_EVALUATION);
                    $sql = "SELECT id FROM $tbl_gradebook_category WHERE course_code = {$params['course_code']} AND session_id = 0 LIMIT 1";
                    $result = Database::query($sql);
                    if (Database::num_rows($result)) {
                        $gradebook_data = Database::fetch_array($result, 'ASSOC');
                        if (!empty($gradebook_data)) {
                            $sql = "UPDATE SET session_id = $session_id WHERE id = {$gradebook_data['id']}";
                            Database::query($sql);
                        }
                    }*/
                }
            }
        } else {
            if (isset($params['return_item_if_already_exists']) && $params['return_item_if_already_exists']) {
                $my_session_result = SessionManager::get_session_by_name($params['name']);
                $session_id = $my_session_result['id'];
            }
        }
        return $session_id;
    }
    /**
     * Updates a session with the given array of field values
     * @param array An array of fields values
     * @return void
     * @assert (null) === false
     */
    public static function update($params) {
        if (empty($params) || count($params)<1) {
            return false;
        }
        $session_model = new SessionModel();
        $session_model->update($params);

        if (!empty($params['id'])) {
            $session_field_value = new SessionFieldValue();
            $params['session_id'] = $params['id'];
            unset($params['id']);
            $session_field_value->save_field_values($params);
        }
    }
    /**
     * Checks whether a session already exists with the given name (used in
     * add() to avoid homonym sessions)
     * @param string A session name
     * @assert ('') === false
     */
    function session_name_exists($session_name) {
        $session_name = Database::escape_string($session_name);
        $result = Database::fetch_array(Database::query("SELECT COUNT(*) as count FROM ".Database::get_main_table(TABLE_MAIN_SESSION)." WHERE name = '$session_name' "));
        return $result['count'] > 0;
    }
    /**
     * Gets the admin session list callback of the session/session_list.php page
     * @param array order and limit keys
     * @param boolean Whether to get all the results or only the count
     * @return mixed Integer for number of rows, or array of results
     * @assert (array(),true) !== false
     */
    public static function get_sessions_admin($options = array(), $get_count = false)
    {
        $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);

        $where = 'WHERE 1 = 1 ';
        $user_id = api_get_user_id();

        if (!api_is_platform_admin()) {
            if (api_is_session_admin() && api_get_setting('allow_session_admins_to_manage_all_sessions') == 'false') {
                $where .=" AND s.session_admin_id = $user_id ";
            }
        }

        if (!api_is_platform_admin() && api_is_teacher() && api_get_setting('allow_teachers_to_create_sessions') == 'true') {
            $where .=" AND s.id_coach = $user_id ";
        }

        $extra_field = new ExtraField('session');
        $conditions = $extra_field->parseConditions($options);
        $inject_joins = $conditions['inject_joins'];
        $where .= $conditions['where'];
        $inject_where = $conditions['inject_where'];
        $inject_extra_fields = $conditions['inject_extra_fields'];
        $order = $conditions['order'];
        $limit = $conditions['limit'];

        if ($get_count == true) {
            $select = " SELECT count(*) as total_rows";
        } else {
            $select =
                    "SELECT DISTINCT ".
                    " s.name, ".
                    " s.display_start_date, ".
                    " s.display_end_date, ".
                    " access_start_date, ".
                    " access_end_date, ".
                    " s.visibility, ".
                    " $inject_extra_fields ".
                    " s.id ";
        }

        $query = "$select FROM $tbl_session s $inject_joins $where $inject_where";

        if (api_is_multiple_url_enabled()) {
            $table_access_url_rel_session= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
            $access_url_id = api_get_current_access_url_id();
            if ($access_url_id != -1) {
                $where.= " AND ar.access_url_id = $access_url_id ";
                $query = "$select FROM $tbl_session s $inject_joins INNER JOIN $table_access_url_rel_session ar ON (ar.session_id = s.id) $where";
            }
        }

        $query .= $order;
        $query .= $limit;
        $result = Database::query($query);

        $formatted_sessions = array();

        if (Database::num_rows($result)) {
            $sessions = Database::store_result($result, 'ASSOC');
            if ($get_count) {
                return $sessions[0]['total_rows'];
            }

            foreach ($sessions as $session) {

                $session_id = $session['id'];

                $session['name'] = Display::url($session['name'], "resume_session.php?id_session=".$session['id']);

                if (isset($session['session_active']) && $session['session_active'] == 1) {
                    $session['session_active'] = Display::return_icon('accept.png', get_lang('Active'), array(), ICON_SIZE_SMALL);
                } else {
                    $session['session_active'] = Display::return_icon('error.png', get_lang('Inactive'), array(), ICON_SIZE_SMALL);
                }

                $session = self::convert_dates_to_local($session);

                switch ($session['visibility']) {
                    case SESSION_VISIBLE_READ_ONLY: //1
                        $session['visibility'] = get_lang('ReadOnly');
                        break;
                    case SESSION_VISIBLE:           //2
                    case SESSION_AVAILABLE:         //4
                        $session['visibility'] = get_lang('Visible');
                        break;
                    case SESSION_INVISIBLE:         //3
                        $session['visibility'] = api_ucfirst(get_lang('Invisible'));
                        break;
                }

                // Cleaning double selects.
                foreach ($session as $key => &$value) {
                    if (isset($options_by_double[$key]) || isset($options_by_double[$key.'_second'])) {
                        $options = explode('::', $value);
                    }
                    $original_key = $key;

                    if (strpos($key, '_second') === false) {
                    } else {
                        $key = str_replace('_second', '', $key);
                    }

                    if (isset($options_by_double[$key])) {
                        if (isset($options[0])) {
                            if (isset($options_by_double[$key][$options[0]])) {
                                if (strpos($original_key, '_second') === false) {
                                    $value = $options_by_double[$key][$options[0]]['option_display_text'];
                                } else {
                                    $value = $options_by_double[$key][$options[1]]['option_display_text'];
                                }
                            }
                        }
                    }
                }
                $formatted_sessions[$session_id] = $session;
            }
        }
        return $formatted_sessions;
    }

    /**
     * Gets the number of rows in the session table filtered through the given
     * array of parameters
     * @param array Array of options/filters/keys
     * @return integer The number of rows, or false on wrong param
     * @assert ('a') === false
     */
    static function get_count_admin_complete($options = array()) {
        if (!is_array($options)) {
            return false;
        }
        $tbl_session            = Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_category   = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $tbl_user               = Database::get_main_table(TABLE_MAIN_USER);

        $where = 'WHERE 1 = 1 ';
        $user_id = api_get_user_id();

        if (api_is_session_admin() && api_get_setting('allow_session_admins_to_see_all_sessions') == 'false') {
            $where.=" WHERE s.session_admin_id = $user_id ";
        }

        if (!empty($options['where'])) {
            if (!empty($options['extra'])) {
                $options['where'] = str_replace(' 1 = 1  AND', '', $options['where']);
                $options['where'] = str_replace('AND', 'OR', $options['where']);

                foreach ($options['extra'] as $extra) {
                    $options['where'] = str_replace($extra['field'], 'fv.field_id = '.$extra['id'].' AND fvo.option_value', $options['where']);
                }
            }
            $where .= ' AND '.$options['where'];
        }

        $query_rows = "SELECT count(*) as total_rows
                       FROM $tbl_session s
                       LEFT JOIN  $tbl_session_category sc ON s.session_category_id = sc.id
                       INNER JOIN $tbl_user u ON s.id_coach = u.user_id $where ";

        if (api_is_multiple_url_enabled()) {
            $table_access_url_rel_session= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
            $access_url_id = api_get_current_access_url_id();
            if ($access_url_id != -1) {
                $where.= " AND ar.access_url_id = $access_url_id ";

                $query_rows = "SELECT count(*) as total_rows
                               FROM $tbl_session s
                               LEFT JOIN  $tbl_session_category sc ON s.session_category_id = sc.id
                               INNER JOIN $tbl_user u ON s.id_coach = u.user_id
                               INNER JOIN $table_access_url_rel_session ar ON ar.session_id = s.id $where ";
            }
        }
        $result_rows = Database::query($query_rows);
        $recorset = Database::fetch_array($result_rows);
        $num = $recorset['total_rows'];
        return $num;
    }
    /**
     * Gets the admin session list callback of the session/session_list.php
     * page with all user/details in the right fomat
     * @param array order and limit keys
     * @result array Array of rows results
     * @asset ('a') === false
     */
    public static function get_sessions_admin_complete($options = array()) {
        if (!is_array($options)) {
            return false;
        }
        $tbl_session            = Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_category   = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $tbl_user               = Database::get_main_table(TABLE_MAIN_USER);
        $tbl_session_rel_course = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_course             = Database::get_main_table(TABLE_MAIN_COURSE);
        $tbl_session_field_values = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_VALUES);
        $tbl_session_field_options = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_OPTIONS);

        $where = 'WHERE 1 = 1 ';
        $user_id = api_get_user_id();

        if (api_is_session_admin() && api_get_setting('allow_session_admins_to_manage_all_sessions') == 'false') {
            $where.=" AND s.session_admin_id = $user_id ";
        }

        $coach_name = " CONCAT(u.lastname , ' ', u.firstname) as coach_name ";
        if (api_is_western_name_order()) {
            $coach_name = " CONCAT(u.firstname, ' ', u.lastname) as coach_name ";
        }

        $today = api_get_utc_datetime();

        $inject_extra_fields = null;

        $extra_fields = array();
        $extra_fields_info = array();

        //for now only sessions
        $extra_field = new ExtraField('session');
        $double_fields = array();

        $extra_field_option = new ExtraFieldOption('session');

        if (isset($options['extra'])) {
            $extra_fields = $options['extra'];
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $extra) {
                    $inject_extra_fields .= " IF (fv.field_id = {$extra['id']}, fvo.option_display_text, NULL ) as {$extra['field']} , ";
                    if (isset($extra_fields_info[$extra['id']])) {
                        $info = $extra_fields_info[$extra['id']];
                    } else {
                        $info = $extra_field->get($extra['id']);
                        $extra_fields_info[$extra['id']] = $info;
                    }

                    if ($info['field_type'] == ExtraField::FIELD_TYPE_DOUBLE_SELECT) {
                        $double_fields[$info['id']] = $info;
                    }
                }
            }
        }

        $options_by_double = array();
        foreach ($double_fields as $double) {
            $my_options = $extra_field_option->get_field_options_by_field($double['id'], true);
            $options_by_double['extra_'.$double['field_variable']] = $my_options;
        }

        //sc.name as category_name,
        $select = "
                SELECT * FROM (
                    SELECT DISTINCT
                        IF (
                            (s.access_start_date <= '$today' AND '$today' < s.access_end_date) OR
                            (s.access_start_date  = '0000-00-00 00:00:00' AND s.access_end_date  = '0000-00-00 00:00:00' ) OR
                            (s.access_start_date <= '$today' AND '0000-00-00 00:00:00' = s.access_end_date) OR
                            ('$today' < s.access_end_date AND '0000-00-00 00:00:00' = s.access_start_date)
                        , 1, 0) as session_active,
                s.name,
                s.nbr_courses,
                s.nbr_users,
                s.display_start_date,
                s.display_end_date,
                $coach_name,
                access_start_date,
                access_end_date,
                s.visibility,
                u.user_id,
                $inject_extra_fields
                c.title as course_title,
                s.id ";

        if (!empty($options['where'])) {
            if (!empty($options['extra'])) {
                $options['where'] = str_replace(' 1 = 1  AND', '', $options['where']);
                $options['where'] = str_replace('AND', 'OR', $options['where']);
                foreach ($options['extra'] as $extra) {
                    $options['where'] = str_replace($extra['field'], 'fv.field_id = '.$extra['id'].' AND fvo.option_value', $options['where']);
                }
            }
            $options['where'] = str_replace('course_title', 'c.title', $options['where']);

            $where .= ' AND '.$options['where'];
        }

        if (!empty($options['limit'])) {
            $where .= " LIMIT ".$options['limit'];
        }
        $query = "$select FROM $tbl_session s
                    LEFT JOIN $tbl_session_field_values fv ON (fv.session_id = s.id)
                    INNER JOIN $tbl_session_field_options fvo ON (fv.field_id = fvo.field_id)
                    LEFT JOIN $tbl_session_rel_course src ON (src.id_session = s.id)
                    LEFT JOIN $tbl_course c ON (src.c_id = c.id)
                    LEFT JOIN $tbl_session_category sc ON (s.session_category_id = sc.id)
                    INNER JOIN $tbl_user u ON (s.id_coach = u.user_id) ".
                    $where;

        if (api_is_multiple_url_enabled()) {
            $table_access_url_rel_session= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
            $access_url_id = api_get_current_access_url_id();
            if ($access_url_id != -1) {
                $where.= " AND ar.access_url_id = $access_url_id ";
                $query = "$select
                    FROM $tbl_session s
                    LEFT JOIN $tbl_session_field_values fv ON (fv.session_id = s.id)
                    LEFT JOIN $tbl_session_rel_course src ON (src.id_session = s.id)
                    LEFT JOIN $tbl_course c ON (src.c_id = c.id)
                    LEFT JOIN $tbl_session_category sc ON (s.session_category_id = sc.id)
                    INNER JOIN $tbl_user u ON (s.id_coach = u.user_id)
                    INNER JOIN $table_access_url_rel_session ar ON (ar.session_id = s.id)
                    $where";
                }
            }

            $query .= ") AS session_table";

        if (!empty($options['order'])) {
            $query .= " ORDER BY ".$options['order'];
        }

        //error_log($query);
        // echo $query;

        $result = Database::query($query);
        $formatted_sessions = array();

        if (Database::num_rows($result)) {
            $sessions   = Database::store_result($result, 'ASSOC');
            foreach ($sessions as $session) {
                $session_id = $session['id'];
                $session['name'] = Display::url($session['name'], "resume_session.php?id_session=".$session['id']);
                $session['coach_name'] = Display::url($session['coach_name'], "user_information.php?user_id=".$session['user_id']);
                if ($session['session_active'] == 1) {
                    $session['session_active'] = Display::return_icon('accept.png', get_lang('Active'), array(), ICON_SIZE_SMALL);
                } else {
                    $session['session_active'] = Display::return_icon('error.png', get_lang('Inactive'), array(), ICON_SIZE_SMALL);
                }

                $session = self::convert_dates_to_local($session);

                switch ($session['visibility']) {
                    case SESSION_VISIBLE_READ_ONLY: //1
                        $session['visibility'] =  get_lang('ReadOnly');
                        break;
                    case SESSION_VISIBLE:           //2
                    case SESSION_AVAILABLE:         //4
                        $session['visibility'] =  get_lang('Visible');
                        break;
                    case SESSION_INVISIBLE:         //3
                        $session['visibility'] =  api_ucfirst(get_lang('Invisible'));
                        break;
                }

                //Cleaning double selects
                foreach ($session as $key => &$value) {
                    if (isset($options_by_double[$key]) || isset($options_by_double[$key.'_second'])) {
                        $options = explode('::', $value);
                    }
                    $original_key = $key;

                    if (strpos($key, '_second') === false) {
                    } else {
                        $key = str_replace('_second', '', $key);
                    }

                    if (isset($options_by_double[$key])) {
                        if (isset($options[0])) {
                            if (isset($options_by_double[$key][$options[0]])) {
                                if (strpos($original_key, '_second') === false) {
                                    $value = $options_by_double[$key][$options[0]]['option_display_text'];
                                } else {
                                    $value = $options_by_double[$key][$options[1]]['option_display_text'];
                                }
                            }
                        }
                    }
                }

                //Magic filter
                if (isset($formatted_sessions[$session_id])) {
                    $formatted_sessions[$session_id] = self::compare_arrays_to_merge($formatted_sessions[$session_id], $session);
                } else {
                    $formatted_sessions[$session_id] = $session;
                }
            }
        }

        return $formatted_sessions;
    }
    /**
     *
     */
    static function compare_arrays_to_merge($array1, $array2)
    {
        if (empty($array2)) {
            return $array1;
        }
        foreach ($array1 as $key => $item) {
            if (!isset($array1[$key])) {
                //My string is empty try the other one
                if (isset($array2[$key]) && !empty($array2[$key])) {
                    $array1[$key] = $array2[$key];
                }
            }
        }
        return $array1;
    }
    /**
     * Converts all dates sent through the param array (given form) to correct
     * dates with timezones
     * @param array The dates
     * @return array The same array, with times converted
     * @assert ('a') === false
     */
    static function convert_dates_to_local($params) {
        if (!is_array($params)) {
            return false;
        }
        $params['display_start_date']       = api_get_local_time($params['display_start_date'], null, null, true);
        $params['display_end_date']         = api_get_local_time($params['display_end_date'], null, null, true);

        $params['access_start_date']        = api_get_local_time($params['access_start_date'], null, null, true);
        $params['access_end_date']          = api_get_local_time($params['access_end_date'], null, null, true);

        $params['coach_access_start_date']  = isset($params['coach_access_start_date']) ? api_get_local_time($params['coach_access_start_date'], null, null, true) : null;
        $params['coach_access_end_date']    = isset($params['coach_access_end_date']) ? api_get_local_time($params['coach_access_end_date'], null, null, true) : null;
        return $params;
    }

    /**
     * Creates a new course code based in given code
     *
     * @param string    wanted code
     * <code>
     * $wanted_code = 'sess' if there are names like sess, sess1, sess2 in the
     * database already, the function will return: sess3
     * if the session name doesn't exist in the DB the same name is returned
     * </code>
     * @return string    wanted unused code
     */
    function generate_nice_next_session_name($session_name) {
        $session_name_ok = !self::session_name_exists($session_name);
        if (!$session_name_ok) {
           $table = Database::get_main_table(TABLE_MAIN_SESSION);
           $session_name = Database::escape_string($session_name);
           $sql = "SELECT count(*) as count FROM $table WHERE name LIKE '$session_name%'";
           $result = Database::query($sql);
           if (Database::num_rows($result) > 0 ) {
               $row = Database::fetch_array($result);
               $count = $row['count'] + 1;
               $session_name = $session_name.'_'.$count;
               $result = self::session_name_exists($session_name);
               if (!$result) {
                   return $session_name;
               }
           }
           return false;
        }
        return $session_name;
    }

    /**
     * Delete session
     * @author Carlos Vargas  from existing code
     * @param    array    id_checked
     * @param   boolean  optional, true if the function is called by a webservice, false otherwise.
     * @return    void    Nothing, or false on error
     * The parameters is a array to delete sessions
     **/
    public static function delete_session ($id_checked, $from_ws = false) {
        $tbl_session=                        Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_rel_course =            Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_course_rel_user=    Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session_rel_user=                Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_url_session                  = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);

        global $_user;
        if(is_array($id_checked)) {
            $id_checked = Database::escape_string(implode(',',$id_checked));
        } else {
            $id_checked = intval($id_checked);
        }

        if (!api_is_platform_admin() && !$from_ws) {
            $sql = 'SELECT session_admin_id FROM '.Database :: get_main_table(TABLE_MAIN_SESSION).' WHERE id='.$id_checked;
            $rs  = Database::query($sql);
            if (Database::result($rs,0,0)!=$_user['user_id']) {
                api_not_allowed(true);
            }
        }
        Database::query("DELETE FROM $tbl_session WHERE id IN($id_checked)");
        Database::query("DELETE FROM $tbl_session_rel_course WHERE id_session IN($id_checked)");
        Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session IN($id_checked)");
        Database::query("DELETE FROM $tbl_session_rel_user WHERE id_session IN($id_checked)");
        Database::query("DELETE FROM $tbl_url_session WHERE session_id IN($id_checked)");

        // delete extra session fields
        $t_sf         = Database::get_main_table(TABLE_MAIN_SESSION_FIELD);
        $t_sfv         = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_VALUES);

        // Delete extra fields from session where field variable is "SECCION"
        $sql = "SELECT t_sfv.field_id FROM $t_sfv t_sfv, $t_sf t_sf  WHERE t_sfv.session_id = '$id_checked' AND t_sf.field_variable = 'SECCION' ";
        $rs_field = Database::query($sql);

        $field_id = 0;
        if (Database::num_rows($rs_field) == 1) {
            $row_field = Database::fetch_row($rs_field);
            $field_id = $row_field[0];

            $sql_delete_sfv = "DELETE FROM $t_sfv WHERE session_id = '$id_checked' AND field_id = '$field_id'";
            $rs_delete_sfv = Database::query($sql_delete_sfv);
        }

        $sql = "SELECT * FROM $t_sfv WHERE field_id = '$field_id' ";
        $rs_field_id = Database::query($sql);

        if (Database::num_rows($rs_field_id) == 0) {
            $sql_delete_sf = "DELETE FROM $t_sf WHERE id = '$field_id'";
            $rs_delete_sf = Database::query($sql_delete_sf);
        }

        /*
        $sql = "SELECT distinct field_id FROM $t_sfv  WHERE session_id = '$id_checked'";
        $res_field_ids = @Database::query($sql);

        if (Database::num_rows($res_field_ids) > 0) {
            while($row_field_id = Database::fetch_row($res_field_ids)){
                $field_ids[] = $row_field_id[0];
            }
        }

        //delete from table_session_field_value from a given session id

        $sql_session_field_value = "DELETE FROM $t_sfv WHERE session_id = '$id_checked'";
        @Database::query($sql_session_field_value);

        $sql = "SELECT distinct field_id FROM $t_sfv";
        $res_field_all_ids = @Database::query($sql);

        if (Database::num_rows($res_field_all_ids) > 0) {
            while($row_field_all_id = Database::fetch_row($res_field_all_ids)){
                $field_all_ids[] = $row_field_all_id[0];
            }
        }

        if (count($field_ids) > 0 && count($field_all_ids) > 0) {
            foreach($field_ids as $field_id) {
                // check if field id is used into table field value
                if (in_array($field_id,$field_all_ids)) {
                    continue;
                } else {
                    $sql_session_field = "DELETE FROM $t_sf WHERE id = '$field_id'";
                    Database::query($sql_session_field);
                }
            }
        }
        */
        // Add event to system log
        $user_id = api_get_user_id();
        event_system(LOG_SESSION_DELETE, LOG_SESSION_ID, $id_checked, api_get_utc_datetime(), $user_id);
    }

     /**
      * Subscribes users (students)  to the given session and optionally (default) unsubscribes previous users
      * @author Carlos Vargas from existing code
      * @param    integer        Session ID
      * @param    array        List of user IDs
      * @param    bool        Whether to unsubscribe existing users (true, default) or not (false)
      * @return    void        Nothing, or false on error
      **/
    public static function suscribe_users_to_session($id_session, $user_list, $session_visibility = SESSION_VISIBLE_READ_ONLY, $empty_users = true, $send_email = false) {

        if ($id_session!= strval(intval($id_session))) return false;

        foreach ($user_list as $intUser){
            if ($intUser!= strval(intval($intUser))) return false;
        }

        $tbl_session_rel_course                = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session_rel_user                 = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session                        = Database::get_main_table(TABLE_MAIN_SESSION);

        $session_info = api_get_session_info($id_session);

        if ($session_info) {
            $session_name = $session_info['name'];
        } else {
            return false;
        }

        //from function parameter
           if (empty($session_visibility)) {
               $session_visibility    = $session_info['visibility']; //loaded from DB
               //default status loaded if empty
            if (empty($session_visibility))
                $session_visibility = SESSION_VISIBLE_READ_ONLY; // by default readonly 1
           } else {
               if (!in_array($session_visibility, array(SESSION_VISIBLE_READ_ONLY, SESSION_VISIBLE, SESSION_INVISIBLE))) {
                   $session_visibility = SESSION_VISIBLE_READ_ONLY;
               }
           }

        $sql = "SELECT id_user FROM $tbl_session_rel_course_rel_user WHERE id_session = '$id_session' AND status = 0";
        $result = Database::query($sql);
        $existingUsers = array();
        while ($row = Database::fetch_array($result)) {
            $existingUsers[] = $row['id_user'];
        }

        $sql = "SELECT c_id FROM $tbl_session_rel_course WHERE id_session = '$id_session'";
        $result = Database::query($sql);
        $course_list = array();
        while ($row = Database::fetch_array($result)) {
            $course_list[] = $row['c_id'];
        }

        if ($send_email) {
            //sending emails only
            if (is_array($user_list) && count($user_list)>0) {
                foreach ($user_list as $user_id) {
                    if (!in_array($user_id, $existingUsers)) {
                        $subject = '['.get_setting('siteName').'] '.get_lang('YourReg').' '.get_setting('siteName');
                        $user_info = api_get_user_info($user_id);
                        $content    = get_lang('Dear')." ".stripslashes($user_info['complete_name']).",\n\n".sprintf(get_lang('YouAreRegisterToSessionX'), $session_name) ." \n\n" .get_lang('Address') ." ". api_get_setting('siteName') ." ". get_lang('Is') ." : ". api_get_path(WEB_PATH) ."\n\n". get_lang('Problem'). "\n\n". get_lang('Formula').",\n\n".get_setting('administratorName')." ".get_setting('administratorSurname')."\n". get_lang('Manager'). " ".get_setting('siteName')."\nT. ".get_setting('administratorTelephone')."\n" .get_lang('Email') ." : ".get_setting('emailAdministrator');
                        MessageManager::send_message($user_id, $subject, $content, array(), array(), null, null, null, null, null);
                    }
                }
            }
        }

        foreach ($course_list as $courseId) {
            // for each course in the session
            $nbr_users = 0;
            $courseId = Database::escape_string($courseId);
            // delete existing users
            if ($empty_users) {
                foreach ($existingUsers as $existing_user) {
                    if (!in_array($existing_user, $user_list)) {
                        $sql = "DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session='$id_session' AND c_id ='$courseId' AND id_user='$existing_user' AND status = 0";
                        $result = Database::query($sql);
                        if (Database::affected_rows($result)) {
                            $nbr_users--;
                        }
                    }
                }
            }

            //Replace with this new function
            //
            // insert new users into session_rel_course_rel_user and ignore if they already exist
            foreach ($user_list as $enreg_user) {
                if(!in_array($enreg_user, $existingUsers)) {
                    $enreg_user = Database::escape_string($enreg_user);
                    $insert_sql = "INSERT IGNORE INTO $tbl_session_rel_course_rel_user(id_session, c_id, id_user, visibility, status) VALUES('$id_session','$courseId','$enreg_user','$session_visibility', '0')";
                    $result = Database::query($insert_sql);

                    if (Database::affected_rows($result)) {
                        $nbr_users++;
                    }
                }
            }
            // count users in this session-course relation
            $sql = "SELECT COUNT(id_user) as nbUsers FROM $tbl_session_rel_course_rel_user WHERE id_session='$id_session' AND c_id ='$courseId' AND status<>2";
            $rs = Database::query($sql);
            list($nbr_users) = Database::fetch_array($rs);
            // update the session-course relation to add the users total
            $update_sql = "UPDATE $tbl_session_rel_course SET nbr_users = $nbr_users WHERE id_session='$id_session' AND c_id ='$courseId'";
            Database::query($update_sql);
        }

        // Delete users from the session
        if ($empty_users === true) {
            Database::query("DELETE FROM $tbl_session_rel_user WHERE id_session = $id_session AND relation_type<>".SESSION_RELATION_TYPE_RRHH."");
        }

        // Insert missing users into session
        $nbr_users = 0;
        foreach ($user_list as $enreg_user) {
            $enreg_user = Database::escape_string($enreg_user);
            $insert_sql = "INSERT IGNORE INTO $tbl_session_rel_user (id_session, id_user) VALUES ('$id_session', '$enreg_user')";
            Database::query($insert_sql);

            //Reset moved_to just in case
            $update_sql = "UPDATE $tbl_session_rel_user SET moved_to = 0, moved_status = 0, moved_at ='0000-00-00 00:00:00'
                           WHERE id_session = $id_session AND id_user = $enreg_user";
            Database::query($update_sql);
            $nbr_users++;
        }

        // update number of users in the session
        $nbr_users = count($user_list);
        if ($empty_users) {
            // update number of users in the session
            $update_sql = "UPDATE $tbl_session SET nbr_users = $nbr_users WHERE id='$id_session' ";
            Database::query($update_sql);
        } else {
            $update_sql = "UPDATE $tbl_session SET nbr_users = nbr_users + $nbr_users WHERE id='$id_session' ";
            Database::query($update_sql);
        }
    }

    /**
     * @param $user_list
     * @param $session_id
     * @param $courseId
     * @param int $session_visibility
     * @return bool
     */
    static function subscribe_users_to_session_course($user_list, $session_id, $courseId, $session_visibility = SESSION_VISIBLE_READ_ONLY ) {
        $tbl_session_rel_course                = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);

        if (empty($user_list) || empty($session_id) || empty($course_code)) {
            return false;
        }

        $session_id = intval($session_id);
        $courseId = Database::escape_string($courseId);
        $session_visibility = intval($session_visibility);

        $nbr_users = 0;
        foreach ($user_list as $enreg_user) {
            //if (!in_array($enreg_user, $existingUsers)) {
                $enreg_user = intval($enreg_user);
                $insert_sql = "INSERT IGNORE INTO $tbl_session_rel_course_rel_user(id_session, c_id, id_user, visibility)
                               VALUES ('$session_id','$courseId','$enreg_user','$session_visibility')";
                $result = Database::query($insert_sql);
                if (Database::affected_rows($result)) {
                    $nbr_users++;
                }
            //}
        }
        // count users in this session-course relation
        $sql = "SELECT COUNT(id_user) as nbUsers FROM $tbl_session_rel_course_rel_user WHERE id_session='$session_id' AND c_id = '$courseId' AND status<>2";
        $rs = Database::query($sql);
        list($nbr_users) = Database::fetch_array($rs);
        // update the session-course relation to add the users total
        $update_sql = "UPDATE $tbl_session_rel_course SET nbr_users = $nbr_users WHERE id_session='$session_id' AND c_id = '$courseId'";
        Database::query($update_sql);
    }

    /**
     * Unsubscribe user from session
     *
     * @param int session id
     * @param int user id
     * @param int reason of unsubscription see function get_session_change_user_reasons()
     * @return bool true in case of success, false in case of error
     */
    public static function unsubscribe_user_from_session($session_id, $user_id) {
        $session_id = intval($session_id);
        $user_id = intval($user_id);
        $reason_id = intval($reason_id);

        $tbl_session_rel_course_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session_rel_course    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);

        $user_status = SessionManager::get_user_status_in_session($session_id, $user_id);

        if ($user_status['moved_to'] != 0) {
            //You can't subscribe a user that was moved
            return false;
        }

        $delete_sql = "DELETE FROM $tbl_session_rel_user WHERE id_session = '$session_id' AND id_user ='$user_id' AND relation_type<>".SESSION_RELATION_TYPE_RRHH."";
        $result = Database::query($delete_sql);
        $return = Database::affected_rows($result);

        // Update number of users
        $update_sql = "UPDATE $tbl_session SET nbr_users = nbr_users - $return WHERE id='$session_id' ";
        Database::query($update_sql);

        // Get the list of courses related to this session
        $course_list = SessionManager::get_course_list_by_session_id($session_id);

        if (!empty($course_list)) {
            foreach($course_list as $course) {
                $courseId = $course['id'];
                // Delete user from course
                $result = Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session='$session_id' AND c_id='$courseId' AND id_user='$user_id'");

                if (Database::affected_rows($result)) {
                    // Update number of users in this relation
                    Database::query("UPDATE $tbl_session_rel_course SET nbr_users=nbr_users - 1 WHERE id_session='$session_id' AND c_id ='$courseId'");
                }
            }
        }
        return true;
    }

    /**
     *
     * @param int $session_id
     * @param array user id list
     * @param int $courseId
     */
    static function unsubscribe_user_from_course_session($session_id, $user_list, $courseId) {
        $tbl_session_rel_course             = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);

        if (is_array($user_list) && count($user_list)>0 ) {
            array_map('intval', $user_list);
            $user_list = implode(',', $user_list);
        }
        $session_id = intval($session_id);
        $courseId = Database::escape_string($courseId);

        if (!empty($user_list)) {
            $result = Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session='$session_id' AND c_id='".$courseId."' AND id_user IN($user_list)");
            $nbr_affected_rows = Database::affected_rows($result);
            Database::query("UPDATE $tbl_session_rel_course SET nbr_users=nbr_users-$nbr_affected_rows WHERE id_session='$session_id' AND c_id='".$courseId."'");
        }
    }

     /** Subscribes courses to the given session and optionally (default) unsubscribes previous users
     * @author Carlos Vargas from existing code
     * @param    int        Session ID
     * @param    array    List of courses IDs
     * @param    bool    Whether to unsubscribe existing users (true, default) or not (false)
     * @return    void    Nothing, or false on error
     **/
     public static function add_courses_to_session($id_session, $course_list, $empty_courses = true) {
         // security checks
         if ($id_session!= strval(intval($id_session))) { return false; }

           // initialisation
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session                        = Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_rel_user                = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session_rel_course                = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);

         // get general coach ID
        $id_coach = Database::query("SELECT id_coach FROM $tbl_session WHERE id=$id_session");
        $id_coach = Database::fetch_array($id_coach);
        $id_coach = $id_coach[0];

        // get list of courses subscribed to this session
        $rs = Database::query("SELECT c_id FROM $tbl_session_rel_course WHERE id_session=$id_session");
        $existingCourses = Database::store_result($rs, 'ASSOC');
        $nbr_courses=count($existingCourses);

        // get list of users subscribed to this session
        $sql="SELECT id_user
            FROM $tbl_session_rel_user
            WHERE id_session = $id_session AND relation_type<>".SESSION_RELATION_TYPE_RRHH."";
        $result=Database::query($sql);
        $user_list=Database::store_result($result, 'ASSOC');

        // Remove existing courses from the session
        if ($empty_courses === true) {
            foreach ($existingCourses as $existingCourse) {
                if (!in_array($existingCourse['c_id'], $course_list)){
                    Database::query("DELETE FROM $tbl_session_rel_course WHERE c_id='".$existingCourse['c_id']."' AND id_session=$id_session");
                    Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE c_id='".$existingCourse['c_id']."' AND id_session=$id_session");
                }
            }
            $nbr_courses=0;
        }

        // Pass through the courses list we want to add to the session
        foreach ($course_list as $courseId) {
            $courseId = Database::escape_string($courseId);
            $exists = false;
            // check if the course we want to add is already subscribed
            foreach ($existingCourses as $existingCourse) {
                if ($courseId == $existingCourse['c_id']) {
                    $exists=true;
                }
            }
            if ($exists == false) {

                CourseManager::update_course_ranking($courseId, $id_session);

                //if the course isn't subscribed yet
                $sql_insert_rel_course= "INSERT INTO $tbl_session_rel_course (id_session, c_id) VALUES ('$id_session','$courseId')";

                Database::query($sql_insert_rel_course);
                //We add the current course in the existing courses array, to avoid adding another time the current course
                $existingCourses[]=array('c_id' => $courseId);
                $nbr_courses++;

                // subscribe all the users from the session to this course inside the session
                $nbr_users=0;
                foreach ($user_list as $enreg_user) {
                    $enreg_user_id = Database::escape_string($enreg_user['id_user']);
                    $sql_insert = "INSERT IGNORE INTO $tbl_session_rel_course_rel_user (id_session, c_id, id_user) VALUES ('$id_session','$courseId','$enreg_user_id')";
                    $result = Database::query($sql_insert);
                    if (Database::affected_rows($result)) {
                        $nbr_users++;
                    }
                }
                SessionManager::subscribe_users_to_session_course($user_list, $id_session, $courseId);
                Database::query("UPDATE $tbl_session_rel_course SET nbr_users=$nbr_users WHERE id_session='$id_session' AND c_id = '$courseId'");
            }
        }
        Database::query("UPDATE $tbl_session SET nbr_courses=$nbr_courses WHERE id='$id_session'");
     }

    /**
     * Unsubscribe course from a session
     *
     * @param int Session id
     * @param int Course id
     * @return bool True in case of success, false otherwise
     */
    public static function unsubscribe_course_from_session($session_id, $course_id) {
        $session_id = (int)$session_id;
        $course_id = (int)$course_id;

        $tbl_session_rel_course = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);

        // Unsubscribe course
        $result = Database::query("DELETE FROM $tbl_session_rel_course WHERE c_id ='$course_id' AND id_session='$session_id'");
        $nb_affected = Database::affected_rows($result);

        Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE c_id ='$course_id' AND id_session='$session_id'");
        if ($nb_affected > 0) {
            // Update number of courses in the session
            Database::query("UPDATE $tbl_session SET nbr_courses= nbr_courses + $nb_affected WHERE id='$session_id' ");
            return true;
        } else {
            return false;
        }
    }



  /**
  * Creates a new extra field for a given session
  * @param    string    Field's internal variable name
  * @param    int        Field's type
  * @param    string    Field's language var name
  * @return int     new extra field id
  */
    public static function create_session_extra_field ($fieldvarname, $fieldtype, $fieldtitle) {
        // database table definition
        $params = array('field_variable' => $fieldvarname,
                        'field_type' => $fieldtype,
                        'field_display_text' => $fieldtitle
        );
        $session_field = new SessionField();
        $field_id = $session_field->save($params);
        return $field_id;
    }

    /**
     * Update an extra field value for a given session
     * @param    integer    Course ID
     * @param    string    Field variable name
     * @param    string    Field value
     * @return    boolean    true if field updated, false otherwise
     */
    public static function update_session_extra_field_value ($session_id, $fname, $fvalue = null) {

        $session_field_value = new SessionFieldValue();
        $session_field_value->update($params);

        $t_sf         = Database::get_main_table(TABLE_MAIN_SESSION_FIELD);
        $t_sfv         = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_VALUES);
        $fname      = Database::escape_string($fname);
        $session_id = (int)$session_id;
        $tms        = api_get_utc_datetime();

        $fvalues = '';
        if (is_array($fvalue)) {
            foreach($fvalue as $val) {
                $fvalues .= Database::escape_string($val).';';
            }
            if(!empty($fvalues)) {
                $fvalues = substr($fvalues,0,-1);
            }
        } else {
            $fvalues = Database::escape_string($fvalue);
        }

        $sqlsf = "SELECT * FROM $t_sf WHERE field_variable='$fname'";
        $ressf = Database::query($sqlsf);
        if (Database::num_rows($ressf)==1) {
            // ok, the field exists
            //    Check if enumerated field, if the option is available
            $rowsf = Database::fetch_array($ressf);

            $sqlsfv = "SELECT * FROM $t_sfv WHERE session_id = '$session_id' AND field_id = '".$rowsf['id']."' ORDER BY id";
            $ressfv = Database::query($sqlsfv);
            $n = Database::num_rows($ressfv);
            if ($n>1) {
                //problem, we already have to values for this field and user combination - keep last one
                while($rowsfv = Database::fetch_array($ressfv)) {
                    if($n > 1) {
                        $sqld = "DELETE FROM $t_sfv WHERE id = ".$rowsfv['id'];
                        $resd = Database::query($sqld);
                        $n--;
                    }
                    $rowsfv = Database::fetch_array($ressfv);
                    if ($rowsfv['field_value'] != $fvalues) {
                        $sqlu = "UPDATE $t_sfv SET field_value = '$fvalues', tms = '$tms' WHERE id = ".$rowsfv['id'];
                        $resu = Database::query($sqlu);
                        return($resu?true:false);
                    }
                    return true;
                }
            } else if ($n==1) {
                //we need to update the current record
                $rowsfv = Database::fetch_array($ressfv);
                if($rowsfv['field_value'] != $fvalues) {
                    $sqlu = "UPDATE $t_sfv SET field_value = '$fvalues', tms = '$tms' WHERE id = ".$rowsfv['id'];
                    //error_log('UM::update_extra_field_value: '.$sqlu);
                    $resu = Database::query($sqlu);
                    return($resu?true:false);
                }
                return true;
            } else {
                $sqli = "INSERT INTO $t_sfv (session_id,field_id,field_value,tms) " .
                    "VALUES ('$session_id',".$rowsf['id'].",'$fvalues', '$tms')";
                //error_log('UM::update_extra_field_value: '.$sqli);
                $resi = Database::query($sqli);
                return($resi?true:false);
            }
        } else {
            return false; //field not found
        }
    }

    /**
    * Checks the relationship between a session and a course.
    * @param int $session_id
    * @param int $course_id
    * @return bool                Returns TRUE if the session and the course are related, FALSE otherwise.
    * */
    public static function relation_session_course_exist ($session_id, $courseId) {
        $tbl_session_course    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $return_value = false;
        $sql= "SELECT c_id FROM $tbl_session_course
              WHERE id_session = ".Database::escape_string($session_id)." AND c_id = '".Database::escape_string($courseId)."'";
        $result = Database::query($sql);
        $num = Database::num_rows($result);
        if ($num>0) {
            $return_value = true;
        }
        return $return_value;
    }

    /**
    * Get the session information by name
    * @param string session name
    * @return mixed false if the session does not exist, array if the session exist
    * */
    public static function get_session_by_name ($session_name) {
        $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);
        $sql = 'SELECT * FROM '.$tbl_session.' WHERE name="'.Database::escape_string($session_name).'"';
        $result = Database::query($sql);
        $num = Database::num_rows($result);
        if ($num) {
            return Database::fetch_array($result, 'ASSOC');
        } else {
            return false;
        }
    }

    /**
      * Create a session category
      * @author Jhon Hinojosa <jhon.hinojosa@dokeos.com>, from existing code
      * @param    string         name
      * @param     integer        year_start
      * @param     integer        month_start
      * @param     integer        day_start
      * @param     integer        year_end
      * @param     integer        month_end
      * @param     integer        day_end
      * @return $id_session;
      **/
    public static function create_category_session($sname,$syear_start,$smonth_start,$sday_start,$syear_end,$smonth_end, $sday_end){
        $tbl_session_category = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $name= trim($sname);
        $year_start= intval($syear_start);
        $month_start=intval($smonth_start);
        $day_start=intval($sday_start);
        $year_end=intval($syear_end);
        $month_end=intval($smonth_end);
        $day_end=intval($sday_end);

    $date_start = "$year_start-".(($month_start < 10)?"0$month_start":$month_start)."-".(($day_start < 10)?"0$day_start":$day_start);
    $date_end = "$year_end-".(($month_end < 10)?"0$month_end":$month_end)."-".(($day_end < 10)?"0$day_end":$day_end);

        if (empty($name)) {
            $msg=get_lang('SessionCategoryNameIsRequired');
            return $msg;
        } elseif (!$month_start || !$day_start || !$year_start || !checkdate($month_start,$day_start,$year_start)) {
            $msg=get_lang('InvalidStartDate');
            return $msg;
        } elseif (!$month_end && !$day_end && !$year_end) {
            $date_end = "null";
        } elseif (!$month_end || !$day_end || !$year_end || !checkdate($month_end,$day_end,$year_end)) {
            $msg=get_lang('InvalidEndDate');
            return $msg;
        } elseif($date_start >= $date_end) {
            $msg=get_lang('StartDateShouldBeBeforeEndDate');
            return $msg;
        }
        $access_url_id = api_get_current_access_url_id();
        $sql = "INSERT INTO $tbl_session_category (name, date_start, date_end, access_url_id)
                VALUES('".Database::escape_string($name)."','$date_start','$date_end', '$access_url_id')";
        Database::query($sql);
        $id_session = Database::insert_id();
        // Add event to system log
        $user_id = api_get_user_id();
        event_system(LOG_SESSION_CATEGORY_CREATE, LOG_SESSION_CATEGORY_ID, $id_session, api_get_utc_datetime(), $user_id);
        return $id_session;
    }

    /**
     * Edit a sessions categories
     * @author Jhon Hinojosa <jhon.hinojosa@dokeos.com>,from existing code
     * @param    integer        id
     * @param    string         name
     * @param     integer        year_start
     * @param     integer        month_start
     * @param     integer        day_start
     * @param     integer        year_end
     * @param     integer        month_end
     * @param     integer        day_end
     * @return $id;
     * The parameter id is a primary key
    **/
    public static function edit_category_session($id, $sname,$syear_start,$smonth_start,$sday_start,$syear_end,$smonth_end, $sday_end){
        $tbl_session_category = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $name= trim($sname);
        $year_start= intval($syear_start);
        $month_start=intval($smonth_start);
        $day_start=intval($sday_start);
        $year_end=intval($syear_end);
        $month_end=intval($smonth_end);
        $day_end=intval($sday_end);
        $id=intval($id);
        $date_start = "$year_start-".(($month_start < 10)?"0$month_start":$month_start)."-".(($day_start < 10)?"0$day_start":$day_start);
        $date_end = "$year_end-".(($month_end < 10)?"0$month_end":$month_end)."-".(($day_end < 10)?"0$day_end":$day_end);

        if (empty($name)) {
            $msg=get_lang('SessionCategoryNameIsRequired');
            return $msg;
        } elseif (!$month_start || !$day_start || !$year_start || !checkdate($month_start,$day_start,$year_start)) {
            $msg=get_lang('InvalidStartDate');
            return $msg;
        } elseif (!$month_end && !$day_end && !$year_end) {
            $date_end = null;
        } elseif (!$month_end || !$day_end || !$year_end || !checkdate($month_end,$day_end,$year_end)) {
            $msg=get_lang('InvalidEndDate');
            return $msg;
        } elseif($date_start >= $date_end) {
            $msg=get_lang('StartDateShouldBeBeforeEndDate');
            return $msg;
        }
        if ( $date_end <> null ) {
            $sql = "UPDATE $tbl_session_category SET name = '".Database::escape_string($name)."', date_start = '$date_start' ".
                ", date_end = '$date_end' WHERE id= '".$id."' ";
        } else {
            $sql = "UPDATE $tbl_session_category SET name = '".Database::escape_string($name)."', date_start = '$date_start' ".
                ", date_end = NULL WHERE id= '".$id."' ";
        }
        $result = Database::query($sql);
        return ($result? true:false);

    }

    /**
     * Delete sessions categories
     * @author Jhon Hinojosa <jhon.hinojosa@dokeos.com>, from existing code
     * @param    array    id_checked
     * @param    bool    include delete session
     * @param    bool    optional, true if the function is called by a webservice, false otherwise.
     * @return    void    Nothing, or false on error
     * The parameters is a array to delete sessions
     **/
    public static function delete_session_category($id_checked, $delete_session = false,$from_ws = false){
        $tbl_session_category = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);
        if (is_array($id_checked)) {
            $id_checked = Database::escape_string(implode(',',$id_checked));
        } else {
            $id_checked = intval($id_checked);
        }

        //Setting session_category_id to 0
        $sql = "UPDATE $tbl_session SET session_category_id = 0 WHERE session_category_id IN (".$id_checked.")";
        Database::query($sql);

        $sql = "SELECT id FROM $tbl_session WHERE session_category_id IN (".$id_checked.")";
        $result = @Database::query($sql);
        while ($rows = Database::fetch_array($result)) {
            $session_id = $rows['id'];
            if ($delete_session) {
                if ($from_ws) {
                    SessionManager::delete_session($session_id,true);
                } else {
                    SessionManager::delete_session($session_id);
                }
            }
        }
        $sql = "DELETE FROM $tbl_session_category WHERE id IN (".$id_checked.")";
        $rs = Database::query($sql);
        $result = Database::affected_rows($rs);

        // Add event to system log
        $user_id = api_get_user_id();

        event_system(LOG_SESSION_CATEGORY_DELETE, LOG_SESSION_CATEGORY_ID, $id_checked, api_get_utc_datetime(), $user_id);


        // delete extra session fields where field variable is "PERIODO"
        $t_sf         = Database::get_main_table(TABLE_MAIN_SESSION_FIELD);
        $t_sfv         = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_VALUES);

        $sql = "SELECT t_sfv.field_id FROM $t_sfv t_sfv, $t_sf t_sf  WHERE t_sfv.session_id = '$id_checked' AND t_sf.field_variable = 'PERIODO' ";
        $rs_field = Database::query($sql);

        $field_id = 0;
        if (Database::num_rows($rs_field) > 0) {
            $row_field = Database::fetch_row($rs_field);
            $field_id = $row_field[0];
            $sql_delete_sfv = "DELETE FROM $t_sfv WHERE session_id = '$id_checked' AND field_id = '$field_id'";
            $rs_delete_sfv = Database::query($sql_delete_sfv);
        }

        $sql = "SELECT * FROM $t_sfv WHERE field_id = '$field_id' ";
        $rs_field_id = Database::query($sql);

        if (Database::num_rows($rs_field_id) == 0) {
            $sql_delete_sf = "DELETE FROM $t_sf WHERE id = '$field_id'";
            $rs_delete_sf = Database::query($sql_delete_sf);
        }

        return true;
    }

    /**
     * Get a list of sessions of which the given conditions match with an = 'cond'
     * @param  array $conditions a list of condition (exemple : array('status =' =>STUDENT) or array('s.name LIKE' => "%$needle%")
     * @param  array $order_by a list of fields on which sort
     * @return array An array with all sessions of the platform.
     * @todo   optional course code parameter, optional sorting parameters...
    */
    public static function get_sessions_list($conditions = array(), $order_by = array()) {

        $session_table                = Database::get_main_table(TABLE_MAIN_SESSION);
        $session_category_table       = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $user_table                   = Database::get_main_table(TABLE_MAIN_USER);
        $table_access_url_rel_session = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);

        $access_url_id = api_get_current_access_url_id();

        $return_array = array();

        $sql_query = " SELECT s.id, s.name, s.nbr_courses, s.access_start_date, s.access_end_date, u.firstname, u.lastname, sc.name as category_name, s.promotion_id
                FROM $session_table s
                INNER JOIN $user_table u ON s.id_coach = u.user_id
                INNER JOIN $table_access_url_rel_session ar ON ar.session_id = s.id
                LEFT JOIN  $session_category_table sc ON s.session_category_id = sc.id
                WHERE ar.access_url_id = $access_url_id ";

        if (count($conditions) > 0) {
            $sql_query .= ' AND ';
            foreach ($conditions as $field=>$value) {
                $field = Database::escape_string($field);
                $value = Database::escape_string($value);
                $sql_query .= $field." '".$value."'";
            }
        }
        if (count($order_by)>0) {
            $sql_query .= ' ORDER BY '.Database::escape_string(implode(',',$order_by));
        }
        //echo $sql_query;
        $sql_result = Database::query($sql_query);
        if (Database::num_rows($sql_result)>0) {
            while ($result = Database::fetch_array($sql_result)) {
                $return_array[$result['id']] = $result;
            }
        }
        return $return_array;
    }

    /**
     * Get the session category information by id
     * @param string session category ID
     * @return mixed false if the session category does not exist, array if the session category exists
     */
    public static function get_session_category ($id) {
        $id = intval($id);
        $tbl_session_category = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $sql = "SELECT id, name, date_start, date_end FROM $tbl_session_category WHERE id= $id";
        $result = Database::query($sql);
        $num = Database::num_rows($result);
        if ($num>0){
            return Database::fetch_array($result);
        } else {
            return false;
        }
    }

    /**
     * Get all session categories (filter by access_url_id)
     * @return mixed false if the session category does not exist, array if the session category exists
     */
    public static function get_all_session_category() {
        $tbl_session_category = Database::get_main_table(TABLE_MAIN_SESSION_CATEGORY);
        $id = api_get_current_access_url_id();
        $sql = 'SELECT * FROM '.$tbl_session_category.' WHERE access_url_id ="'.$id.'" ORDER BY name ASC';
        $result = Database::query($sql);
        if (Database::num_rows($result) > 0 ){
            $data = Database::store_result($result,'ASSOC');
            return $data;
        } else {
            return false;
        }
    }

    /**
     * Assign a coach to course in session with status = 2
     * @param int          - user id
     * @param int          - session id
     * @param int      - course id
     * @param bool      - optional, if is true the user don't be a coach now, otherwise it'll assign a coach
     * @return bool true if there are affected rows, otherwise false
     */
    public static function set_coach_to_course_session($user_id, $session_id = 0, $courseId = '',$nocoach = false) {

        // Definition of variables
        $user_id = intval($user_id);

        if (!empty($session_id)) {
            $session_id = intval($session_id);
        } else {
            $session_id = api_get_session_id();
        }

        if (!empty($courseId)) {
            $courseId = Database::escape_string($courseId);
        } else {
            $courseId = api_get_course_int_id();
        }

        // definitios of tables
        $tbl_session_rel_course_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_user    = Database::get_main_table(TABLE_MAIN_USER);

        // check if user is a teacher
        $sql= "SELECT * FROM $tbl_user WHERE status='1' AND user_id = '$user_id'";

        $rs_check_user = Database::query($sql);

        if (Database::num_rows($rs_check_user) > 0) {

            if ($nocoach) {

                // check if user_id exits int session_rel_user
                $sql = "SELECT id_user FROM $tbl_session_rel_user WHERE id_session = '$session_id' AND id_user = '$user_id'";
                $res = Database::query($sql);

                if (Database::num_rows($res) > 0) {
                    // The user don't be a coach now
                    $sql = "UPDATE $tbl_session_rel_course_rel_user SET status = 0 WHERE id_session = '$session_id' AND c_id = '$courseId' AND id_user = '$user_id' ";
                    $rs_update = Database::query($sql);
                    if (Database::affected_rows($rs_update) > 0) return true;
                    else return false;
                } else {
                    // The user don't be a coach now
                    $sql = "DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session = '$session_id' AND c_id = '$courseId' AND id_user = '$user_id' ";
                    $rs_delete = Database::query($sql);
                    if (Database::affected_rows($rs_delete) > 0) return true;
                    else return false;
                }

            } else {
                // Assign user like a coach to course
                // First check if the user is registered in the course
                $sql = "SELECT id_user FROM $tbl_session_rel_course_rel_user WHERE id_session = '$session_id' AND c_id = '$courseId' AND id_user = '$user_id'";
                $rs_check = Database::query($sql);

                //Then update or insert
                if (Database::num_rows($rs_check) > 0) {
                    $sql = "UPDATE $tbl_session_rel_course_rel_user SET status = 2 WHERE id_session = '$session_id' AND c_id = '$courseId' AND id_user = '$user_id' ";
                    $rs_update = Database::query($sql);
                    if (Database::affected_rows($rs_update) > 0) return true;
                    else return false;
                } else {
                    $sql = " INSERT INTO $tbl_session_rel_course_rel_user(id_session, c_id, id_user, status) VALUES('$session_id', '$courseId', '$user_id', 2)";
                    $rs_insert = Database::query($sql);
                    if (Database::affected_rows($rs_insert) > 0) return true;
                    else return false;
                }
            }
        } else {
            return false;
        }
    }

    /**
      * Subscribes sessions to human resource manager (Dashboard feature)
      *    @param    int         Human Resource Manager id
      * @param    array         Sessions id
      * @param    int            Relation type
      **/
    public static function suscribe_sessions_to_hr_manager($hr_manager_id,$sessions_list) {
        global $_configuration;
        // Database Table Definitions
        $tbl_session                    =   Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_rel_user           =   Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session_rel_course_user    =   Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session_rel_access_url     =   Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);

        $hr_manager_id = intval($hr_manager_id);
        $affected_rows = 0;

        //Deleting assigned sessions to hrm_id
        if (api_is_multiple_url_enabled()) {
            $sql = "SELECT id_session FROM $tbl_session_rel_user s INNER JOIN $tbl_session_rel_access_url a ON (a.session_id = s.id_session)
                    WHERE id_user = $hr_manager_id AND relation_type=".SESSION_RELATION_TYPE_RRHH." AND access_url_id = ".api_get_current_access_url_id()."";
        } else {
            $sql = "SELECT id_session FROM $tbl_session_rel_user s WHERE id_user = $hr_manager_id AND relation_type=".SESSION_RELATION_TYPE_RRHH."";
        }
        $result = Database::query($sql);

        if (Database::num_rows($result) > 0) {
            while ($row = Database::fetch_array($result))   {
                 $sql = "DELETE FROM $tbl_session_rel_user
                         WHERE id_session = {$row['id_session']} AND id_user = $hr_manager_id AND relation_type=".SESSION_RELATION_TYPE_RRHH." ";
                 Database::query($sql);
            }
        }
        // inserting new sessions list
        if (is_array($sessions_list)) {
            foreach ($sessions_list as $session_id) {
                $session_id = intval($session_id);
                $insert_sql = "INSERT IGNORE INTO $tbl_session_rel_user(id_session, id_user, relation_type) VALUES($session_id, $hr_manager_id, '".SESSION_RELATION_TYPE_RRHH."')";
                $result = Database::query($insert_sql);
                $affected_rows = Database::affected_rows($result);
            }
        }
        return $affected_rows;
    }

    /**
     * Get sessions followed by human resources manager
     * @param int        Human resources manager or Session admin id
     * @return array     sessions
     */
    public static function get_sessions_followed_by_drh($hr_manager_id) {
        global $_configuration;
        // Database Table Definitions
        $tbl_session             =     Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_rel_user     =     Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session_rel_access_url =   Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);

        $hr_manager_id = intval($hr_manager_id);
        $assigned_sessions_to_hrm = array();

        if ($_configuration['multiple_access_urls']) {
           $sql = "SELECT * FROM $tbl_session s INNER JOIN $tbl_session_rel_user sru ON (sru.id_session = s.id) LEFT JOIN $tbl_session_rel_access_url a  ON (s.id = a.session_id)
                   WHERE sru.id_user = '$hr_manager_id' AND sru.relation_type = '".SESSION_RELATION_TYPE_RRHH."' AND access_url_id = ".api_get_current_access_url_id()."";
        } else {
            $sql = "SELECT * FROM $tbl_session s
                     INNER JOIN $tbl_session_rel_user sru ON sru.id_session = s.id AND sru.id_user = '$hr_manager_id' AND sru.relation_type = '".SESSION_RELATION_TYPE_RRHH."' ";
        }
        $rs_assigned_sessions = Database::query($sql);
        if (Database::num_rows($rs_assigned_sessions) > 0) {
            while ($row_assigned_sessions = Database::fetch_array($rs_assigned_sessions))    {
                $assigned_sessions_to_hrm[$row_assigned_sessions['id']] = $row_assigned_sessions;
            }
        }
        return $assigned_sessions_to_hrm;
    }

    /**
     * Gets the list of courses by session filtered by access_url
     * @param int session id
     * @return array list of courses
     */
    public static function get_course_list_by_session_id ($session_id) {
        $tbl_course                = Database::get_main_table(TABLE_MAIN_COURSE);
        $tbl_session_rel_course    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);

        // select the courses
        $sql = "SELECT c.*, c.id as real_id FROM $tbl_course c INNER JOIN $tbl_session_rel_course src ON c.id = src.c_id
                WHERE src.id_session = '$session_id'
                ORDER BY title;";
        $result     = Database::query($sql);
        $num_rows     = Database::num_rows($result);
        $courses = array();
        if ($num_rows > 0) {
            while ($row = Database::fetch_array($result,'ASSOC')) {
                $row['real_id'] = $row['id'];
                $courses[$row['id']] = $row;
            }
        }
        return $courses;
    }

    /**
     * Get the session id based on the original id and field name in the extra fields. Returns 0 if session was not found
     *
     * @param string Original session id
     * @param string Original field name
     * @return int Session id
     */
    public static function get_session_id_from_original_id($original_session_id_value, $original_session_id_name) {
        $t_sfv = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_VALUES);
        $table_field = Database::get_main_table(TABLE_MAIN_SESSION_FIELD);
        $sql_session = "SELECT session_id FROM $table_field sf INNER JOIN $t_sfv sfv ON sfv.field_id=sf.id WHERE field_variable='$original_session_id_name' AND field_value='$original_session_id_value'";
        $res_session = Database::query($sql_session);
        $row = Database::fetch_object($res_session);
        if ($row) {
            return $row->session_id;
        } else {
            return 0;
        }
    }

    /**
     * Get users by session
     * @param  int session id
     * @param    int    filter by status
     * @return  array a list with an user list
     */
    public static function get_users_by_session($id, $with_status = null) {
        if (empty($id)) {
            return array();
        }
        $tbl_user               = Database::get_main_table(TABLE_MAIN_USER);
        $tbl_session_rel_user   = Database::get_main_table(TABLE_MAIN_SESSION_USER);

        $id = intval($id);

        /*
         * sessions are not shared between urls
        if (api_is_multiple_url_enabled()) {
            $tbl_session_rel_url  = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
            $url_id = api_get_current_access_url_id();
            $sql = "SELECT u.user_id, lastname, firstname, username, access_url_id, moved_to, moved_status, moved_at
                FROM $tbl_user u
                INNER JOIN $tbl_session_rel_user su
                ON u.user_id = su.id_user AND su.relation_type<>".SESSION_RELATION_TYPE_RRHH."
                INNER JOIN $tbl_session_rel_url s ON (s.session_id = su.id_session)
                LEFT OUTER JOIN $tbl_session_rel_user uu ON (uu.user_id = u.user_id AND s.session_id = uu.id_session)
                WHERE su.id_session = $id AND (access_url_id = $url_id OR access_url_id is null )";
        } else {
            $sql = "SELECT u.user_id, lastname, firstname, username, moved_to, moved_status, moved_at
                    FROM $tbl_user u INNER JOIN $tbl_session_rel_user su
                    ON  u.user_id = su.id_user  AND
                        su.id_session = $id
                    WHERE 1 = 1
                    ";
        }*/
        $sql = "SELECT u.user_id,
                    lastname,
                    firstname,
                    username,
                    moved_to,
                    moved_status,
                    moved_at
                    FROM $tbl_user u INNER JOIN $tbl_session_rel_user su
                    ON  u.user_id = su.id_user  AND
                        su.id_session = $id
                    WHERE 1 = 1
                    ";

        if (isset($with_status) && $with_status != '') {
            $with_status = intval($with_status);
            $sql .= " AND relation_type = $with_status ";
        }

        $order_clause = api_sort_by_first_name() ? ' ORDER BY firstname, lastname' : ' ORDER BY lastname, firstname';

        $sql .= $order_clause;

        $return_array = array();
        $result = Database::query($sql);
        while ($row = Database::fetch_array($result,'ASSOC')) {
            $return_array[] = $row;
        }
        return $return_array;
    }

    /**
     * The general coach (field: session.id_coach)
     * @param int user id
     */
    public static function get_sessions_by_general_coach($user_id) {
        $session_table = Database::get_main_table(TABLE_MAIN_SESSION);
        $user_id = intval($user_id);

        // session where we are general coach
        $sql = "SELECT DISTINCT *
                FROM $session_table
                WHERE id_coach = $user_id";

        if (api_is_multiple_url_enabled()) {
            $tbl_session_rel_access_url= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
            $access_url_id = api_get_current_access_url_id();
            if ($access_url_id != -1) {
                $sql = 'SELECT DISTINCT session.*
                    FROM '.$session_table.' session INNER JOIN '.$tbl_session_rel_access_url.' session_rel_url
                    ON (session.id = session_rel_url.session_id)
                    WHERE id_coach = '.$user_id.' AND access_url_id = '.$access_url_id;
            }
        }
        $sql .= ' ORDER by name';
        $result = Database::query($sql);
        return Database::store_result($result, 'ASSOC');
    }

    /**
     * Get all sessions if the user is a sesion course coach of any session
     * @param int user id
     */
    static function get_sessions_by_coach($user_id) {
        // table definition
        $tbl_session = Database :: get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_course_user = Database :: get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $sql = 'SELECT DISTINCT session.*
                FROM ' . $tbl_session . ' as session
                INNER JOIN ' . $tbl_session_course_user . ' as session_course_user
                    ON session.id = session_course_user.id_session
                    AND session_course_user.id_user=' . $user_id.' AND session_course_user.status=2';

        if (api_is_multiple_url_enabled()) {
            $tbl_session_rel_access_url= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
            $access_url_id = api_get_current_access_url_id();
            if ($access_url_id != -1){
                $sql = 'SELECT DISTINCT session.*
                    FROM '.$tbl_session.' as session
                    INNER JOIN ' . $tbl_session_course_user . ' as session_course_user
                        ON session.id = session_course_user.id_session AND session_course_user.id_user = '.$user_id.' AND session_course_user.status=2
                    INNER JOIN '.$tbl_session_rel_access_url.' session_rel_url
                    ON (session.id = session_rel_url.session_id)
                    WHERE access_url_id = '.$access_url_id;
            }
        }
        //$sql .= " ORDER BY session.name";

        $result = Database::query($sql);
        return Database::store_result($result, 'ASSOC');
    }

    public static function get_sessions_coached_by_user($coach_id, $check_session_rel_user_visibility = false) {
        $sessions = self::get_sessions_by_general_coach($coach_id);
        $sessions_by_coach = self::get_sessions_by_coach($coach_id);

        if (!empty($sessions_by_coach)) {
            $sessions = array_merge($sessions, $sessions_by_coach);
        }
        //Remove  repeated sessions
        if (!empty($sessions)) {
            $clean_sessions = array();
            foreach ($sessions as $session) {
                $clean_sessions[$session['id']] = $session;
            }
            $sessions = $clean_sessions;
        }

        if ($check_session_rel_user_visibility) {
            if (!empty($sessions)) {
                $new_session = array();
                foreach ($sessions as $session) {
                    $visibility = api_get_session_visibility($session['id']);
                    if ($visibility == SESSION_INVISIBLE) {
                        continue;
                    }
                    $new_session[] = $session;
                }
                $sessions = $new_session;
            }
        }
        return $sessions;
    }

    /**
     * Gets user status within a session
     * @param $user_id
     * @param $course_code
     * @param $session_id
     * @return unknown_type
     */
    public static function get_user_status_in_course_session($user_id, $courseId, $session_id) {
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_user                           = Database::get_main_table(TABLE_MAIN_USER);
        $sql = "SELECT session_rcru.status
                FROM $tbl_session_rel_course_rel_user session_rcru, $tbl_user user
                WHERE session_rcru.id_user = user.user_id AND
                session_rcru.id_session = '".intval($session_id)."' AND
                session_rcru.c_id ='".Database::escape_string($courseId)."' AND
                user.user_id = ".intval($user_id);

        $result = Database::query($sql);
        $status = false;
        if (Database::num_rows($result)) {
            $status = Database::fetch_row($result);
            $status = $status['0'];
        }
        return $status;
    }

    static function get_user_status_in_session($session_id, $user_id) {
        $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $session_id = intval($session_id);
        $user_id = intval($user_id);

        $sql = "SELECT * FROM $tbl_session_rel_user WHERE id_user = $user_id AND id_session = $session_id";
        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            $result = Database::store_result($result, 'ASSOC');
            return $result[0];
        }
        return array();
    }

    function get_all_sessions_by_promotion($id) {
        $t = Database::get_main_table(TABLE_MAIN_SESSION);
        return Database::select('*', $t, array('where'=>array('promotion_id = ?'=>$id)));
    }

    function suscribe_sessions_to_promotion($promotion_id, $list) {
        $t = Database::get_main_table(TABLE_MAIN_SESSION);
        $params = array();
        $params['promotion_id'] = 0;
        Database::update($t, $params, array('promotion_id = ?'=>$promotion_id));

        $params['promotion_id'] = $promotion_id;
        if (!empty($list)) {
            foreach ($list as $session_id) {
                $session_id= intval($session_id);
                Database::update($t, $params, array('id = ?'=>$session_id));
            }
        }
    }

    /**
    * Updates a session status
    * @param    int     session id
    * @param    int     status
    */
    public function set_session_status($session_id, $status) {
        $t = Database::get_main_table(TABLE_MAIN_SESSION);
        $params['visibility'] = $status;
        Database::update($t, $params, array('id = ?'=>$session_id));
    }

    /**
     * Copies a session with the same data to a new session.
     * The new copy is not assigned to the same promotion. @see suscribe_sessions_to_promotions() for that
     * @param   int     Session ID
     * @param   bool    Whether to copy the relationship with courses
     * @param   bool    Whether to copy the relationship with users
     * @param    bool    New courses will be created
     * @return  int     The new session ID on success, 0 otherwise
     * @todo make sure the extra session fields are copied too
     */
    /**
     * @param $id
     * @param $copy_courses
     * @param $copy_users
     * @param $create_new_courses
     * @param $set_exercises_lp_invisible
     * @return int
     */
    public function copy_session($id, $copy_courses = true, $copy_users = true, $create_new_courses = false, $set_exercises_lp_invisible = false) {
        $id = intval($id);
        $params = self::fetch($id);

        $params['name'] = $params['name'].' '.get_lang('CopyLabelSuffix');
        $sid = self::add($params);
        if (!is_numeric($sid) || empty($sid)) {
            return false;
        }

        if ($copy_courses) {
            // Register courses from the original session to the new session
            $courses = self::get_course_list_by_session_id($id);

            $short_courses = $new_short_courses = array();
            if (is_array($courses) && count($courses)>0) {
                foreach ($courses as $course) {
                    $short_courses[] = $course;
                }
            }
            $courses = null;

            //We will copy the current courses of the session to new courses
            if (!empty($short_courses)) {
                if ($create_new_courses) {
                    //Just in case
                    if (function_exists('ini_set')) {
                    	api_set_memory_limit('256M');
                        ini_set('max_execution_time',0);
                    }
                    $params = array();
                    $params['skip_lp_dates'] = true;

                    foreach ($short_courses as $course_data) {
                        $course_info = CourseManager::copy_course_simple($course_data['title'].' '.get_lang('CopyLabelSuffix'), $course_data['course_code'], $id, $sid, $params);
                        if ($course_info) {
                            //By default new elements are invisible
                            if ($set_exercises_lp_invisible) {
                                $list       = new LearnpathList('', $course_info['code'], $sid);
                                $flat_list  = $list->get_flat_list();
                                if (!empty($flat_list)) {
                                    foreach ($flat_list as $lp_id => $data) {
                                        api_item_property_update($course_info, TOOL_LEARNPATH, $lp_id, 'invisible', api_get_user_id(), 0 ,0, 0, 0, $sid);
                                        api_item_property_update($course_info, TOOL_LEARNPATH, $lp_id, 'invisible', api_get_user_id(), 0 ,0, 0, 0);
                                    }
                                }
                                $quiz_table   = Database::get_course_table(TABLE_QUIZ_TEST);
                                $course_id     = $course_info['real_id'];

                                //@todo check this query
                                //Disabling quiz items
                                $sql = "UPDATE $quiz_table SET active = 0 WHERE c_id = $course_id ";
                                Database::query($sql);
                            }
                            $new_short_courses[] = $course_info['code'];
                        }
                    }
                } else {
                    foreach($short_courses as $course_data) {
                         $new_short_courses[] = $course_data['id'];
                    }
                }
                $short_courses = $new_short_courses;
                $res = self::add_courses_to_session($sid, $short_courses, true);
                $short_courses = null;
            }
        }
        if ($copy_users) {
            // Register users from the original session to the new session
            $users = self::get_users_by_session($id);
            $short_users = array();
            if (is_array($users) && count($users)>0) {
                foreach ($users as $user) {
                    $short_users[] = $user['user_id'];
                }
            }
            $users = null;
            //Subscribing in read only mode
            $res = self::suscribe_users_to_session($sid, $short_users, SESSION_VISIBLE_READ_ONLY, true, false);
            $short_users = null;
        }
        return $sid;
    }

    static function user_is_general_coach($user_id, $session_id) {
        $session_id = intval($session_id);
        $user_id = intval($user_id);
        $session_table = Database::get_main_table(TABLE_MAIN_SESSION);
        $sql = "SELECT DISTINCT id
                 FROM $session_table
                 WHERE session.id_coach =  '".$user_id."' AND id = '$session_id'";
        $result = Database::query($sql);
        if ($result && Database::num_rows($result)) {
            return true;
        }
        return false;
    }

    /**
     * Get the number of sessions
     * @param  int ID of the URL we want to filter on (optional)
     * @return int Number of sessions
     */
    public static function count_sessions($access_url_id=null) {
        $session_table = Database::get_main_table(TABLE_MAIN_SESSION);
        $access_url_rel_session_table = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_SESSION);
        $sql = "SELECT count(id) FROM $session_table s";
        if (!empty($access_url_id) && $access_url_id == intval($access_url_id)) {
            $sql .= ", $access_url_rel_session_table u ".
                    " WHERE s.id = u.session_id AND u.access_url_id = $access_url_id";
        }
        $res = Database::query($sql);
        $row = Database::fetch_row($res);
        return $row[0];
    }

    static function protect_session_edit($id = null) {
        $blockTeachers = !api_is_platform_admin() && (!api_is_teacher() || (api_is_teacher() && api_get_setting('allow_teachers_to_create_sessions') == 'false'));
        if ($blockTeachers) {
            api_not_allowed(true);
        }

        if (!empty($id)) {
            $session_info = self::fetch($id);

            if (empty($session_info)) {
                api_not_allowed(true);
            }

            if ($blockTeachers && !api_is_platform_admin() && api_get_setting('allow_session_admins_to_manage_all_sessions') != 'true') {
                if ($session_info['session_admin_id'] != api_get_user_id()) {
                    api_not_allowed(true);
                }
            }
            //Blocking teachers that want to edit another session
            if ($blockTeachers == false && $session_info['id_coach'] != api_get_user_id()) {
                api_not_allowed(true);
            }
        }
    }

    /**
     * @param $courseId
     * @return array
     */
    static function get_session_by_course($courseId)
    {
        $table_session_course = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $table_session = Database::get_main_table(TABLE_MAIN_SESSION);
        $courseId = Database::escape_string($courseId);
        $sql = "SELECT name, s.id FROM $table_session_course sc INNER JOIN $table_session s ON (sc.id_session = s.id)
                WHERE sc.c_id = '$courseId' ";
        $result = Database::query($sql);
        return Database::store_result($result);
    }

    public static function get_sessions_by_user($user_id, $ignore_visibility_for_admins = false)
    {
       $session_categories = UserManager::get_sessions_by_category($user_id, false, false, false, 0, null, null, $ignore_visibility_for_admins);
       $session_array = array();
       if (!empty($session_categories)) {
           foreach ($session_categories as $category) {
               if (isset($category['sessions'])) {
                   foreach ($category['sessions'] as $session) {
                       $session_array[] = $session;
                   }
               }
           }
       }
       return $session_array;
    }

    /**
     * @param string $file
     * @param bool $updatesession options:
     * true: if the session exists it will be updated
     * false: if session exists a new session will be created adding a counter session1, session2, etc
     * @param int $user_id
     * @param $logger
     * @param array convert a file row to an extra field. Example in CSV file there's a SessionID then it will
     * converted to extra_external_session_id if you set this: array('SessionId' => 'extra_external_session_id')
     * @param array extra fields
     * @param string extra field id
     * @param int $daysCoachAccessBeforeBeginning
     * @param int $daysCoachAccessAfterBeginning
     * @param int $sessionVisibility
     * @return array
     */
    static function importCSV(
        $file,
        $updatesession,
        $defaultUserId = null,
        $logger = null,
        $extraFields = array(),
        $extraFieldId = null,
        $daysCoachAccessBeforeBeginning = null,
        $daysCoachAccessAfterBeginning = null,
        $sessionVisibility = 1
    )
    {
        $content = file($file);

        $error_message = null;
        $session_counter = 0;

        if (empty($defaultUserId)) {
            $defaultUserId = api_get_user_id();
        }

        $eol = PHP_EOL;
        if (PHP_SAPI !='cli') {
            $eol = '<br />';
        }

        $debug = false;
        if (isset($logger)) {
            $debug = true;
        }

        $extraParameters = null;

        if (!empty($daysCoachAccessBeforeBeginning) && !empty($daysCoachAccessAfterBeginning)) {
            $extraParameters .= ' , nb_days_access_before_beginning = '.intval($daysCoachAccessBeforeBeginning);
            $extraParameters .= ' , nb_days_access_after_end = '.intval($daysCoachAccessAfterBeginning);
        }

        $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);
        $tbl_session_user           = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session_course         = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_course_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);

        $sessions = array();

        if (!api_strstr($content[0], ';')) {
            $error_message = get_lang('NotCSV');
        } else {
            $tag_names = array();

            foreach ($content as $key => $enreg) {

                $enreg = explode(';', trim($enreg));
                if ($key) {
                    foreach ($tag_names as $tag_key => $tag_name) {
                        $sessions[$key - 1][$tag_name] = $enreg[$tag_key];
                    }
                } else {
                    foreach ($enreg as $tag_name) {
                        $tag_names[] = api_preg_replace('/[^a-zA-Z0-9_\-]/', '', $tag_name);
                    }
                    if (!in_array('SessionName', $tag_names) || !in_array('DateStart', $tag_names) || !in_array('DateEnd', $tag_names)) {
                        $error_message = get_lang('NoNeededData');
                        break;
                    }
                }
            }

            // Looping the sessions.
            foreach ($sessions as $enreg) {
                $user_counter = 0;
                $course_counter = 0;

                if (isset($extraFields) && !empty($extraFields)) {
                    foreach ($extraFields as $original => $to) {
                        $enreg[$to] = $enreg[$original];
                    }
                }

                $session_name           = Database::escape_string($enreg['SessionName']);
                $date_start             = $enreg['DateStart'];
                $date_end               = $enreg['DateEnd'];
                $visibility             = isset($enreg['Visibility']) ? $enreg['Visibility'] : $sessionVisibility;
                $session_category_id    = $enreg['SessionCategory'];

                // Searching a coach.
                if (!empty($enreg['Coach'])) {
                    $coach_id = UserManager::get_user_id_from_username($enreg['Coach']);
                    if ($coach_id === false) {
                        // If the coach-user does not exist - I'm the coach.
                        $coach_id = $defaultUserId;
                    }
                } else {
                    $coach_id = $defaultUserId;
                }

                if (!$updatesession) {
                    // Always create a session.
                    $unique_name = false; // This MUST be initializead.
                    $i = 0;
                    // Change session name, verify that session doesn't exist.
                    while (!$unique_name) {
                        if ($i > 1) {
                            $suffix = ' - '.$i;
                        }
                        $sql = 'SELECT 1 FROM '.$tbl_session.' WHERE name="'.$session_name.$suffix.'"';
                        $rs = Database::query($sql);

                        if (Database::result($rs, 0, 0)) {
                            $i++;
                        } else {
                            $unique_name = true;
                            $session_name .= $suffix;
                        }
                    }

                    // Creating the session.
                    $sql_session = "INSERT IGNORE INTO $tbl_session SET
                            name = '".$session_name."',
                            id_coach = '$coach_id',
                            date_start = '$date_start',
                            date_end = '$date_end',
                            visibility = '$visibility',
                            session_category_id = '$session_category_id',
                            session_admin_id=".intval($defaultUserId).$extraParameters;
                    Database::query($sql_session);
                    $session_id = Database::insert_id();

                    if ($debug) {
                        if ($session_id) {

                            foreach ($enreg as $key => $value) {
                                if (substr($key, 0, 6) == 'extra_') { //an extra field
                                    self::update_session_extra_field_value($session_id, substr($key, 6), $value);
                                }
                            }

                            $logger->addInfo("Sessions - Session created: #$session_id - $session_name");
                        } else {
                            $logger->addError("Sessions - Session NOT created: $session_name");
                        }
                    }
                    $session_counter++;
                } else {
                    $sessionId = null;

                    if (isset($extraFields) && !empty($extraFields)) {
                        $sessionId = self::get_session_id_from_original_id($enreg['extra_'.$extraFieldId], $extraFieldId);

                        if (empty($sessionId)) {
                            $my_session_result = false;
                        } else {
                            $my_session_result = true;
                        }
                    } else {
                        $my_session_result = self::get_session_by_name($enreg['SessionName']);
                    }

                    if ($my_session_result === false) {

                        // Creating a session.
                        $sql_session = "INSERT IGNORE INTO $tbl_session SET
                                name = '$session_name',
                                id_coach = '$coach_id',
                                date_start = '$date_start',
                                date_end = '$date_end',
                                visibility = '$visibility',
                                session_category_id = '$session_category_id'".$extraParameters;

                        Database::query($sql_session);
                        // We get the last insert id.
                        $my_session_result = SessionManager::get_session_by_name($session_name);
                        $session_id = $my_session_result['id'];

                        if ($debug) {
                            if ($session_id) {
                                foreach ($enreg as $key => $value) {
                                    if (substr($key, 0, 6) == 'extra_') { //an extra field
                                        self::update_session_extra_field_value($session_id, substr($key, 6), $value);
                                    }
                                }
                                $logger->addInfo("Sessions - #$session_id created: $session_name");
                            } else {
                                $logger->addError("Sessions - Session NOT created: $session_name");
                            }
                        }
                    } else {

                        $params = array(
                            'id_coach' =>  $coach_id,
                            'date_start' => $date_start,
                            'date_end' => $date_end,
                            'visibility' => $visibility,
                            'session_category_id' => $session_category_id
                        );

                        if (!empty($fieldsToAvoidUpdate)) {
                            foreach ($fieldsToAvoidUpdate as $field) {
                                unset($params[$field]);
                            }
                        }

                        if (isset($sessionId) && !empty($sessionId)) {
                            // The session already exists, update it then.
                            Database::update($tbl_session, $params, array('id = ?' => $sessionId));
                            $session_id = $sessionId;
                        } else {
                            Database::update($tbl_session, $params, array("name = '?' " => $enreg['SessionName']));

                            $row = Database::query("SELECT id FROM $tbl_session WHERE name = '$session_name'");
                            list($session_id) = Database::fetch_array($row);
                        }

                        foreach ($enreg as $key => $value) {
                            if (substr($key, 0, 6) == 'extra_') { //an extra field
                                self::update_session_extra_field_value($session_id, substr($key, 6), $value);
                            }
                        }

                        Database::query("DELETE FROM $tbl_session_user WHERE id_session='$session_id'");
                        Database::query("DELETE FROM $tbl_session_course WHERE id_session='$session_id'");
                        Database::query("DELETE FROM $tbl_session_course_user WHERE id_session='$session_id'");
                    }
                    $session_counter++;
                }

                $users = explode('|', $enreg['Users']);

                // Adding the relationship "Session - User".
                if (is_array($users)) {
                    foreach ($users as $user) {
                        $user_id = UserManager::get_user_id_from_username($user);
                        if ($user_id !== false) {
                            // Insert new users.
                            $sql = "INSERT IGNORE INTO $tbl_session_user SET
                                    id_user = '$user_id',
                                    id_session = '$session_id'";
                            Database::query($sql);
                            if ($debug) {
                                $logger->addInfo("Sessions - Adding User #$user_id ($user) to session #$session_id");
                            }
                            $user_counter++;
                        }
                    }
                }

                $courses = explode('|', $enreg['Courses']);

                foreach ($courses as $course) {
                    $course_code = api_strtoupper(api_substr($course, 0, api_strpos($course, '[')));

                    if (CourseManager::course_exists($course_code)) {

                        $coach = api_strstr($course, '[');
                        $coach = api_substr($coach, 1, api_strpos($coach,']') - 1);

                        if (!empty($coach)) {
                            $coach_id = UserManager::get_user_id_from_username($coach);
                            if ($coach_id === false) {
                                $coach_id = '';
                            }
                        } else {
                            $coach = '';
                        }

                        // Adding the course to a session.
                        $sql_course = "INSERT IGNORE INTO $tbl_session_course SET
                                course_code = '$course_code',
                                id_session='$session_id'";
                        Database::query($sql_course);

                        if ($debug) {
                            $logger->addInfo("Sessions - Adding course '$course_code' to session #$session_id");
                        }
                        $course_counter++;

                        $pattern = "/\[(.*?)\]/";
                        preg_match_all($pattern, $course, $matches);

                        if (isset($matches[1])) {
                            $course_coaches = $matches[1][0];
                            $course_users   = $matches[1][1];
                        }

                        $course_users   = explode(',', $course_users);
                        $course_coaches = explode(',', $course_coaches);

                        // Adding coaches to session course user
                        if (!empty($course_coaches)) {
                            foreach ($course_coaches as $course_coach) {
                                $coach_id = UserManager::get_user_id_from_username($course_coach);
                                if ($coach_id !== false) {
                                    $sql = "INSERT IGNORE INTO $tbl_session_course_user SET
                                            id_user='$coach_id',
                                            course_code='$course_code',
                                            id_session = '$session_id',
                                            status = 2 ";
                                    Database::query($sql);
                                    if ($debug) {
                                        $logger->addInfo("Sessions - Adding course coach: user #$coach_id ($course_coach) to course: '$course_code' and session #$session_id");
                                    }
                                } else {
                                    $error_message .= get_lang('UserDoesNotExist').' : '.$course_coach.$eol;
                                }
                            }
                        }

                        $users_in_course_counter = 0;

                        // Adding the relationship "Session - Course - User".

                        foreach ($course_users as $user) {
                            $user_id = UserManager::get_user_id_from_username($user);

                            if ($user_id !== false) {
                                $sql = "INSERT IGNORE INTO $tbl_session_course_user SET
                                        id_user='$user_id',
                                        course_code='$course_code',
                                        id_session = '$session_id'";
                                Database::query($sql);
                                if ($debug) {
                                    $logger->addInfo("Sessions - Adding student: user #$user_id ($user) to course: '$course_code' and session #$session_id");
                                }
                                $users_in_course_counter++;
                            } else {
                                $error_message .= get_lang('UserDoesNotExist').': '.$user.$eol;
                            }
                        }

                        $sql = "UPDATE $tbl_session_course SET nbr_users='$users_in_course_counter' WHERE course_code='$course_code'";
                        Database::query($sql);

                        $course_info = CourseManager::get_course_information($course_code);
                        $inserted_in_course[$course_code] = $course_info['title'];
                    } else {
                        // TODO: We should create the course as in the XML import.
                    }


                    if (CourseManager::course_exists($course_code, true)) {

                        $list = CourseManager :: get_courses_info_from_visual_code($course_code);

                        foreach ($list as $vcourse) {

                            if ($vcourse['code'] == $course_code) {
                                // Ignore, this has already been inserted.
                            } else {

                                $coach = api_strstr($course, '[');
                                $coach = api_substr($coach, 1, api_strpos($coach,']') - 1);

                                // Adding the relationship "Session - Course".
                                $sql_course = "INSERT IGNORE INTO $tbl_session_course SET
                                                course_code = '".$vcourse['code']."',
                                                id_session='$session_id'";

                                Database::query($sql_course);

                                if ($debug) {
                                    $logger->addInfo("Sessions - Adding course to session: course: '".$vcourse['code']."' and session #$session_id");
                                }

                                // Adding coachs to session course user
                                foreach ($course_coaches as $course_coach) {
                                    $coach_id = UserManager::get_user_id_from_username($course_coach);
                                    if ($coach_id !== false) {
                                        $sql = "INSERT IGNORE INTO $tbl_session_course_user SET
                                                id_user='$coach_id',
                                                course_code='{$vcourse['code']}',
                                                id_session = '$session_id',
                                                status = 2 ";
                                        Database::query($sql);

                                        if ($debug) {
                                            $logger->addInfo("Sessions - Adding coach to session: user #$coach_id ($course_coach) course: '".$vcourse['code']."' and session #$session_id");
                                        }

                                    } else {
                                        $error_message .= get_lang('UserDoesNotExist').' : '.$course_coach.$eol;
                                    }
                                }


                                $users_in_course_counter = 0;
                                // Adding the relationship "Session - Course - User".
                                foreach ($course_users as $user) {
                                    $user_id = UserManager::get_user_id_from_username($user);
                                    if ($user_id !== false) {
                                        $sql = "INSERT IGNORE INTO $tbl_session_course_user SET
                                                id_user='$user_id',
                                                course_code='{$vcourse['code']}',
                                                id_session = '$session_id'";
                                        Database::query($sql);

                                        if ($debug) {
                                            $logger->addInfo("Sessions - Adding user to session: user #$user_id ($user) course: '".$vcourse['code']."' and session #$session_id");
                                        }

                                        $users_in_course_counter++;
                                    } else {
                                        $error_message .= get_lang('UserDoesNotExist').' : '.$user.$eol;
                                    }
                                }
                                Database::query("UPDATE $tbl_session_course SET nbr_users='$users_in_course_counter' WHERE course_code='".$vcourse['code']."'");
                            }
                        }
                        $inserted_in_course[$vcourse['code']] = $vcourse['title'];
                    }
                }
                $access_url_id = api_get_current_access_url_id();
                UrlManager::add_session_to_url($session_id, $access_url_id);
                $sql_update_users = "UPDATE $tbl_session SET nbr_users='$user_counter', nbr_courses='$course_counter' WHERE id='$session_id'";
                Database::query($sql_update_users);
            }
        }

        return array(
            'error_message' => $error_message,
            'session_counter' =>  $session_counter
        );
    }

    /**
     *  @todo Add constatns in a DB table
     */
    static function get_session_change_user_reasons() {
        return array (
            self::SESSION_CHANGE_USER_REASON_SCHEDULE => get_lang('ScheduleChanged'),
            self::SESSION_CHANGE_USER_REASON_CLASSROOM => get_lang('ClassRoomChanged'),
            self::SESSION_CHANGE_USER_REASON_LOCATION => get_lang('LocationChanged'),
            //self::SESSION_CHANGE_USER_REASON_ENROLLMENT_ANNULATION => get_lang('EnrollmentAnnulation'),
        );
    }

    static function get_session_change_user_reasons_variations() {
        return array (
            self::SESSION_CHANGE_USER_REASON_SCHEDULE => array(
                'default' => get_lang('ScheduleChanged'),
                'from' => get_lang('ScheduleChangedFrom'),
                'to' => get_lang('ScheduleChangedTo'),
            ),
            self::SESSION_CHANGE_USER_REASON_CLASSROOM => array(
                'default' => get_lang('ClassRoomChanged'),
                'from' => get_lang('ClassRoomChangedFrom'),
                'to' => get_lang('ClassRoomChangedTo'),
            ),
            self::SESSION_CHANGE_USER_REASON_LOCATION => array(
                'default' => get_lang('LocationChanged'),
                'from' => get_lang('LocationChangedFrom'),
                'to' => get_lang('LocationChangedTo'),
            )
        );
    }

    static function get_session_change_user_reasons_variations_by_id($id, $type) {
        $reasons = self::get_session_change_user_reasons_variations();
        $my_reason = isset($reasons[$id]) ? $reasons[$id] : null;
        return isset($my_reason[$type]) ? $my_reason[$type] : null;
    }

    static function get_session_changed_reason_label($id, $type) {
        switch ($type) {
            case 'origin':
                break;
            case 'destination':
                break;
        }

    }

    /**
     * Gets the reason name
     * @param int reason id
     */
    static function get_session_change_user_reason($id) {
        $reasons = self::get_session_change_user_reasons();
        return isset($reasons[$id]) ? $reasons[$id] : null;
    }

    /**
     * Changes the user from one session to another due a reason
     * @params int user_id
     * @params int old session id
     * @params int new session id
     * @params int see SessionManager::get_session_change_user_reasons()
     */
    static function change_user_session($user_id, $old_session_id, $new_session_id, $reason_id) {
        if (!empty($user_id) && !empty($old_session_id) && !empty($new_session_id)) {
            $user_id = intval($user_id);
            $old_session_id = intval($old_session_id);
            $new_session_id = intval($new_session_id);
            $reason_id = intval($reason_id);

            $tbl_session_rel_course    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
            $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
            $tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);

            // Update number of users
            $update_sql = "UPDATE $tbl_session SET nbr_users = nbr_users - 1 WHERE id = '$old_session_id' ";
            Database::query($update_sql);

            // Get the list of courses related to this session
            $course_list = SessionManager::get_course_list_by_session_id($old_session_id);
            if (!empty($course_list)) {
                foreach ($course_list as $course) {
                    $courseId = $course['id'];
                    // Delete user from course
                    //Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session='$session_id' AND course_code='$course_code' AND id_user='$user_id'");
                    //if (Database::affected_rows()) {
                        // Update number of users in this relation
                        $sql = "UPDATE $tbl_session_rel_course SET nbr_users = nbr_users - 1 WHERE id_session = '$old_session_id' AND c_id='$courseId'";
                        Database::query($sql);
                    //}
                }
            }

            //Deal with reasons
            switch ($reason_id) {
                case self::SESSION_CHANGE_USER_REASON_SCHEDULE:
                case self::SESSION_CHANGE_USER_REASON_CLASSROOM:
                case self::SESSION_CHANGE_USER_REASON_LOCATION:
                    //Adding to the new session
                    self::suscribe_users_to_session($new_session_id, array($user_id), null, false);

                    //Setting move_to if session was provided
                    $sql = "UPDATE $tbl_session_rel_user SET moved_to = '$new_session_id'
                            WHERE id_session = '$old_session_id' AND id_user ='$user_id'";
                    Database::query($sql);
                    break;
                case self::SESSION_CHANGE_USER_REASON_ENROLLMENT_ANNULATION:
                    UserManager::deactivate_users(array($user_id));
                    break;
            }

            $now = api_get_utc_datetime();
            //Setting the moved_status
            $sql = "UPDATE $tbl_session_rel_user SET moved_status = $reason_id, moved_at = '$now'
                    WHERE id_session = '$old_session_id' AND id_user ='$user_id'";
            Database::query($sql);

            return true;
        }
        return;
    }


    /**
     * Get users inside a course session
     */
    static function get_users_in_course_session($courseId, $id_session, $sort, $direction, $from = null, $limit = null) {
        $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_user                           = Database::get_main_table(TABLE_MAIN_USER);

        $courseId = Database::escape_string($courseId);
        $id_session  = Database::escape_string($id_session);
        $from = intval($from);
        $limit = intval($limit);

        $is_western_name_order = api_is_western_name_order();

        //Select the number of users
        $sql = " SELECT DISTINCT u.user_id,".($is_western_name_order ? 'u.firstname, u.lastname' : 'u.lastname, u.firstname').", u.username, scru.id_user as is_subscribed
                 FROM $tbl_session_rel_user sru INNER JOIN $tbl_user u ON (u.user_id=sru.id_user)
                      LEFT JOIN $tbl_session_rel_course_rel_user scru ON (u.user_id = scru.id_user AND scru.c_id = '".$courseId."' )
                 WHERE  sru.id_session = '$id_session' AND
                        sru.moved_to = 0 AND sru.moved_status <> ".SessionManager::SESSION_CHANGE_USER_REASON_ENROLLMENT_ANNULATION." AND
                        sru.relation_type<>".SESSION_RELATION_TYPE_RRHH;
        $sql .= " ORDER BY $sort $direction ";

        if (!empty($from) && !empty($limit)) {
            $sql .= " LIMIT $from, $limit";
        }

        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            return Database::store_result($result);
        }
        return false;
    }

    /**
     * @param $courseId
     * @param $id_session
     * @return int|mixed
     */
    static function get_count_users_in_course_session($courseId, $id_session) {
        $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);

        //Select the number of users
        $sql = " SELECT count(*) FROM $tbl_session_rel_user sru, $tbl_session_rel_course_rel_user srcru
                 WHERE  srcru.id_user = sru.id_user AND
                        srcru.id_session = sru.id_session AND
                        srcru.c_id = '".Database::escape_string($courseId)."' AND
                        srcru.id_session = '".intval($id_session)."'  AND
                        (sru.moved_to = 0 AND sru.moved_status <> ".SessionManager::SESSION_CHANGE_USER_REASON_ENROLLMENT_ANNULATION.") AND
                        sru.relation_type<>".SESSION_RELATION_TYPE_RRHH;

        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            return Database::result($result,0,0);
        }
        return 0;
    }

    /**
     * Get the list of coaches (only user ids)
     * @param string course_code
     * @param in session_id
     * @return array
     */
    static function get_session_course_coaches($courseId, $session_id)
    {
        $tbl_user                            = Database::get_main_table(TABLE_MAIN_USER);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);

        // Get coachs of the courses in session
        $sql = "SELECT user.user_id FROM $tbl_session_rel_course_rel_user session_rcru, $tbl_user user
                WHERE   session_rcru.id_user = user.user_id AND
                        session_rcru.id_session = '".intval($session_id)."' AND
                        session_rcru.c_id ='".Database::escape_string($courseId)."' AND
                        session_rcru.status=2";
        $result = Database::query($sql);
        return Database::store_result($result);
    }

    static function get_session_course_coaches_by_user($courseId, $session_id, $user_id)
    {
        $tbl_user                            = Database::get_main_table(TABLE_MAIN_USER);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);

        // Get coachs of the courses in session
        $sql = "SELECT user.user_id FROM $tbl_session_rel_course_rel_user session_rcru, $tbl_user user
                WHERE   session_rcru.id_user = user.user_id AND
                        session_rcru.id_session = '".intval($session_id)."' AND
                        session_rcru.c_id ='".Database::escape_string($courseId)."' AND
                        session_rcru.status=2";
        $result = Database::query($sql);
        return Database::store_result($result);
    }


    static function get_session_course_coaches_to_string($courseId, $session_id)
    {
        $coaches = self::get_session_course_coaches($courseId, $session_id);
        if (!empty($coaches)) {
            $coach_list = array();
            foreach ($coaches as $coach_info) {
                $user_info = api_get_user_info($coach_info['user_id']);
                $coach_list[] = $user_info['complete_name'];
            }
            if (!empty($coach_list)) {
                return implode(', ', $coach_list);
            }
        }
        return get_lang('None');
    }

    /**
     * @param $id_session
     * @param $courseId
     */
    static function delete_course_in_session($id_session, $courseId)
    {
        $tbl_session_rel_course                = Database::get_main_table(TABLE_MAIN_SESSION_COURSE);
        $tbl_session_rel_course_rel_user    = Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER);
        $tbl_session                        = Database::get_main_table(TABLE_MAIN_SESSION);

        $id_session = intval($id_session);
        $courseId = Database::escape_string($courseId);

        if (!empty($id_session) && !empty($courseId)) {

            $result = Database::query("DELETE FROM $tbl_session_rel_course WHERE id_session='$id_session' AND c_id = '$courseId'");
            $nbr_affected_rows = Database::affected_rows($result);

            Database::query("DELETE FROM $tbl_session_rel_course_rel_user WHERE id_session='$id_session' AND c_id = '$courseId'");
            Database::query("UPDATE $tbl_session SET nbr_courses=nbr_courses-$nbr_affected_rows WHERE id='$id_session'");
        }
    }

    static function get_session_rel_user_by_moved_to($session_id, $user_id) {
        $tbl_session_rel_user = Database::get_main_table(TABLE_MAIN_SESSION_USER);
        $sql = "SELECT id_session, moved_status, moved_at FROM $tbl_session_rel_user WHERE id_user = $user_id AND moved_to = $session_id LIMIT 1";
        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            $result = Database::store_result($result,'ASSOC');
            return $result[0];
        }
        return false;
    }

    static function get_coaches_by_keyword($tag) {
        $tbl_user = Database::get_main_table(TABLE_MAIN_USER);

        $order_clause = api_sort_by_first_name() ? ' ORDER BY firstname, lastname, username' : ' ORDER BY lastname, firstname, username';

        $select ="SELECT user.user_id, lastname, firstname, username ";
        $sql = " $select FROM $tbl_user user WHERE status='1'";

        $tag = Database::escape_string($tag);

        $where_condition = array();
        if (!empty($tag)) {
            $condition = ' LIKE "%'.$tag.'%"';
            $where_condition = array( "firstname $condition",
                                      "lastname $condition",
                                      "username $condition"
            );
            $where_condition = ' AND  ('.implode(' OR ',  $where_condition).') ';
        }

        if (api_is_multiple_url_enabled()) {
            $tbl_user_rel_access_url= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
            $access_url_id = api_get_current_access_url_id();
            if ($access_url_id != -1){
                $sql = $select.' FROM '.$tbl_user.' user
                        INNER JOIN '.$tbl_user_rel_access_url.' url_user ON (url_user.user_id=user.user_id)
                        WHERE access_url_id = '.$access_url_id.'  AND status = 1';
            }
        }
        $sql .= $where_condition.$order_clause;
        $result = Database::query($sql);
        return Database::store_result($result, 'ASSOC');
    }

    /**
     * Returns a human readable string
     * @params array An array with all the session dates
     * @return string
     */
    static function parse_session_dates($session_info)
    {
        //This will clean the variables if 0000-00-00 00:00:00 the variable will be empty
        $start_date = null;
        $end_date = null;
        if (isset($session_info['display_start_date'])) {
            $start_date = api_get_local_time($session_info['display_start_date'], null, null, true);
        }
        if (isset($session_info['display_end_date'])) {
            $end_date = api_get_local_time($session_info['display_end_date'], null, null, true);
        }
        $msg_date = null;
        if (!empty($start_date) && !empty($end_date)) {
            //$msg_date = get_lang('From').' '.$start_date.' '.get_lang('To').' '.$end_date;
            $msg_date =  sprintf(get_lang('FromDateXToDateY'), $start_date, $end_date);
        } else {
            if (!empty($start_date)) {
                $msg_date = get_lang('From').' '.$start_date;
            }
            if (!empty($end_date)) {
                $msg_date = get_lang('Until').' '.$end_date;
            }
        }
        return $msg_date;
    }

    public static function get_session_columns($list_type = 'simple') {
        //Column config
        $operators = array('cn', 'nc');
        $date_operators = array('gt', 'ge', 'lt', 'le');

        switch ($list_type) {
            case 'simple':
                $columns = array(
                    get_lang('Name'),
                    get_lang('SessionDisplayStartDate'),
                    get_lang('SessionDisplayEndDate'),
                    //get_lang('SessionCategoryName'),
                    //get_lang('Coach'),
                    //get_lang('Status'),
                    get_lang('Visibility'),
                    //get_lang('CourseTitle'),
                );
                $column_model = array (
                    array('name'=>'name',                'index'=>'name',          'width'=>'200',  'align'=>'left', 'search' => 'true', 'searchoptions' => array('sopt' => $operators)),
                    array('name'=>'display_start_date',  'index'=>'display_start_date', 'width'=>'70',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('dataInit' => 'date_pick_today', 'sopt' => $date_operators)),
                    array('name'=>'display_end_date',    'index'=>'display_end_date', 'width'=>'70',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('dataInit' => 'date_pick_one_month', 'sopt' => $date_operators)),
         //           array('name'=>'category_name',       'index'=>'category_name', 'hidden' => 'true', 'width'=>'70',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true', 'sopt' => $operators)),
                    //array('name'=>'access_start_date',     'index'=>'access_start_date',    'width'=>'60',   'align'=>'left', 'search' => 'true',  'searchoptions' => array('searchhidden' =>'true')),
                    //array('name'=>'access_end_date',       'index'=>'access_end_date',      'width'=>'60',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true')),
                    //array('name'=>'coach_name',           'index'=>'coach_name',     'width'=>'70',   'align'=>'left', 'search' => 'false', 'searchoptions' => array('sopt' => $operators)),
                    //array('name'=>'session_active',       'index'=>'session_active', 'width'=>'25',   'align'=>'left', 'search' => 'true', 'stype'=>'select',
                          //for the bottom bar
                    //      'searchoptions' => array(
                    //                        'defaultValue'  => '1',
                    //                        'value'         => '1:'.get_lang('Active').';0:'.get_lang('Inactive')),
                          //for the top bar
                          //'editoptions' => array('value' => '" ":'.get_lang('All').';1:'.get_lang('Active').';0:'.get_lang('Inactive'))
                    //),
                    //array('name'=>'course_code',    'index'=>'course_code',    'width'=>'40', 'hidden' => 'true', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true','sopt' => $operators)),
                    array('name'=>'visibility',     'index'=>'visibility',      'width'=>'40',   'align'=>'left', 'search' => 'false'),
                    //array('name'=>'course_title',    'index'=>'course_title',   'width'=>'50',   'hidden' => 'true', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true','sopt' => $operators)),
                );
                break;
            case 'complete':
                $columns = array(
                    get_lang('Name'),
                    get_lang('SessionDisplayStartDate'),
                    get_lang('SessionDisplayEndDate'),
                    //get_lang('SessionCategoryName'),
                    get_lang('Coach'),
                    get_lang('Status'),
                    get_lang('Visibility'),
                    get_lang('CourseTitle'),
                );
                $column_model = array (
                    array('name'=>'name',                'index'=>'name',          'width'=>'200',  'align'=>'left', 'search' => 'true', 'searchoptions' => array('sopt' => $operators)),
                    array('name'=>'display_start_date',  'index'=>'display_start_date', 'width'=>'70',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('dataInit' => 'date_pick_today', 'sopt' => $date_operators)),
                    array('name'=>'display_end_date',    'index'=>'display_end_date', 'width'=>'70',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('dataInit' => 'date_pick_one_month', 'sopt' => $date_operators)),
         //           array('name'=>'category_name',       'index'=>'category_name', 'hidden' => 'true', 'width'=>'70',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true', 'sopt' => $operators)),
                    //array('name'=>'access_start_date',     'index'=>'access_start_date',    'width'=>'60',   'align'=>'left', 'search' => 'true',  'searchoptions' => array('searchhidden' =>'true')),
                    //array('name'=>'access_end_date',       'index'=>'access_end_date',      'width'=>'60',   'align'=>'left', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true')),
                    array('name'=>'coach_name',           'index'=>'coach_name',     'width'=>'70',   'align'=>'left', 'search' => 'false', 'searchoptions' => array('sopt' => $operators)),
                    array('name'=>'session_active',       'index'=>'session_active', 'width'=>'25',   'align'=>'left', 'search' => 'true', 'stype'=>'select',
                          //for the bottom bar
                          'searchoptions' => array(
                                            'defaultValue'  => '1',
                                            'value'         => '1:'.get_lang('Active').';0:'.get_lang('Inactive')),
                          //for the top bar
                          'editoptions' => array('value' => '" ":'.get_lang('All').';1:'.get_lang('Active').';0:'.get_lang('Inactive'))
                    ),
                    //array('name'=>'course_code',    'index'=>'course_code',    'width'=>'40', 'hidden' => 'true', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true','sopt' => $operators)),
                    array('name'=>'visibility',     'index'=>'visibility',      'width'=>'40',   'align'=>'left', 'search' => 'false'),
                    array('name'=>'course_title',    'index'=>'course_title',   'width'=>'50',   'hidden' => 'true', 'search' => 'true', 'searchoptions' => array('searchhidden' =>'true','sopt' => $operators)),
                );
                break;
        }

        // Inject extra session fields
        $session_field = new SessionField();
        $rules = $session_field->getRules($columns, $column_model);

        $column_model[] = array('name'=>'actions', 'index'=>'actions', 'width'=>'80',  'align'=>'left','formatter'=>'action_formatter','sortable'=>'false', 'search' => 'false');
        $columns[] = get_lang('Actions');

        foreach ($column_model as $col_model) {
            $simple_column_name[] = $col_model['name'];
        }

        $return_array =  array(
            'columns' => $columns,
            'column_model' => $column_model,
            'rules' => $rules,
            'simple_column_name' => $simple_column_name
        );
        return $return_array;
    }

    static function getSessionsByCategory($categoryId)
    {
        $categoryId = intval($categoryId);
        $tableSession = Database::get_main_table(TABLE_MAIN_SESSION);
        $sql = "select * FROM $tableSession WHERE session_category_id = $categoryId";
        $result = Database::query($sql);
        return Database::store_result($result);

    }
}
