<?php
/**
 * Prospress Posts
 *
 * Adds a marketplace posting system along side WordPress.
 *
 * @package Prospress
 * @subpackage Posts
 * @author Brent Shepherd
 * @version 0.1
 */

/**
 * @TODO have these constant declarations occur in the pp-core.php
 */
if ( !defined( 'PP_PLUGIN_DIR' ) )
	define( 'PP_PLUGIN_DIR', WP_PLUGIN_DIR . '/prospress' );
if ( !defined( 'PP_PLUGIN_URL' ) )
	define( 'PP_PLUGIN_URL', WP_PLUGIN_URL . '/prospress' );

if ( !defined( 'PP_POSTS_DIR' ) )
	define( 'PP_POSTS_DIR', PP_PLUGIN_DIR . '/pp-posts' );
if ( !defined( 'PP_POSTS_URL' ) )
	define( 'PP_POSTS_URL', PP_PLUGIN_URL . '/pp-posts' );

/**
 * Include the Prospress Custom Post Type
 */
include( PP_POSTS_DIR . '/pp-custom-post-type.php');

/**
 * Include Prospress Sort widget
 */
include( PP_POSTS_DIR . '/pp-sort.php');

/**
 * Include Prospress Template Tags
 */
include( PP_POSTS_DIR . '/pp-posts-templatetags.php');

/**
 * Include Prospress Custom Taxonomy Component
 */
include( PP_POSTS_DIR . '/pp-custom-taxonomy.php');

global $market_system;


/**
 * Sets up Prospress environment with any settings required and/or shared across the 
 * other components. 
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 *
 * @uses is_site_admin() returns true if the current user is a site admin, false if not.
 * @uses add_submenu_page() WP function to add a submenu item.
 * @uses get_role() WP function to get the administrator role object and add capabilities to it.
 *
 * @global wpdb $wpdb WordPress DB access object.
 * @global PP_Market_System $market_system Prospress market system object for this marketplace.
 * @global WP_Rewrite $wp_rewrite WordPress Rewrite Component.
 */
function pp_posts_install(){
	global $wpdb, $market_system, $wp_rewrite;

	$wp_rewrite->flush_rules(false);

	error_log('*** in pp_posts_install ***');

	// Need an index page for Prospress posts
	if( !$wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_name = '" . $market_system->name() . "'" ) ){
		$index_page = array();
		$index_page['post_title'] = $market_system->display_name();
		$index_page['post_name'] = $market_system->name();
		$index_page['post_status'] = 'publish';
		$index_page['post_content'] = __( 'This is the index for your ' . $market_system->display_name() . '.' );
		$index_page['post_type'] = 'page';

		wp_insert_post( $index_page );
	}

	pp_add_sidebars_widgets();
	
 	$role = get_role( 'administrator' );

	$role->add_cap( 'publish_prospress_posts' );
	$role->add_cap( 'edit_prospress_post' );
	$role->add_cap( 'edit_prospress_posts' );
	$role->add_cap( 'edit_others_prospress_posts' );
	$role->add_cap( 'read_prospress_posts' );
	$role->add_cap( 'delete_prospress_post' );	
}
add_action( 'pp_activation', 'pp_posts_install' );


/** 
 * When a Prospress post is saved, this function saves the custom information specific to Prospress posts,
 * like end time. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 * 
 * @global wpdb $wpdb WordPress DB access object.
 */
