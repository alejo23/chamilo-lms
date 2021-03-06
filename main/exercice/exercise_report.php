<?php
/* For licensing terms, see /license.txt */
/**
 * Exercise list: This script shows the list of exercises for administrators and students.
 * @package chamilo.exercise
 * @author Julio Montoya <gugli100@gmail.com> jqgrid integration
 *   Modified by hubert.borderiou (question category)
 *
 * @todo fix excel export
 *
 */
/**
 * Code
 */
// name of the language file that needs to be included
$language_file = array('exercice');

// including the global library
require_once '../inc/global.inc.php';
require_once api_get_path(SYS_CODE_PATH).'gradebook/lib/be.inc.php';
$urlMainExercise = api_get_path(WEB_CODE_PATH).'exercice/';

// Setting the tabs
$this_section = SECTION_COURSES;

$htmlHeadXtra[] = api_get_jqgrid_js();

// Access control
api_protect_course_script(true, false, true);

// including additional libraries
require_once 'exercise.class.php';
require_once 'question.class.php';
require_once 'answer.class.php';
require_once 'hotpotatoes.lib.php';

// need functions of statsutils lib to display previous exercices scores
require_once api_get_path(LIBRARY_PATH).'statsUtils.lib.inc.php';

// document path
$documentPath = api_get_path(SYS_COURSE_PATH).$_course['path']."/document";

/*	Constants and variables */
$is_allowedToEdit = api_is_allowed_to_edit(null, true) || api_is_drh();
$is_tutor = api_is_allowed_to_edit(true);

$TBL_QUESTIONS = Database :: get_course_table(TABLE_QUIZ_QUESTION);
$TBL_TRACK_EXERCICES = Database :: get_main_table(TABLE_STATISTIC_TRACK_E_EXERCICES);
$TBL_TRACK_ATTEMPT = Database :: get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
$TBL_TRACK_ATTEMPT_RECORDING = Database :: get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT_RECORDING);
$TBL_LP_ITEM_VIEW = Database :: get_course_table(TABLE_LP_ITEM_VIEW);

$course_id = api_get_course_int_id();
$exercise_id = isset($_REQUEST['exerciseId']) ? intval($_REQUEST['exerciseId']) : null;
$filter_user = isset($_REQUEST['filter_by_user']) ? intval($_REQUEST['filter_by_user']) : null;
$gradebook = isset($_REQUEST['gradebook']) ? Security::remove_XSS($_REQUEST['gradebook']) : null;

$locked = api_resource_is_locked_by_gradebook($exercise_id, LINK_EXERCISE);

if (empty($exercise_id)) {
    api_not_allowed(true);
}

if (!$is_allowedToEdit) {
    api_not_allowed(true);
}

// @todo check if the $parameters is used
if (!empty($exercise_id)) {
    $parameters['exerciseId'] = $exercise_id;
}
if (!empty($_GET['path'])) {
    $parameters['path'] = Security::remove_XSS($_GET['path']);
}

if (!empty($_REQUEST['export_report']) && $_REQUEST['export_report'] == '1') {
    if (api_is_platform_admin() || api_is_course_admin() || api_is_course_tutor() || api_is_course_coach()) {

        $load_extra_data = false;
        if (isset($_REQUEST['extra_data']) && $_REQUEST['extra_data'] == 1) {
            $load_extra_data = true;
        }
        require_once 'exercise_result.class.php';
        switch ($_GET['export_format']) {
            case 'xls':
                $export = new ExerciseResult();
                $export->exportCompleteReportXLS(
                    $documentPath,
                    null,
                    $load_extra_data,
                    null,
                    $_GET['exerciseId'],
                    $_GET['hotpotato_name']
                );
                exit;
                break;
            case 'csv':
            default:
                $export = new ExerciseResult();
                $export->exportCompleteReportCSV(
                    $documentPath,
                    null,
                    $load_extra_data,
                    null,
                    $_GET['exerciseId'],
                    $_GET['hotpotato_name']
                );
                exit;
                break;
        }
    } else {
        api_not_allowed(true);
    }
}


