<?php
/**
 * Plugin Name: LH Number User Nicename
 * Plugin URI: https://lhero.org/portfolio/lh-number-user-nicename/
 * Description: Match the user_nicename for WordPress users to harden security
 * Version: 1.06
 * Author: Peter Shaw
 * Author URI: https://shawfactor.com
 * Requires PHP: 5.6
 * Text Domain: lh_number_user_nicename
 * Domain Path: /languages
*/

if (!class_exists('LH_Number_user_nicename_plugin')) {

class LH_Number_user_nicename_plugin {

    private static $instance;

    static function return_plugin_namespace(){

    return 'lh_number_user_nicename';

    }
    
    static function return_user_nicename_backend_field_name(){
        
    return 'lh_number_user_nicename-backend_field_name';
        
    }

    static function fix_nicename($user_id){    
        
        global $wpdb;
        
        $sql = "update ".$wpdb->users." set user_nicename = '".$user_id."' where ID = '".$user_id."'";
        
        $result = $wpdb->get_results($sql);
        
        wp_cache_flush();
    
    }
    
    static function return_excluded_roles(){
        
        //specifically exclude management type roles
        $excluded_roles = array('administrator', 'editor', 'author', 'contributor');

        return apply_filters( 'lh_number_user_nicename_return_excluded_roles_filter', $excluded_roles);
        
        
    }

    static function return_actionable_users(){

        $excluded_roles = self::return_excluded_roles();

        global $wpdb; 
        $sql = "SELECT users.ID, users.user_nicename FROM ".$wpdb->usermeta." as usermeta, ".$wpdb->users." as users where usermeta.user_id = users.ID and usermeta.meta_key = '".$wpdb->prefix."capabilities'";

        foreach ($excluded_roles as $excluded_role){

            $sql .= " and usermeta.meta_value not LIKE '%".$excluded_role."%'";

        }

        $sql .= " and (abs(users.user_nicename) = 0 AND users.user_nicename != '0') GROUP BY users.ID limit 20";

        //echo $sql;

        //$results = $wpdb ->get_results($sql);

        $args = array( 'role__not_in' => $excluded_roles,
        'number' => 20,
        'meta_query' => array(
            array(
             'key' => 'lh_number_user_nicename-actioned_user',
             'compare' => 'NOT EXISTS' // this should work...
            ),
        )
        
        ) ;


        $results = new WP_User_Query($args);

        return $results->results;

    }

    static function adjust_users_nicename(){
    
        if (is_main_site()){
        
            $returned_users = self::return_actionable_users();
        
            //print_r($returned_users);
        
            foreach ($returned_users as $returned_user){
        
                self::fix_nicename($returned_user->ID);
        
                update_user_meta( $returned_user->ID, "lh_number_user_nicename-actioned_user", "1" );
        
            }
        
        }
    
    }

    static function setup_crons(){
        
        wp_clear_scheduled_hook( 'lh_number_user_nicename_process' );
        wp_clear_scheduled_hook( 'lh_number_user_nicename_initial_run' );
        wp_schedule_single_event(time() + wp_rand( 0, 300 ), 'lh_number_user_nicename_initial_run');
        wp_schedule_event( time() + wp_rand( 0, 1000 ), 'daily', 'lh_number_user_nicename_process' );
    
    }

    static function remove_crons(){
            
        wp_clear_scheduled_hook( 'lh_number_user_nicename_initial_run' );
        wp_clear_scheduled_hook( 'lh_number_user_nicename_process' ); 
        
    }



    public function user_register( $user_id ) {
    
        self::fix_nicename($user_id);
        
        update_user_meta( $user_id, "lh_number_user_nicename-actioned_user", "1" );
        
        wp_cache_flush();
    
    }
    
    public function run_initial_processes(){
    
        self::adjust_users_nicename();
    
    }

    public function run_ongoing_process(){

        self::adjust_users_nicename();
    
    }

    public function show_authors_without_posts( $template ) {
        global $wp_query;

        if ( $wp_query->query_vars[ 'post_type' ] === 'author' ) {

            return get_author_template();
        }

        return $template;
        
    }

public function extra_user_profile_field( $user ) {


  if ( (is_multisite() and current_user_can( 'edit_users' ) and is_network_admin()) or (!is_multisite() and current_user_can( 'edit_users' ))) {

?>

<table class="form-table">
<tr>
<th><label for="<?php echo self::return_user_nicename_backend_field_name(); ?>"><?php _e('User Nicename', self::return_plugin_namespace()); ?></label></th>
<td><input type="text" name="<?php echo self::return_user_nicename_backend_field_name(); ?>" id="<?php echo self::return_user_nicename_backend_field_name(); ?>" value="<?php echo $user->user_nicename; ?>" class="regular-text" /></td>
</tr>
</table>

<?php
wp_nonce_field( self::return_plugin_namespace()."-backend_nonce", self::return_plugin_namespace()."-backend_nonce" ); 
}

}


    public function do_profile_update( $user_id, $old_user_data ) {
    
    	// Remove the auto-update actions so we don't find ourselves in a loop.
    	remove_action( 'profile_update', array($this,"do_profile_update") );
    
    
        if (is_admin() && (current_user_can( 'edit_users' )) && !empty($_POST[self::return_user_nicename_backend_field_name()]) && wp_verify_nonce( $_POST[self::return_plugin_namespace().'-backend_nonce'], self::return_plugin_namespace().'-backend_nonce')) {
    
            $args = array(
                'ID'            => $user_id,
                'user_nicename' => sanitize_title($_POST[self::return_user_nicename_backend_field_name()])
            );
            
            wp_update_user( $args );
        
        } else {
        
            self::fix_nicename($user_id);    
        
        }
    
    }



    public function plugin_init(){
        
        //load the translations
        load_plugin_textdomain( self::return_plugin_namespace(), false, basename( dirname( __FILE__ ) ) . '/languages' );
        
        //oveeride new users nicename
        add_action( 'user_register', array($this, 'user_register'), 10, 1);
        
        //Hook to attach processes to initial cron job
        add_action('lh_number_user_nicename_initial_run', array($this,"run_initial_processes"));
        
        //Hook to attach processes to ongoing cron job
        add_action('lh_number_user_nicename_process', array($this,"run_ongoing_process"));
        
        //add a template for authors even if they have no posts
        add_filter( '404_template', array($this,"show_authors_without_posts"), 10, 1);
        
        //add extra user_nicename field
        add_action( 'show_user_profile', array($this,"extra_user_profile_field"),10,1);
        add_action( 'edit_user_profile', array($this,"extra_user_profile_field"),10,1);
        
        //process the field change
        add_action( 'profile_update', array($this,"do_profile_update"),10,2);
        
    }

    /**
     * Gets an instance of our plugin.
     *
     * using the singleton pattern
     */
     
    public static function get_instance(){
        
        if (null === self::$instance) {
            
            self::$instance = new self();
            
        }
 
        return self::$instance;
        
    }
    
    static function on_activate($network_wide) {

        if ( is_multisite() && $network_wide ) { 
        
            $args = array('number' => 500, 'fields' => 'ids');
        
            $sites = get_sites($args);
    
            foreach ($sites as $blog_id) {
            
                switch_to_blog($blog_id);
                self::setup_crons();
                restore_current_blog();
            
            } 
        
        } else {

            self::setup_crons();

        }

    }

    static function on_deactivate($network_wide) {
    
        if ( is_multisite() && $network_wide ) { 

            $args = array('number' => 500, 'fields' => 'ids');
            $sites = get_sites($args);
    
            foreach ($sites as $blog_id) {
            
                switch_to_blog($blog_id);
                self::remove_crons();
                restore_current_blog();
            
            } 

        } else {

            self::remove_crons();

        }
    
    }



    public function __construct() {
        
        //run our hooks on plugins loaded to as we may need checks       
        add_action( 'plugins_loaded', array($this,'plugin_init'));
    
    }

}

$lh_number_user_nicename_instance = LH_Number_user_nicename_plugin::get_instance();
register_activation_hook(__FILE__, array('LH_Number_user_nicename_plugin', 'on_activate'));
register_deactivation_hook( __FILE__, array('LH_Number_user_nicename_plugin','on_deactivate') );

}


?>