function pp_post_save_postdata( $post_id, $post ) {
	global $wpdb;

	if( wp_is_post_revision( $post_id ) )
		$post_id = wp_is_post_revision( $post_id );

	if ( empty( $_POST ) || 'page' == $_POST['post_type'] ) {
		return $post_id;
	} else if ( !current_user_can( 'edit_post', $post_id )) {
		return $post_id;
	} else if ( !isset( $_POST['yye'] ) ){ // Make sure an end date is submitted (not submitted with quick edits etc.)
		return $post_id;
	}

	$yye = $_POST['yye'];
	$mme = $_POST['mme'];
	$dde = $_POST['dde'];
	$hhe = $_POST['hhe'];
	$mne = $_POST['mne'];
	$sse = $_POST['sse'];	
	$yye = ($yye <= 0 ) ? date('Y') : $yye;
	$mme = ($mme <= 0 ) ? date('n') : $mme;
	$dde = ($dde > 31 ) ? 31 : $dde;
	$dde = ($dde <= 0 ) ? date('j') : $dde;
	$hhe = ($hhe > 23 ) ? $hhe -24 : $hhe;
	$mne = ($mne > 59 ) ? $mne -60 : $mne;
	$sse = ($sse > 59 ) ? $sse -60 : $sse;
	$post_end_date = sprintf( "%04d-%02d-%02d %02d:%02d:%02d", $yye, $mme, $dde, $hhe, $mne, $sse );

	$now_gmt = current_time( 'mysql', true ); // get current GMT
	$post_end_date_gmt = get_gmt_from_date( $post_end_date );
	$original_post_end_date_gmt = get_post_end_time( $post_id, 'mysql' );

	if( !$original_post_end_date_gmt || $post_end_date_gmt != $original_post_end_date_gmt ){
		update_post_meta( $post_id, 'post_end_date', $post_end_date );
		update_post_meta( $post_id, 'post_end_date_gmt', $post_end_date_gmt);		
	}

	if( $post_end_date_gmt <= $now_gmt && $_POST['save'] != 'Save Draft'){
		wp_unschedule_event( strtotime( $original_post_end_date_gmt ), 'schedule_end_post', array( 'ID' => $post_id ) );
		pp_end_post( $post_id );
	} else {
		wp_unschedule_event( strtotime( $original_post_end_date_gmt ), 'schedule_end_post', array( 'ID' => $post_id ) );

		if($post_status != 'draft'){
			pp_schedule_end_post( $post_id, strtotime( $post_end_date_gmt ) );
			do_action( 'publish_end_date_change', $post_status, $post_end_date );
		}
	}
}
add_action( 'save_post', 'pp_post_save_postdata', 10, 2 );


/**
 * Schedules a post to end at a given post end time. 
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 *
 * @uses wp_schedule_single_event function
 * @param post_id for identifing the post
 * @param post_end_time_gmt a unix time stamp of the gmt date/time the post should end
 */
function pp_schedule_end_post( $post_id, $post_end_time_gmt ) {
	wp_schedule_single_event( $post_end_time_gmt, 'schedule_end_post', array( 'ID' => $post_id ) );
}


/**
 * Changes the status of a given post to 'completed'. This function is added to the
 * schedule_end_post hook.
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 *
 * @uses wp_unschedule_event function
 */
function pp_end_post( $post_id ) {
	global $wpdb;

	if( wp_is_post_revision( $post_id ) )
		$post_id = wp_is_post_revision( $post_id );

	$post_status = apply_filters( 'post_end_status', 'completed' );

	$wpdb->update( $wpdb->posts, array( 'post_status' => $post_status ), array( 'ID' => $post_id ) );
	do_action( 'post_completed' );
}
add_action('schedule_end_post', 'pp_end_post');


/**
 * Unschedules the completion of a post in WP Cron.
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 *
 * @uses wp_unschedule_event function
 */
function pp_unschedule_post_end( $post_id ) {
	$next = wp_next_scheduled( 'schedule_end_post', array('ID' => $post_id) );
	wp_unschedule_event( $next, 'schedule_end_post', array('ID' => $post_id) );
}
add_action( 'deleted_post', 'pp_unschedule_post_end' );


/**
 * What happens to Prospress posts when they completed? They need to be marked with a special status. 
 * This function registers the "Completed" to designate to posts upon their completion. 
 * 
 * For now, a post earns this status with the passing of a given period of time. However, eventually a 
 * post may be completed due to a number of other circumstances. 
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 *
 * @uses pp_register_completed_status function
 */
function pp_register_completed_status() {
	register_post_status(
	       'completed',
	       array('label' => _x('Completed Posts', 'post'),
				'label_count' => _n_noop('Completed <span class="count">(%s)</span>', 'Completed <span class="count">(%s)</span>'),
				'show_in_admin_all' => false,
				'show_in_admin_all_list' => false,
				'show_in_admin_status_list' => true,
				'public' => false,
				//'private' => true,
				'publicly_queryable' => false,
				'exclude_from_search' => true,
	       )
	);
}
add_action('init', 'pp_register_completed_status');