$actions = null;
if (isset($origin) && $origin == 'learnpath') {
    $actions .= '<a href="exercice.php">'.Display :: return_icon(
        'back.png',
        get_lang('GoBackToQuestionList'),
        '',
        ICON_SIZE_MEDIUM
    ).'</a>';
} else {
    if ($is_allowedToEdit) {
        // the form
        if (api_is_platform_admin() || api_is_course_admin() || api_is_course_tutor() || api_is_course_coach()) {
            // @todo check if $path is used
            $path = isset($_GET['path']) ? Security::remove_XSS($_GET['path']) : null;

            $actions .= '<a href="admin.php?exerciseId='.intval($_GET['exerciseId']).'">'.Display :: return_icon('back.png',get_lang('GoBackToQuestionList'),'',ICON_SIZE_MEDIUM).'</a>';
            $actions .= '<a href="live_stats.php?'.api_get_cidreq().'&exerciseId='.$exercise_id.'">'.Display :: return_icon('activity_monitor.png',get_lang('LiveResults'), '', ICON_SIZE_MEDIUM ).'</a>';
            $actions .= '<a href="stats.php?'.api_get_cidreq().'&exerciseId='.$exercise_id.'">'.Display :: return_icon('statistics.png',get_lang('ReportByQuestion'), '', ICON_SIZE_MEDIUM).'</a>';
            $actions .= '<a id="export_opener" href="'.api_get_self().'?export_report=1&hotpotato_name='.$path.'&exerciseId='.intval($_GET['exerciseId']).'" >'.
                Display::return_icon('save.png', get_lang('Export'), '', ICON_SIZE_MEDIUM).'</a>';
            $actions .= '<a href="recalculate_scores.php?'.api_get_cidreq().'&exerciseId='.$exercise_id.'">'.Display :: return_icon('history.png',get_lang('RecalculateResults'), '', ICON_SIZE_MEDIUM).'</a>';
        }
    }
}

//Deleting an attempt
if (($is_allowedToEdit || $is_tutor || api_is_coach()) && isset($_GET['delete']) && $_GET['delete'] == 'delete' && !empty ($_GET['did']) && $locked == false
) {
    $exe_id = intval($_GET['did']);
    if (!empty($exe_id)) {
        $sql = 'DELETE FROM '.$TBL_TRACK_EXERCICES.' WHERE exe_id = '.$exe_id;
        Database::query($sql);
        $sql = 'DELETE FROM '.$TBL_TRACK_ATTEMPT.' WHERE exe_id = '.$exe_id;
        Database::query($sql);
        header(
            'Location: exercise_report.php?cidReq='.Security::remove_XSS($_GET['cidReq']).'&exerciseId='.$exercise_id
        );
        exit;
    }
}

if ($is_allowedToEdit || $is_tutor) {
    $nameTools = get_lang('StudentScore');
    $interbreadcrumb[] = array("url" => "exercice.php?gradebook=$gradebook", "name" => get_lang('Exercices'));
    $objExerciseTmp = new Exercise();
    if ($objExerciseTmp->read($exercise_id)) {
        $interbreadcrumb[] = array("url" => "admin.php?exerciseId=".$exercise_id, "name" => $objExerciseTmp->name);
    }
} else {
    $interbreadcrumb[] = array("url" => "exercice.php?gradebook=$gradebook", "name" => get_lang('Exercices'));
    $objExerciseTmp = new Exercise();
    if ($objExerciseTmp->read($exercise_id)) {
        $nameTools = get_lang('Results').': '.$objExerciseTmp->name;
    }
}

Display :: display_header($nameTools);

$actions = Display::div($actions, array('class' => 'actions'));

$extra = '<script>
    $(document).ready(function() {

        $( "#dialog:ui-dialog" ).dialog( "destroy" );

        $( "#dialog-confirm" ).dialog({
                autoOpen: false,
                show: "blind",
                resizable: false,
                height:300,
                modal: true
         });

        $("#export_opener").click(function() {
            var targetUrl = $(this).attr("href");
            $( "#dialog-confirm" ).dialog({
                width:400,
                height:300,
                buttons: {
                    "'.addslashes(get_lang('Download')).'": function() {
                        var export_format = $("input[name=export_format]:checked").val();
                        var extra_data  = $("input[name=load_extra_data]:checked").val();
                        location.href = targetUrl+"&export_format="+export_format+"&extra_data="+extra_data;
                        $( this ).dialog( "close" );
                    },
                }
            });
            $( "#dialog-confirm" ).dialog("open");
            return false;
        });
    });
    </script>';

