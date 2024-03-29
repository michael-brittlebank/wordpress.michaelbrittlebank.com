<?php
/*FILE: wp-reading-list-functions.php
*DESCRIPTION: Core plugin functions
*/
defined( 'ABSPATH' ) OR exit;

global $version;
$version = "4.0.1";

/*Load the custom taxonomies for 'works' and 'authors' */
if (!current_theme_supports('post-thumbnails')) {
    add_theme_support('post-thumbnails');
}
require 'wprl-core/wp-reading-list-taxonomies.php';
add_action('init', 'wprl_custom_tax', 0);

/*Set wprl default option settings */
function wprl_default_options() {
    global $version;
    $options = array(
        'title' => 'Notes',
        'multiple_title' => 'Reading List',
        'cover_image' => plugins_url('wp-reading-list/wprl-theme/default.png'),
        'order' => 'date',
        'direction' => 'DESC',
        'grid_width' => '3',
        'grid_rows' => '4',
        'works_in_feed' => false,
        'show_post_date' => false,
        'delete' => false,
        'show_page_nums' => false,
        'show_author' => true,
        'post_author' => false,
        'show_url' => true,
        'show_single_work' => true,
        'list_image' => true,
        'show_author_link' => true,
        'version' => $version,
        'show_list_excerpt' => true,
        'show_work_type' => true,
        'show_type_link' => true,
        'override_theme_taxonomies' => true,
    );
    return $options;
}

/*Initialize wprl options */
global $wprl_options;
$wprl_options = get_option('wprl_plugin_options');
if (!$wprl_options) {
    $wprl_options = wprl_default_options();
}
elseif ($wprl_options['version'] != '2.2') {
    $wprl_options_default = wprl_default_options();
    $wprl_options = array_merge($wprl_options_default,$wprl_options);
}
update_option('wprl_plugin_options', $wprl_options);

/*Check to see if user is in admin/backend */
if (is_admin()){
    require 'wprl-admin/wp-reading-list-admin.php';
}

/* queue different files depending on the layout chosen and the location */
function wprl_layout_query($query){
    $wprl_options = get_option('wprl_plugin_options');
    if (!is_admin() && $query->is_main_query() && $wprl_options['works_in_feed'] && !is_post_type_archive('works')) {
        $query->set('post_type', array('post', 'works'));
    }
    else if (is_post_type_archive('works') && !is_admin() && $query->is_main_query()) {
        $query->set('post_type', 'works');
        if ($wprl_options['order'] == 'rand') {
            $query->set('orderby', 'rand');
        }
        else {
            $orderby = $wprl_options['order'];
            if ($orderby == 'author') {
                $orderby .= ' title';
            }
            $query->set('orderby', $orderby);
            $query->set('order', $wprl_options['direction']);
        }
        $numposts = $wprl_options['grid_width'] * $wprl_options['grid_rows'];
        $query->set('posts_per_page', $numposts);
        return $query;
    }
}
add_action('pre_get_posts', 'wprl_layout_query');

/*Enqueue plugin stylesheet and allow for child theme to override */
if (!function_exists('wprl_styles')) {
    function wprl_styles() {
        global $version;
        if (!is_admin()) {
            wp_enqueue_style( 'reading-list-default-style', plugins_url('/wprl-theme/default.css?'.$version, __FILE__));
        }
    }
}
add_action('wp_enqueue_scripts', 'wprl_styles' );

/*Remove taxonomy function */
function remove_taxonomy($taxonomy) {
    global $wp_taxonomies;
    $terms = get_terms($taxonomy);
    foreach ($terms as $term) {
        wp_delete_term($term->term_id, $taxonomy );
    }
    unset($wp_taxonomies[$taxonomy]);
}

/*Delete works and authors function, called after 'save settings' or when deleting the plugin */
function delete_works(){
    global $wpdb;
    $query = "
		DELETE FROM wp_posts
		WHERE post_type = 'works'
	";
    $wpdb->query($query);
    remove_taxonomy('work-author');
    $GLOBALS['wp_rewrite']->flush_rules();
}