/**
 * Display custom Prospress post end date/time form fields.
 *
 * This code is sourced from the edit-form-advanced.php file. Additional code is added for 
 * dealing with 'completed' post status. 
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_post_submit_meta_box() {
	global $action, $wpdb, $post, $market_system;

	if( !is_pp_post_admin_page() )
		return;

	$datef = __( 'M j, Y @ G:i' );

	//Set up post end date label
	if ( 'completed' == $post->post_status ) // already finished
		$end_stamp = __('Ended: <b>%1$s</b>', 'prospress' );
	else
		$end_stamp = __('End on: <b>%1$s</b>', 'prospress' );

	//Set up post end date and time variables
	if ( 0 != $post->ID ) {
		$post_end = get_post_end_time( $post->ID, 'mysql', false );

		if ( !empty( $post_end ) && '0000-00-00 00:00:00' != $post_end )
			$end_date = date_i18n( $datef, strtotime( $post_end ) );
	}

	// Default to one week if post end date is not set
	if ( !isset( $end_date ) ) {
		$end_date = date_i18n( $datef, strtotime( gmdate( 'Y-m-d H:i:s', ( time() + 604800 + ( get_option( 'gmt_offset' ) * 3600 ) ) ) ) );
	}
	?>
	<div class="misc-pub-section curtime misc-pub-section-last">
		<span id="endtimestamp">
		<?php printf($end_stamp, $end_date); ?></span>
		<a href="#edit_endtimestamp" class="edit-endtimestamp hide-if-no-js" tabindex='4'><?php ('completed' != $post->post_status) ? _e('Edit', 'prospress' ) : _e('Extend', 'prospress' ); ?></a>
		<div id="endtimestampdiv" class="hide-if-js">
			<?php touch_end_time(($action == 'edit'),5); ?>
		</div>
	</div><?php
}
add_action('post_submitbox_misc_actions', 'pp_post_submit_meta_box');


/**
 * Copy of the WordPress "touch_time" template function for use with end time, instead of start time
 *
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 *
 * @param unknown_type $edit
 * @param unknown_type $tab_index
 * @param unknown_type $multi
 */