$extra .= '<div id="dialog-confirm" title="'.get_lang("ConfirmYourChoice").'">';
$form = new FormValidator('report', 'post', null, null, array('class' => 'form-vertical'));
$form->addElement(
    'radio',
    'export_format',
    null,
    get_lang('ExportAsCSV'),
    'csv',
    array('id' => 'export_format_csv_label')
);
$form->addElement(
    'radio',
    'export_format',
    null,
    get_lang('ExportAsXLS'),
    'xls',
    array('id' => 'export_format_xls_label')
);
$form->addElement(
    'checkbox',
    'load_extra_data',
    null,
    get_lang('LoadExtraData'),
    '0',
    array('id' => 'export_format_xls_label')
);
$form->setDefaults(array('export_format' => 'csv'));
$extra .= $form->return_form();
$extra .= '</div>';

if ($is_allowedToEdit) {
    echo $extra;
}

echo $actions;

$url = api_get_path(WEB_AJAX_PATH).'model.ajax.php?a=get_exercise_results&exerciseId='.$exercise_id.'&filter_by_user='.$filter_user;

$action_links = '';

//Generating group list

$group_list = GroupManager::get_group_list();
$group_parameters = array('group_all:'.get_lang('All'), 'group_none:'.get_lang('None'));

foreach ($group_list as $group) {
    $group_parameters[] = $group['id'].':'.$group['name'];
}
if (!empty($group_parameters)) {
    $group_parameters = implode(';', $group_parameters);
}

if ($is_allowedToEdit || $is_tutor) {

    //The order is important you need to check the the $column variable in the model.ajax.php file
    $columns = array(
        get_lang('FirstName'),
        get_lang('LastName'),
        get_lang('LoginName'),
        get_lang('Group'),
        get_lang('Duration').' ('.get_lang('MinMinute').')',
        get_lang('StartDate'),
        get_lang('EndDate'),
        get_lang('Score'),
        get_lang('Status'),
        get_lang('ToolLearnpath'),
        get_lang('Actions')
    );

//Column config
    $column_model = array(
        array('name' => 'firstname', 'index' => 'firstname', 'width' => '50', 'align' => 'left', 'search' => 'true'),
        array(
            'name' => 'lastname',
            'index' => 'lastname',
            'width' => '50',
            'align' => 'left',
            'formatter' => 'action_formatter',
            'search' => 'true'
        ),
        array(
            'name' => 'login',
            'index' => 'username',
            'width' => '40',
            'align' => 'left',
            'search' => 'true',
            'hidden' => 'true'
        ),
        array(
            'name' => 'group_name',
            'index' => 'group_id',
            'width' => '40',
            'align' => 'left',
            'search' => 'true',
            'stype' => 'select',
            //for the bottom bar
            'searchoptions' => array(
                'defaultValue' => 'group_all',
                'value' => $group_parameters
            ),
            //for the top bar
            'editoptions' => array('value' => $group_parameters)
        ),
        array('name' => 'duration', 'index' => 'exe_duration', 'width' => '30', 'align' => 'left', 'search' => 'true'),
        array('name' => 'start_date', 'index' => 'start_date', 'width' => '60', 'align' => 'left', 'search' => 'true'),
        array('name' => 'exe_date', 'index' => 'exe_date', 'width' => '60', 'align' => 'left', 'search' => 'true'),
        array('name' => 'score', 'index' => 'exe_result', 'width' => '50', 'align' => 'left', 'search' => 'true'),
        array(
            'name' => 'status',
            'index' => 'revised',
            'width' => '40',
            'align' => 'left',
            'search' => 'true',
            'stype' => 'select',
            //for the bottom bar
            'searchoptions' => array(
                'defaultValue' => '',
                'value' => ':'.get_lang('All').';1:'.get_lang('Validated').';0:'.get_lang('NotValidated')
            ),
            //for the top bar
            'editoptions' => array(
                'value' => ':'.get_lang('All').';1:'.get_lang('Validated').';0:'.get_lang(
                    'NotValidated'
                )
            )
        ),
        array('name' => 'lp', 'index' => 'lp', 'width' => '60', 'align' => 'left', 'search' => 'false'),
        array('name' => 'actions', 'index' => 'actions', 'width' => '60', 'align' => 'left', 'search' => 'false')
    );

    $action_links = '
    // add username as title in lastname filed - ref 4226
    function action_formatter(cellvalue, options, rowObject) {
        // rowObject is firstname,lastname,login,... get the third word
        var loginx = "'.api_htmlentities(sprintf(get_lang("LoginX"), ":::"), ENT_QUOTES).'";
        var tabLoginx = loginx.split(/:::/);
        // tabLoginx[0] is before and tabLoginx[1] is after :::
        // may be empty string but is defined
        return "<span title=\""+tabLoginx[0]+rowObject[2]+tabLoginx[1]+"\">"+cellvalue+"</span>";
    }';
}

