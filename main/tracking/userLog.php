<?php
/* For licensing terms, see /license.txt */

// TODO: Is this file deprecated?
exit;

/**
 * @package chamilo.tracking
 * @todo clean code - structure is unclear and difficult to modify
 */
/**
 * Code
 */

/* INIT SECTION */


$uInfo = isset($_REQUEST['uInfo']) ? $_REQUEST['uInfo'] : null;
$view  = isset($_REQUEST['view']) ? $_REQUEST['view'] : null;

// name of the language file that needs to be included
$language_file = 'tracking';

// Including the global initialization file
require_once '../inc/global.inc.php';

// the section (for the tabs)
$this_section = "session_my_space";

// variables
$user_id = api_get_user_id();
$course_id = api_get_course_id();
$courseId = api_get_course_int_id();

//YW Hack security to quick fix RolesRights bug
$is_allowed = true;

/* Libraries */

require_once api_get_path(LIBRARY_PATH).'statsUtils.lib.inc.php';
require_once api_get_path(SYS_CODE_PATH).'resourcelinker/resourcelinker.inc.php';
require_once api_get_path(SYS_CODE_PATH).'exercice/hotpotatoes.lib.php';

/* Header */

if ($uInfo) {
    $interbreadcrumb[]= array ('url'=>'../user/userInfo.php?uInfo='.Security::remove_XSS($uInfo), "name"=> api_ucfirst(get_lang('Users')));
}

$nameTools = get_lang('ToolName');