function touch_end_time( $edit = 1, $tab_index = 0, $multi = 0 ) {
	global $wp_locale, $post, $comment;

	//error_log('post = ' . print_r($post,true));
	$post_end_date_gmt = get_post_end_time( $post->ID, 'mysql' );

	$edit = ( in_array($post->post_status, array('draft', 'pending') ) && (!$post_end_date_gmt || '0000-00-00 00:00:00' == $post_end_date_gmt ) ) ? false : true;

	$tab_index_attribute = '';
	if ( (int) $tab_index > 0 )
		$tab_index_attribute = " tabindex=\"$tab_index\"";

	$time_adj = time() + ( get_option( 'gmt_offset' ) * 3600 );
	$time_adj_end = time() + 604800 + ( get_option( 'gmt_offset' ) * 3600 );

	$post_end_date = get_post_end_time( $post->ID, 'mysql', false );
	if(empty($post_end_date))
		$post_end_date = gmdate( 'Y-m-d H:i:s', ( time() + 604800 + ( get_option( 'gmt_offset' ) * 3600 ) ) );

	$dde = ($edit) ? mysql2date( 'd', $post_end_date, false ) : gmdate( 'd', $time_adj_end );
	$mme = ($edit) ? mysql2date( 'm', $post_end_date, false ) : gmdate( 'm', $time_adj_end );
	$yye = ($edit) ? mysql2date( 'Y', $post_end_date, false ) : gmdate( 'Y', $time_adj_end );
	$hhe = ($edit) ? mysql2date( 'H', $post_end_date, false ) : gmdate( 'H', $time_adj_end );
	$mne = ($edit) ? mysql2date( 'i', $post_end_date, false ) : gmdate( 'i', $time_adj_end );
	$sse = ($edit) ? mysql2date( 's', $post_end_date, false ) : gmdate( 's', $time_adj_end );

	$cur_dde = gmdate( 'd', $time_adj );
	$cur_mme = gmdate( 'm', $time_adj );
	$cur_yye = gmdate( 'Y', $time_adj );
	$cur_hhe = gmdate( 'H', $time_adj );
	$cur_mne = gmdate( 'i', $time_adj );
	$cur_sse = gmdate( 's', $time_adj );

	$month = "<select " . ( $multi ? '' : 'id="mme" ' ) . "name=\"mme\"$tab_index_attribute>\n";
	for ( $i = 1; $i < 13; $i = $i +1 ) {
		$month .= "\t\t\t" . '<option value="' . zeroise($i, 2) . '"';
		if ( $i == $mme )
			$month .= ' selected="selected"';
		$month .= '>' . $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) . "</option>\n";
	}
	$month .= '</select>';

	$day = '<input type="text" ' . ( $multi ? '' : 'id="dde" ' ) . 'name="dde" value="' . $dde . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" />';
	$year = '<input type="text" ' . ( $multi ? '' : 'id="yye" ' ) . 'name="yye" value="' . $yye . '" size="4" maxlength="4"' . $tab_index_attribute . ' autocomplete="off" />';
	$hour = '<input type="text" ' . ( $multi ? '' : 'id="hhe" ' ) . 'name="hhe" value="' . $hhe . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" />';
	$minute = '<input type="text" ' . ( $multi ? '' : 'id="mne" ' ) . 'name="mne" value="' . $mne . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" />';
	/* translators: 1: month input, 2: day input, 3: year input, 4: hour input, 5: minute input */
	printf(__('%1$s%2$s, %3$s @ %4$s : %5$s'), $month, $day, $year, $hour, $minute);

	echo '<input type="hidden" id="sse" name="sse" value="' . $sse . '" />';

	if ( $multi ) return;

	echo "\n\n";
	foreach ( array('mme', 'dde', 'yye', 'hhe', 'mne', 'sse') as $timeunit ) {
		echo '<input type="hidden" id="hidden_' . $timeunit . '" name="hidden_' . $timeunit . '" value="' . $$timeunit . '" />' . "\n";
		$cur_timeunit = 'cur_' . $timeunit;
		echo '<input type="hidden" id="'. $cur_timeunit . '" name="'. $cur_timeunit . '" value="' . $$cur_timeunit . '" />' . "\n";
	}
?>

<p>
	<a href="#edit_endtimestamp" class="save-endtimestamp hide-if-no-js button"><?php _e('OK', 'prospress' ); ?></a>
	<a href="#edit_endtimestamp" class="cancel-endtimestamp hide-if-no-js"><?php _e('Cancel', 'prospress' ); ?></a>
</p>
<?php
}