/* Force users to include a title on reading list items */
function force_post_title() {
    if (is_admin() && 'works' == get_post_type()) {
        echo ("<script type='text/javascript'>
	  		jQuery('#publish').click(function(){
	        	var testervar = jQuery('[id^=\"titlediv\"]')
	        	.find('#title');
	        	if (testervar.val().length < 1) {
	            		setTimeout(\"jQuery('.spinner').css('visibility', 'hidden');\", 100);
	            		alert('Please enter a work title.');
	            		setTimeout(\"jQuery('#publish').removeClass('button-primary-disabled');\", 100);
	            		return false;
	        	}
	    		});
	  	</script>\n");
    }
}

/*get featured image for works listing in admin columns */
function wprl_get_cover_image($post_ID) {
    $post_thumbnail_id = get_post_thumbnail_id($post_ID);
    if ($post_thumbnail_id) {
        $post_thumbnail_img = wp_get_attachment_image_src($post_thumbnail_id, 'featured_preview');
        return $post_thumbnail_img[0];
    }
}

/*add new column to admin listing for works */
function wprl_columns_head($defaults) {
    $defaults['author'] = 'Post Author';
    $defaults['featured_image'] = 'Featured Image';
    return $defaults;
}

/*show the cover image for the admin works listing */
function wprl_columns_content($column_name, $post_ID) {
    if ($column_name == 'featured_image') {
        $cover_image= wprl_get_cover_image($post_ID);
        if ($cover_image) {
            echo ('<img src="'.$cover_image.'"/>');
        }
    }
}
add_filter('manage_posts_columns', 'wprl_columns_head');
add_action('manage_posts_custom_column', 'wprl_columns_content', 10, 2);

/* add an "author" column to the "all works" list */
function post_author_column( $columns ) {
    $columns['author'] = 'author';
    return $columns;
}
add_filter('manage_edit-works_sortable_columns', 'post_author_column');

/*Template fallback*/
function wp_reading_list_redirect() {
    global $wp_query;
    $wprl_options = get_option('wprl_plugin_options');
    if (isset($wp_query->query_vars["post_type"]) && $wprl_options['override_theme_taxonomies']) {
        if($wp_query->query_vars["post_type"] == 'works') {
            if (is_singular('works')) {
                $return_template =  dirname( __FILE__ ) . '/wprl-theme/single-works.php';
                wprl_theme_redirect($return_template);
            }
            else {
                $return_template =  dirname( __FILE__ ) . '/wprl-theme/archive-works.php';
                wprl_theme_redirect($return_template);
            }
        }
        elseif (isset($wp_query->query_vars["taxonomy"]) && $wp_query->query_vars["taxonomy"] == 'work-author') {
            $return_template = dirname( __FILE__ ) . '/wprl-theme/taxonomy-work-author.php';
            wprl_theme_redirect($return_template);
        }
        elseif (isset($wp_query->query_vars["taxonomy"]) && $wp_query->query_vars["taxonomy"] == 'work-type') {
            $return_template = dirname( __FILE__ ) . '/wprl-theme/taxonomy-work-type.php';
            wprl_theme_redirect($return_template);
        }
    }
}

function wprl_theme_redirect($url) {
    global $post, $wp_query;
    if (have_posts()) {
        include($url);
        die();
    } else {
        $wp_query->is_404 = true;
    }
}
add_action("template_redirect", 'wp_reading_list_redirect');

function wprl_custom_excerpt($limit) {
    $excerpt = explode(' ', get_the_content(), $limit);
    if (count($excerpt)>=$limit) {
        array_pop($excerpt);
        $excerpt = implode(" ",$excerpt).'...';
    } else {
        $excerpt = implode(" ",$excerpt);
    }
    $excerpt = preg_replace('`\[[^\]]*\]`','',$excerpt);
    return $excerpt;
}

function content($limit) {
    $content = explode(' ', get_the_content(), $limit);
    if (count($content)>=$limit) {
        array_pop($content);
        $content = implode(" ",$content).'...';
    } else {
        $content = implode(" ",$content);
    }
    $content = preg_replace('/\[.+\]/','', $content);
    $content = apply_filters('the_content', $content);
    $content = str_replace(']]>', ']]&gt;', $content);
    return $content;
}

function register_wprl_localization() {
// Localization
    load_plugin_textdomain( 'wp_reading_list', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action('init', 'register_wprl_localization');

function wprl_rewrite_flush(){
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
}

add_filter('widget_text', 'do_shortcode');

function wprl_get_works($numPosts){
    $wprl_options = get_option('wprl_plugin_options');
    $orderby = $wprl_options['order'];
    if ($orderby == 'author') {
        $orderby .= ' title';
    }
    $args = array(
        'post_type' => 'works',
        'order' => $wprl_options['direction'],
        'orderby' => $orderby,
        'posts_per_page' => $numPosts,
        'post_status' => "publish"
    );
    return new WP_Query($args);
}

function wprl_layout_shortcode($atts) {
    $args = shortcode_atts(
        array(
            'layout' => 'plain',
            'number' => '5',
        ),
        $atts);
    if (isset($args["layout"]) && isset($args["number"])){
        $query = wprl_get_works($args["number"]);
        if ($query->have_posts()){
            if ($args["layout"] === "plain"){
                $output = "<ul>";
                while ($query->have_posts() ) {
                    $query->the_post();
                    $output .= "<li><a href='".get_permalink()."'>".get_the_title()."</a></li>";
                }
                return $output."</ul>";
            }
        }
    }
}
add_shortcode('wprl', 'wprl_layout_shortcode');

/**
 * End of File
 */