$htmlHeadXtra[] = "<style>
/*<![CDATA[*/
.secLine {background-color : #E6E6E6;}
.content {padding-left : 15px;padding-right : 15px; }
.specialLink{color : #0000FF;}
/*]]>*/
</style>
<style media='print' type='text/css'>
/*<![CDATA[*/
td {border-bottom: thin dashed gray;}
/*]]>*/
</style>";

Display::display_header($nameTools,"Tracking");

/*	Constants and variables */

$is_allowedToTrack = api_is_course_admin();
$is_course_member = CourseManager::is_user_subscribed_in_real_or_linked_course($user_id, $courseId);

// Database Table Definitions
$TABLECOURSUSER	        	= Database::get_main_table(TABLE_MAIN_COURSE_USER);
$TABLEUSER	        		= Database::get_main_table(TABLE_MAIN_USER);
$TABLECOURSE_GROUPSUSER 	= Database::get_course_table(TABLE_GROUP_USER);

$tbl_learnpath_main = Database::get_course_table(TABLE_LP_MAIN);
$tbl_learnpath_item = Database::get_course_table(TABLE_LP_ITEM);
$tbl_learnpath_view = Database::get_course_table(TABLE_LP_VIEW);
$tbl_learnpath_item_view = Database::get_course_table(TABLE_LP_ITEM_VIEW);

$documentPath=api_get_path(SYS_COURSE_PATH).$_course['path'].'/document';

// The variables for the days and the months
$DaysShort = api_get_week_days_short();
$DaysLong = api_get_week_days_long();
$MonthsLong = api_get_months_long();
$MonthsShort = api_get_months_short();

//$is_allowedToTrack = $is_groupTutor; // allowed to track only user of one group
//$is_allowedToTrackEverybodyInCourse = $is_allowed[EDIT_RIGHT]; // allowed to track all students in course
//YW hack security to fix RolesRights bug
$is_allowedToTrack = true; // allowed to track only user of one group
$is_allowedToTrackEverybodyInCourse = $is_allowedToTrack; // allowed to track all students in course

$courseId = api_get_course_int_id();

/*	MAIN SECTION */
?>
<h3>
    <?php echo $nameTools ?>
</h3>
<h4>
    <?php echo get_lang('StatsOfUser'); ?>
</h4>
<table width="100%" cellpadding="2" cellspacing="3" border="0">
<?php
// check if uid is tutor of this group
if( ( $is_allowedToTrack || $is_allowedToTrackEverybodyInCourse )) {
    if(!$uInfo && !isset($uInfo) ) {
        /*
        *		Display list of user of this group
         */

        echo "<h4>".get_lang('ListStudents')."</h4>";
        if( $is_allowedToTrackEverybodyInCourse ) {
            // if user can track everybody : list user of course

            $sql = "SELECT count(user_id)
                    FROM $TABLECOURSUSER
                    WHERE course_code = '".Database::escape_string($_cid)."' AND relation_type<>".COURSE_RELATION_TYPE_RRHH."";

        } else {
            // if user can only track one group : list users of this group
            $sql = "SELECT count(user)
                    FROM $TABLECOURSE_GROUPSUSER
                    WHERE group_id = '".Database::escape_string($_gid)."'";
        }
        $userGroupNb = getOneResult($sql);
        $step = 25; // number of student per page
        if ($userGroupNb > $step) {
            if(!isset($offset)) {
                    $offset=0;
            }

            $next     = $offset + $step;
            $previous = $offset - $step;

            $navLink = "<table width='100%' border='0'>\n"
                    ."<tr>\n"
                            ."<td align='left'>";

            if ($previous >= 0) {
                    $navLink .= "<a href='".api_get_self()."?offset=$previous'>&lt;&lt; ".get_lang('PreviousPage')."</a>";
            }

            $navLink .= "</td>\n"
                    ."<td align='right'>";

            if ($next < $userGroupNb) {
                    $navLink .= "<a href='".api_get_self()."?offset=$next'>".get_lang('NextPage')." &gt;&gt;</a>";
            }

            $navLink .= "</td>\n"
                    ."</tr>\n"
                    ."</table>\n";
        } else {
            $offset = 0;
        }
        echo $navLink;

    if (!settype($offset, 'integer') || !settype($step, 'integer')) die('Offset or step variables are not integers.');	//sanity check of integer vars
        if( $is_allowedToTrackEverybodyInCourse ) {
            // list of users in this course
            $sql = "SELECT u.user_id, u.firstname,u.lastname
                        FROM $TABLECOURSUSER cu , $TABLEUSER u
                        WHERE cu.user_id = u.user_id AND cu.relation_type<>".COURSE_RELATION_TYPE_RRHH."
                            AND cu.course_code = '".Database::escape_string($_cid)."'
                        LIMIT $offset,$step";
        }
        else
        {
            // list of users of this group
            $sql = "SELECT u.user_id, u.firstname,u.lastname
                        FROM $TABLECOURSE_GROUPSUSER gu , $TABLEUSER u
                        WHERE gu.user_id = u.user_id
                            AND gu.group_id = '".Database::escape_string($_gid)."'
                        LIMIT $offset,$step";
        }
        $list_users = getManyResults3Col($sql);
        echo 	"<table width='100%' cellpadding='2' cellspacing='1' border='0'>\n"
                    ."<tr align='center' valign='top' bgcolor='#E6E6E6'>\n"
                    ."<td align='left'>",get_lang('UserName'),"</td>\n"
                    ."</tr>\n";
        for($i = 0 ; $i < sizeof($list_users) ; $i++) {
            echo    "<tr valign='top' align='center'>\n"
                    ."<td align='left'>"
                    ."<a href='".api_get_self()."?uInfo=",$list_users[$i][0],"'>"
                    .$list_users[$i][1]," ",$list_users[$i][2]
                    ."</a>".
                    "</td>\n";
        }
        echo        "</table>\n";

        echo $navLink;
    } else {
        // if uInfo is set

        /*
        *		Informations about student uInfo
         */
        // these checks exists for security reasons, neither a prof nor a tutor can see statistics of a user from
        // another course, or group
        if( $is_allowedToTrackEverybodyInCourse ) {
            // check if user is in this course
            $tracking_is_accepted = $is_course_member;
            $tracked_user_info = api_get_user_info($uInfo);
        } else {

            // check if user is in the group of this tutor
            $sql = "SELECT u.firstname,u.lastname, u.email
                        FROM $TABLECOURSE_GROUPSUSER gu , $TABLEUSER u
                        WHERE gu.user_id = u.user_id
                            AND gu.group_id = '".Database::escape_string($_gid)."'
                            AND u.user_id = '".Database::escape_string($uInfo)."'";
            $query = Database::query($sql);
            $tracked_user_info = @Database::fetch_assoc($query);
            if(is_array($tracked_user_info)) $tracking_is_accepted = true;
        }

        if ($tracking_is_accepted) {
            $tracked_user_info['email'] == '' ? $mail_link = get_lang('NoEmail') : $mail_link = Display::encrypted_mailto_link($tracked_user_info['email']);

            echo "<tr><td>";
            echo get_lang('informationsAbout').' :';
            echo "<ul>\n"
                    ."<li>".get_lang('FirstName')." : ".$tracked_user_info['firstname']."</li>\n"
                    ."<li>".get_lang('LastName')." : ".$tracked_user_info['lastname']."</li>\n"
                    ."<li>".get_lang('Email')." : ".$mail_link."</li>\n"
                    ."</ul>";
            echo "</td></tr>\n";

            // show all : number of 1 is equal to or bigger than number of categories
            // show none : number of 0 is equal to or bigger than number of categories
            echo "<tr>
                    <td>
                    [<a href='".api_get_self()."?uInfo=".Security::remove_XSS($uInfo)."&view=1111111'>".get_lang('ShowAll')."</a>]
                    [<a href='".api_get_self()."?uInfo=".Security::remove_XSS($uInfo)."&view=0000000'>".get_lang('ShowNone')."</a>]".
                    //"||[<a href='".api_get_self()."'>".get_lang('BackToList')."</a>]".
                    "</td>
                </tr>
            ";
            if(!isset($view))
            {
                $view ='0000000';
            }
            //Logins
            TrackingUserLog::display_login_tracking_info($view, $uInfo, $courseId);

            //Exercise results
            TrackingUserLog::display_exercise_tracking_info($view, $uInfo, $courseId);

            //Student publications uploaded
            TrackingUserLog::display_student_publications_tracking_info($view, $uInfo, $courseId);

            //Links usage
            TrackingUserLog::display_links_tracking_info($view, $uInfo, $courseId);

            //Documents downloaded
            TrackingUserLog::display_document_tracking_info($view, $uInfo, $courseId);
        } else {
            echo get_lang('ErrorUserNotInGroup');
        }


        /*
         *		Scorm contents and Learning Path
         */
        if(substr($view,5,1) == '1') {
            $new_view = substr_replace($view,'0',5,1);
            echo "<tr>
                        <td valign='top'>
                        <font     color='#0000FF'>-&nbsp;&nbsp;&nbsp;</font><b>".get_lang('ScormAccess')."</b>&nbsp;&nbsp;&nbsp;[<a href='".api_get_self()."?view=".Security::remove_XSS($new_view)."&uInfo=".Security::remove_XSS($uInfo)."'>".get_lang('Close')."</a>]&nbsp;&nbsp;&nbsp;[<a href='userLogCSV.php?".api_get_cidreq()."&uInfo=".Security::remove_XSS($_GET['uInfo'])."&view=000001'>".get_lang('ExportAsCSV')."</a>]
                        </td>
                </tr>";

            $sql = "SELECT id, name FROM $tbl_learnpath_main";
            $result=Database::query($sql);
            $ar=Database::fetch_array($result);

            echo "<tr><td style='padding-left : 40px;padding-right : 40px;'>";
            echo "<table cellpadding='2' cellspacing='1' border='0' align='center'><tr>
                                    <td class='secLine'>
                                    &nbsp;".get_lang('ScormContentColumn')."&nbsp;
                                    </td>
            </tr>";
            if (is_array($ar)) {
                while ($ar['id'] != '') {
                    $lp_title = stripslashes($ar['name']);
                    echo "<tr><td>";
                    echo "<a href='".api_get_self()."?view=".$view."&scormcontopen=".$ar['id']."&uInfo=".Security::remove_XSS($uInfo)."' class='specialLink'>$lp_title</a>";
                    echo "</td></tr>";
                    if ($ar['id']==$scormcontopen) { //have to list the students here
                            $contentId=$ar['id'];
                            $sql3 = "SELECT iv.status, iv.score, i.title, iv.total_time " .
                                    "FROM $tbl_learnpath_item i " .
                                    "INNER JOIN $tbl_learnpath_item_view iv ON i.id=iv.lp_item_id " .
                                    "INNER JOIN $tbl_learnpath_view v ON iv.lp_view_id=v.id " .
                                    "WHERE (v.user_id=".Database::escape_string($uInfo)." and v.lp_id=$contentId) ORDER BY v.id, i.id";
                               $result3=Database::query($sql3);
                               $ar3=Database::fetch_array($result3);
                            if (is_array($ar3)) {
                                echo "<tr><td>&nbsp;&nbsp;&nbsp;</td>
                                       <td class='secLine'>
                                       &nbsp;".get_lang('ScormTitleColumn')."&nbsp;
                                       </td>
                                       <td class='secLine'>
                                       &nbsp;".get_lang('ScormStatusColumn')."&nbsp;
                                       </td>
                                       <td class='secLine'>
                                       &nbsp;".get_lang('ScormScoreColumn')."&nbsp;
                                       </td>
                                       <td class='secLine'>
                                       &nbsp;".get_lang('ScormTimeColumn')."&nbsp;
                                       </td>
                                       </tr>";
                                   while ($ar3['status'] != '') {
                                    require_once '../newscorm/learnpathItem.class.php';
                                    $time = learnpathItem::get_scorm_time('php',$ar3['total_time']);
                                       echo "<tr><td>&nbsp;&nbsp;&nbsp;</td><td>";
                                       echo "$title</td><td align=right>{$ar3['status']}</td><td     align=right>{$ar3['score']}</td><td align=right>$time</td>";
                                       echo "</tr>";
                                       $ar3=Database::fetch_array($result3);
                                   }
                            } else {
                                echo "<tr>";
                                echo "<td colspan='3'><center>".get_lang('ScormNeverOpened')."</center></td>";
                                echo"</tr>";
                            }
                       }
                    $ar=Database::fetch_array($result);
                }
            } else {
                $noscorm=true;
            }

            if ($noscorm) {
                echo "<tr>";
                echo "<td colspan='3'><center>".get_lang('NoResult')."</center></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</td></tr>";
        } else {
            $new_view = substr_replace($view,'1',5,1);
            echo "
                <tr>
                        <td valign='top'>
                        +<font color='#0000FF'>&nbsp;&nbsp;</font><a href='".api_get_self()."?view=".Security::remove_XSS($new_view)."&uInfo=".Security::remove_XSS($uInfo)."' class='specialLink'>".get_lang('ScormAccess')."</a>
                        </td>
                </tr>
            ";
        }

    }
} else {
    // not allowed
        api_not_allowed();
}
?>
</table>
<?php
Display::display_footer();