/** 
 * Enqueues scripts and styles to the head of Prospress post admin pages. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_posts_admin_head() {

	if( !is_pp_post_admin_page() )
		return;

	if( strpos( $_SERVER['REQUEST_URI'], 'post.php' ) !== false || strpos( $_SERVER['REQUEST_URI'], 'post-new.php' ) !== false ) {
		wp_enqueue_script( 'prospress-post', PP_POSTS_URL . '/pp-post.dev.js', array('jquery') );
		wp_localize_script( 'prospress-post', 'ppPostL10n', array(
			'endedOn' => __('Ended on:', 'prospress' ),
			'endOn' => __('End on:', 'prospress' ),
			'end' => __('End', 'prospress' ),
			'update' => __('Update', 'prospress' ),
			'repost' => __('Repost', 'prospress' ),
			));
		wp_enqueue_style( 'prospress-post',  PP_POSTS_URL . '/pp-post.css' );
	}

	if ( strpos( $_SERVER['REQUEST_URI'], 'completed' ) !== false ){
		wp_enqueue_script( 'inline-edit-post' );
	}

	// @TODO replace this quick and dirty hack with a server side way to remove styles on these tables.
	if ( strpos( $_SERVER['REQUEST_URI'], 'edit.php' ) !== false ||  strpos( $_SERVER['REQUEST_URI'], 'completed' ) !== false ) {
		echo '<script type="text/javascript">';
		echo 'jQuery(document).ready( function($) {';
		echo '$("#author").removeClass("column-author");';
		echo '$("#categories").removeClass("column-categories");';
		echo '$("#tags").removeClass("column-tags");';
		echo '});</script>';
	}
}
add_action( 'admin_enqueue_scripts', 'pp_posts_admin_head' );


/** 
 * Prospress posts end and a post's end date/time is important enough to be shown on the posts 
 * admin table. Completed posts also require follow up actions, so these actions should also be 
 * shown on the posts admin table, but only for completed posts. 
 *
 * This function adds the end date and completed posts actions columns to the column headings array
 * for Prospress posts admin tables. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_post_columns( $column_headings ) {
	global $market_system;

	if( !is_pp_post_admin_page() )
		return $column_headings;

	if( strpos( $_SERVER['REQUEST_URI'], 'completed' ) !== false ) {
		$column_headings[ 'end_date' ] = __( 'Ended', 'prospress' );
		$column_headings[ 'post_actions' ] = __( 'Action', 'prospress' );
		unset( $column_headings[ 'date' ] );
	} else {
		$column_headings[ 'date' ] = __( 'Date Published', 'prospress' );
		$column_headings[ 'end_date' ] = __( 'Ending', 'prospress' );
	}

	return $column_headings;
}
add_filter( 'manage_' . $market_system->name() . '_posts_columns', 'pp_post_columns' );


/** 
 * The admin tables for Prospress posts have custom columns for Prospress specific information. 
 * This function fills those columns with their appropriate information.
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_post_columns_custom( $column_name, $post_id ) {
	global $wpdb;

	// Need to manually populate $post var. Global $post contains post_status of "publish"...
	$post = $wpdb->get_row( "SELECT post_status FROM $wpdb->posts WHERE ID = $post_id" );

	if( $column_name == 'end_date' ) {
		$end_time_gmt = get_post_end_time( $post_id );

		if ( $end_time_gmt == false || empty( $end_time_gmt ) ) {
			$m_time = $human_time = __('Not set.', 'prospress' );
			$time_diff = 0;
		} else {
			$human_time = human_interval( $end_time_gmt - time(), 3 );
			$human_time .= '<br/>' . get_post_end_time( $post_id, 'mysql', false );
		}
		echo '<abbr title="' . $m_time . '">';
		echo apply_filters('post_end_date_column', $human_time, $post_id, $column_name) . '</abbr>';
	}

	if( $column_name == 'post_actions' ) {
		$actions = apply_filters( 'completed_post_actions', array(), $post_id );
		if( is_array( $actions ) && !empty( $actions ) ){?>
			<div class="prospress-actions">
				<ul class="actions-list">
					<li class="base"><?php _e( 'Take action:', 'prospress' ) ?></li>
				<?php foreach( $actions as $action => $attributes )
					echo "<li class='action'><a href='" . add_query_arg ( array( 'action' => $action, 'post' => $post_id ) , $attributes['url'] ) . "'>" . $attributes['label'] . "</a></li>";
				 ?>
				</ul>
			</div>
		<?php
		} else {
			echo '<p>' . __( 'No action can be taken.', 'prospress' ) . '</p>';
		}
	}
}
add_action( 'manage_posts_custom_column', 'pp_post_columns_custom', 10, 2 );


/** 
 * Prospress posts aren't just your vanilla WordPress post! They have special meta which needs to
 * be presented in a special way. They also need to be sorted and filtered to make them easier to
 * browse and compare. That's why this function redirects individual Prospress posts to a default
 * template for single posts - pp-single.php - and the auto-generated Prospress index page to a 
 * special index template - pp-index.php. 
 * 
 * However, before doing so, it provides a hook for overriding the templates and also checks if the 
 * current theme has Prospress compatible templates. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_template_redirects() {
	global $post, $market_system;

	error_log('$post = ' . print_r( $post, true ));
	if( $post->post_name == $market_system->name() ){
		
		do_action( 'pp_index_template_redirect' );
		
		if( file_exists( TEMPLATEPATH . '/pp-index.php' ) )
			include( TEMPLATEPATH . '/pp-index.php');
		else
			include( PP_POSTS_DIR . '/pp-index.php');
		exit;

	} elseif ( $post->post_type == $market_system->name() && !isset( $_GET[ 's' ] ) ) {
		
		do_action( 'pp_single_template_redirect' );

		if( file_exists( TEMPLATEPATH . '/pp-single.php' ) )
			include( TEMPLATEPATH . '/pp-single.php');
		else
			include( PP_POSTS_DIR . '/pp-single.php');
		exit;
	}
}
add_action( 'template_redirect', 'pp_template_redirects' );


/** 
 * Create a sidebar for the Prospress post index page. This sidebar automatically has the Sort and 
 * Filter widgets added to it on activation. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_register_sidebars(){
	global $market_system;

	register_sidebar( array (
		'name' => $market_system->display_name() . ' ' . __( 'Index Sidebar', 'prospress' ),
		'id' => $market_system->name() . '-index-sidebar',
		'description' => __( "The sidebar on your Prospress posts.", 'prospress' ),
		'before_widget' => '<li id="%1$s" class="widget-container %2$s">',
		'after_widget' => "</li>",
		'before_title' => '<h3 class="widget-title">',
		'after_title' => '</h3>',
	) );
}
add_action( 'init', 'pp_register_sidebars' );


/** 
 * Add the Sort and Filter widgets to the default Prospress sidebar. This function is called on 
 * Prospress' activation to help get everything working with one-click.
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_add_sidebars_widgets(){
	global $market_system;

	$sidebars_widgets = get_option( 'sidebars_widgets' );

	if( !isset( $sidebars_widgets[ $market_system->name() . '-index-sidebar' ] ) )
		$sidebars_widgets[ $market_system->name() . '-index-sidebar' ] = array();

	$sort_widget = get_option( 'widget_pp-sort' );
	if( empty( $sort_widget ) ){

		$sort_widget[] = array(
							'title' => __( 'Sort by:', 'prospress' ),
							'post-desc' => 'on',
							'post-asc' => 'on',
							'end-asc' => 'on',
							'end-desc' => 'on',
							'price-asc' => 'on',
							'price-desc' => 'on'
							);

		$sort_widget['_multiwidget'] = 1;
		update_option( 'widget_pp-sort',$sort_widget );
		array_push( $sidebars_widgets[ $market_system->name() . '-index-sidebar' ], 'pp-sort-0' );
	}

	$filter_widget = '';
	if( empty( $filter_widget ) ){

		$filter_widget[] = array( 'title' => __( 'Price:', 'prospress' ) );

		$filter_widget['_multiwidget'] = 1;
		update_option( 'widget_bid-filter', $filter_widget );
		array_push( $sidebars_widgets[ $market_system->name() . '-index-sidebar' ], 'bid-filter-0' );
	}

	update_option( 'sidebars_widgets', $sidebars_widgets );
}


/** 
 * Prospress includes a widget & function for sorting Prospress posts. This function adds post
 * related meta values to optionally be sorted. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 * @see pp_set_sort_options()
 */