//Autowidth
$extra_params['autowidth'] = 'true';

//height auto
$extra_params['height'] = 'auto';

?>
<script>

    function setSearchSelect(columnName) {
        $("#results").jqGrid('setColProp', columnName, {
            searchoptions:{
                dataInit:function (el) {
                    $("option[value='1']", el).attr("selected", "selected");
                    setTimeout(function () {
                        $(el).trigger('change');
                    }, 1000);
                }
            }
        });
    }

    function exportExcel() {
        var mya = new Array();
        mya = $("#results").getDataIDs();  // Get All IDs
        var data = $("#results").getRowData(mya[0]);     // Get First row to get the labels
        var colNames = new Array();
        var ii = 0;
        for (var i in data) {
            colNames[ii++] = i;
        }    // capture col names
        var html = "";

        for (i = 0; i < mya.length; i++) {
            data = $("#results").getRowData(mya[i]); // get each row
            for (j = 0; j < colNames.length; j++) {
                html = html + data[colNames[j]] + ","; // output each column as tab delimited
            }
            html = html + "\n";  // output each row with end of line
        }
        html = html + "\n";  // end of line at the end

        var form = $("#export_report_form");

        $("#csvBuffer").attr('value', html);
        form.target = '_blank';
        form.submit();
    }

    $(function () {
    <?php
    echo Display::grid_js('results', $url, $columns, $column_model, $extra_params, array(), $action_links, true);

    if ($is_allowedToEdit || $is_tutor) {
        ?>
        //setSearchSelect("status");
        //
        //view:true, del:false, add:false, edit:false, excel:true}
        $("#results").jqGrid('navGrid', '#results_pager', {view:true, edit:false, add:false, del:false, excel:false},
                {height:280, reloadAfterSubmit:false}, // view options
                {height:280, reloadAfterSubmit:false}, // edit options
                {height:280, reloadAfterSubmit:false}, // add options
                {reloadAfterSubmit:false}, // del options
                {width:500} // search options
        );
        /*
// add custom button to export the data to excel
jQuery("#results").jqGrid('navButtonAdd','#results_pager',{
  caption:"",
  onClickButton : function () {
       //exportExcel();
  }
});*/

        /*
        jQuery('#sessions').jqGrid('navButtonAdd','#sessions_pager',{id:'pager_csv',caption:'',title:'Export To CSV',onClickButton : function(e)
        {
            try {
                jQuery("#sessions").jqGrid('excelExport',{tag:'csv', url:'grid.php'});
            } catch (e) {
                window.location= 'grid.php?oper=csv';
            }
        },buttonicon:'ui-icon-document'})
        */

        //Adding search options
        var options = {
            'stringResult': true,
            'autosearch' : true,
            'searchOnEnter':false
        }
        jQuery("#results").jqGrid('filterToolbar', options);
        var sgrid = $("#results")[0];
        sgrid.triggerToolbar();

        <?php } ?>
    });
</script>
<form id="export_report_form" method="post" action="exercise_report.php">
    <input type="hidden" name="csvBuffer" id="csvBuffer" value=""/>
    <input type="hidden" name="export_report" id="export_report" value="1"/>
    <input type="hidden" name="exerciseId" id="exerciseId" value="<?php echo $exercise_id ?>"/>
</form>
<?php

echo Display::grid_html('results');
Display :: display_footer();