function pp_post_sort_options( $pp_sort_options ){

	$pp_sort_options['post-desc'] = __( 'Time: Newly posted', 'prospress' );
	$pp_sort_options['post-asc'] = __( 'Time: Oldest first', 'prospress' );
	$pp_sort_options['end-asc'] = __( 'Time: Ending soonest', 'prospress' );
	$pp_sort_options['end-desc'] = __( 'Time: Ending latest', 'prospress' );

	return $pp_sort_options;
}
add_filter( 'pp_sort_options', 'pp_post_sort_options' );


/** 
 * A boolean function to centralise the check for whether the current page is a Prospress posts admin page. 
 *
 * This is required when enqueuing scripts, styles and performing other Prospress post admin page 
 * specific functions so it makes sense to centralise it. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function is_pp_post_admin_page(){
	global $market_system, $post;

	if( $_GET[ 'post_type' ] == $market_system->name() || $_GET[ 'post' ] == $market_system->name() || $post->post_type == $market_system->name() ) //get_post_type( $_GET[ 'post' ] ) ==  $market_system->name() )
		return true;
	else
		return false;
}


/** 
 * Removes the Prospress index page from the search results as it's really meant to be used as an empty place-holder. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_remove_index( $search ){
	global $wpdb, $market_system;

	if ( isset( $_GET['s'] ) ) // only remove post from search results
		$search .= "AND ID NOT IN (SELECT ID FROM $wpdb->posts WHERE post_name = '" . $market_system->name() . "')";

	return $search;
}
add_filter( 'posts_search', 'pp_remove_index' );


/** 
 * Admin's may want to allow or disallow users to create, edit and delete marketplace posts. 
 * To do this without relying on the post capability type, Prospress creates it's own type. 
 * This function provides an admin menu for selecting which roles can do what to posts. 
 * 
 * Allow site admin to choose which roles can do what to marketplace posts.
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_capabilities_settings_page() { 
	global $wp_roles, $market_system;

	$role_names = $wp_roles->get_names();
	$roles = array();

	foreach ( $role_names as $key => $value ) {
		$roles[ $key] = get_role( $key);
		$roles[ $key]->display_name = $value;
	}
	?>

	<?php wp_nonce_field( 'pp_capabilities_settings' ); ?>
	<div class="prospress-capabilities">
		<h3><?php _e( 'Capabilities', 'prospress' ); ?></h3>
		<p><?php printf( __( 'You can restrict interaction with %s to certain roles. Please choose which roles have the following capabilities:', 'prospress' ), $market_system->display_name() ); ?></p>
		<div class="prospress-capabilitiy create">
			<h4><?php printf( __( "Publish %s", 'prospress' ), $market_system->display_name() ); ?></h4>
			<?php foreach ( $roles as $role ): //if( $role->name == 'administrator' ) continue; ?>
			<?php //error_log( 'role = ' . print_r( $role, true ) ); ?>

			<label for="<?php echo $role->name; ?>-create">
				<input type="checkbox" id="<?php echo $role->name; ?>-publish" name="<?php echo $role->name; ?>-publish"<?php checked( $role->capabilities[ 'publish_prospress_posts' ], 1 ); ?> />
				<?php echo $role->display_name; ?>
			</label>
			<?php endforeach; ?>
		</div>
		<div class="prospress-capability edit">
			<h4><?php printf( __( "Edit %s", 'prospress' ), $market_system->display_name() ); ?></h4>
			<?php foreach ( $roles as $role ): //if( $role->name == 'administrator' ) continue; ?>
			<label for="<?php echo $role->name; ?>-edit">
			  	<input type="checkbox" id="<?php echo $role->name; ?>-edit" name="<?php echo $role->name; ?>-edit"<?php checked( $role->capabilities[ 'edit_prospress_post' ], 1 ); ?> />
				<?php echo $role->display_name; ?>
			</label>
			<?php endforeach; ?>
		</div>
		<div class="prospress-capability edit">
			<h4><?php printf( __( "Edit Other's %s", 'prospress' ), $market_system->display_name() ); ?></h4>
			<?php foreach ( $roles as $role ): //if( $role->name == 'administrator' ) continue; ?>
			<label for="<?php echo $role->name; ?>-edit-others">
				<input type="checkbox" id="<?php echo $role->name; ?>-edit-others" name="<?php echo $role->name; ?>-edit-others"<?php checked( $role->capabilities[ 'edit_others_prospress_posts' ], 1 ); ?> />
				<?php echo $role->display_name; ?>
			</label>
			<?php endforeach; ?>
		</div>
		<div class="prospress-capability delete">
			<h4><?php printf( __( "Delete %s", 'prospress' ), $market_system->display_name() ); ?></h4>
			<?php foreach ( $roles as $role ): //if( $role->name == 'administrator' ) continue; ?>
			<label for="<?php echo $role->name; ?>-delete">
				<input type="checkbox" id="<?php echo $role->name; ?>-delete" name="<?php echo $role->name; ?>-delete"<?php checked( $role->capabilities[ 'delete_prospress_post' ], 1 ) ?> />
				<?php echo $role->display_name; ?>
			</label>
			<?php endforeach; ?>
		</div>
	</div>
<?php
}
add_action( 'pp_core_settings_page', 'pp_capabilities_settings_page' );


/** 
 * Save capabilities settings when the admin page is submitted page. As the settings don't need to be stored in 
 * the options table of the database, they're not added to the whitelist as is expected by this filter, instead 
 * they're added to the appropriate roles.
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_capabilities_whitelist( $whitelist_options ) {
	global $wp_roles, $market_system;

    if ( $_POST['_wpnonce' ] && check_admin_referer( 'pp_capabilities_settings' ) && current_user_can( 'manage_options' ) ){

		$role_names = $wp_roles->get_names();
		$roles = array();

		foreach ( $role_names as $key=>$value ) {
			$roles[ $key ] = get_role( $key );
			$roles[ $key ]->display_name = $value;
		}

		foreach ( $roles as $key => $role ) {

			//if( $role->name == 'administrator' )
			//	continue;

			if ( isset( $_POST[ $key . '-publish' ] )  && $_POST[ $key . '-publish' ] == 'on' ) {
				$role->add_cap( 'publish_prospress_posts' );
			} else {
				$role->remove_cap( 'publish_prospress_posts' );
			}

			if ( isset( $_POST[ $key . '-edit' ] )  && $_POST[ $key . '-edit' ] == 'on' ) {
				$role->add_cap( 'edit_prospress_post' );
				$role->add_cap( 'edit_prospress_posts' );
			} else {
				$role->remove_cap( 'edit_prospress_post' );
				$role->remove_cap( 'edit_prospress_posts' );
			}

			if ( isset( $_POST[ $key . '-edit-others' ] )  && $_POST[ $key . '-edit-others' ] == 'on' ) {
				$role->add_cap( 'edit_others_prospress_posts' );
			} else {
				$role->remove_cap( 'edit_others_prospress_posts' );
	        }

			if ( isset( $_POST[ $key . '-delete' ] )  && $_POST[ $key . '-delete' ] == 'on' ) {
				$role->add_cap( 'delete_prospress_post' );
			} else {
				$role->remove_cap( 'delete_prospress_post' );
			}
		}
    }

	return $whitelist_options;
}
add_filter( 'pp_options_whitelist', 'pp_capabilities_whitelist' );


/** 
 * Clean up anything added on activation that does not need to persist incase of reactivation. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_posts_deactivate(){
	global $market_system;

	error_log( '** pp_posts_deactivate called **' );

	delete_option( 'widget_bid-filter' );
	delete_option( 'widget_pp-sort' );
	
	$sidebars_widgets = get_option( 'sidebars_widgets' );

	if( isset( $sidebars_widgets[ $market_system->name() . '-index-sidebar' ] ) ){
		unset( $sidebars_widgets[ $market_system->name() . '-index-sidebar' ] );
		update_option( 'sidebars_widgets', $sidebars_widgets );
	}
	if( isset( $sidebars_widgets[ $market_system->name() . '-single-sidebar' ] ) ){
		unset( $sidebars_widgets[ $market_system->name() . '-single-sidebar' ] );
		update_option( 'sidebars_widgets', $sidebars_widgets );
	}

 	$role = get_role( 'administrator' );

	$role->remove_cap( 'publish_prospress_posts' );
	$role->remove_cap( 'edit_prospress_post' );
	$role->remove_cap( 'edit_prospress_posts' );
	$role->remove_cap( 'edit_others_prospress_posts' );
	$role->remove_cap( 'read_prospress_posts' );
	$role->remove_cap( 'delete_prospress_post' );	
}
add_action( 'pp_deactivation', 'pp_posts_deactivate' );


/** 
 * When Prospress is uninstalled completely, remove that nasty index page created on activation. 
 * 
 * @package Prospress
 * @subpackage Posts
 * @since 0.1
 */
function pp_posts_uninstall(){
	global $wpdb, $market_system;

	error_log('*** in pp_posts_uninstall ***');

	$index_page_id = $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_name = '" . $market_system->name() . "'" );

	wp_delete_post( $index_page_id );

}
add_action( 'pp_uninstall', 'pp_posts_uninstall' );
