<?php
/*
Plugin Name: CFTP Deporter
Plugin URI: http://codeforthepeople.com
Description: Transfer articles published on the parent site to the respective child site
Version: 1.1
Author: Scott Evans & Simon Wheatley (Code For The People Ltd)
Author URI: http://codeforthepeople.com/

				_____________
			   /      ____   \
		 _____/       \   \   \
		/\    \        \___\   \
	   /  \    \                \
	  /   /    /          _______\
	 /   /    /          \       /
	/   /    /            \     /
	\   \    \ _____    ___\   /
	 \   \    /\    \  /       \
	  \   \  /  \____\/    _____\
	   \   \/        /    /    / \
		\           /____/    /___\
		 \                        /
		  \______________________/


*/


/**
 * 
 * 
 * @package 
 **/
class CFTP_Deporter {

	/**
	 * A version integer.
	 *
	 * @var int
	 **/
	var $version;

	/**
	 * Singleton stuff.
	 * 
	 * @access @static
	 * 
	 * @return CFTP_Deporter object
	 */
	static public function init() {
		static $instance = false;

		if ( ! $instance )
			$instance = new CFTP_Deporter;

		return $instance;

	}

	/**
	 * Class constructor
	 *
	 * @return null
	 */
	public function __construct() {
		if ( is_admin() ) { 
			add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		}

		$this->version = 1;
	}

	// HOOKS
	// =====

	/**
	 * Hooks the WP action admin_init
	 *
	 * @action admin_init
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function action_admin_init() {
		$this->maybe_upgrade();
	}

	public function action_admin_menu() {
	 	add_management_page( 'cftp_dep', 'CFTP Deporter', 'administrator', 'cftp_dep', array( $this, 'callback_settings' ) );
	}

	// CALLBACKS
	// =========

	public function callback_settings() { 
		
	?>

	<style type="text/css">
	.output {
		background: #eee;
		padding: 20px;
		overflow-y: auto;
		height: 100px;
	}	

	</style>

	<div class="wrap">

		<div class="icon32" id="icon-options-general"></div>
		
		<h2>CFTP Deporter</h2><br/>
		
		<?php 

		// save routine 

		if ( isset( $_POST[ 'cftpdep_deport' ] ) )
			$this->deport_posts();

		$taxonomy = get_taxonomy( $this->get_taxonomy_name() );

		?>

		<form method="post" action="tools.php?page=cftp_dep">
		    
		    <p><?php printf( 'Select a %s and site into which the posts should be deported:', $taxonomy->labels->name_admin_bar ); ?></p>
		    <table class="form-table">
		        <tr valign="top">
		        <th scope="row"><?php printf( 'Copy from %s:', $taxonomy->labels->name_admin_bar ); ?></th>
		        <td>
					<?php
					$args = array(
						'orderby'            => 'name', 
						'order'              => 'ASC',
						'show_count'         => 0,
						'hide_empty'         => 1, 
						'echo'               => 1,
						'selected'           => 0,
						'name'               => 'cftpdep_term',
						'id'                 => 'cftpdep_term',
						'class'              => 'postform',
						'depth'              => 0,
						'tab_index'          => 0,
						'taxonomy'           => $taxonomy->name,
					);
					wp_dropdown_categories($args);
					?>
		    	</td>
		        </tr>

		        <tr valign="top">
		        <th scope="row">Copy to site:</th>
		        <td>
		        	<?php
		        	global $wpdb;
	 				$site_list = $wpdb->get_results("SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = '{$wpdb->siteid}' AND spam = '0' AND deleted = '0' AND archived = '0' AND blog_id != 1");
					$sites = array();
					
					foreach ($site_list as $blog) {
						$sites[$blog->blog_id] = get_blog_option($blog->blog_id, 'blogname');
					}
	 
					natsort($sites);

					echo '<select id="cftpdep_target_site" name="cftpdep_target_site">' . "\n";

					foreach ($sites as $site_id => $site ) {
						echo '<option value="'.$site_id.'">'.$site."</option>\n";
					}
		
					echo '</select>';

		        	?>
		    	</td>
		        </tr>
		    </table>
		    
			<p class="submit">
				<input type="submit" class="button-primary" name="cftpdep_deport" value="<?php _e('Deport Posts') ?>" />
			</p>
		</form>
		
	</div>
	<?php 
	}
	// UTILITIES
	// =========

	protected function deport_posts() {
		$orig_term_id  = isset($_POST['cftpdep_term']) ? absint($_POST['cftpdep_term']) : false;
		$target_blog_id = isset($_POST['cftpdep_target_site']) ? absint($_POST['cftpdep_target_site']) : false;
		$orig_blog_id = $GLOBALS['blog_id'];

		if ( $orig_term_id && $target_blog_id ) {

			if (!$orig_term_id)
				echo '<div id="message" class="error"><p>Invalid Category</p></div>';
			if (!$target_blog_id)
				echo '<div id="message" class="error"><p>Invalid Site</p></div>';

			// all posts in the chosen taxonomy
			$args = array(
				'post_type' => 'post',
				'posts_per_page' => -1,
				'post_status' => array('publish'),
				'tax_query' => array(
					array(
						'taxonomy' => $this->get_taxonomy_name(),
						'field' => 'id',
						'terms' => $orig_term_id
					)
				),
				'fields' => 'ids',
			);
			
			// Let's increase our memory resources first
			ini_set('memory_limit', '512M');
			// Add another 15 minutes to PHP execution time
			set_time_limit(60 * 15);

			// add_filter( 'posts_where', 'filter_where' );
			$all = new WP_Query( $args );
			// remove_filter( 'posts_where', 'filter_where' );
			
			if ( $all->posts ) {

				echo '<div class="output">';

				echo "Found ".$all->post_count. " post(s)<br/>";
				//flush();
				//sleep(1);

				$taxonomies = get_object_taxonomies( 'post' );

				$i=0;
				foreach ( $all->posts as $orig_post_id ) {

					global $feature_posts_on_root_blog;

					echo "Deporting&hellip;".get_the_title()." (".get_the_ID().") &hellip;";
					//flush();

					// prepare this post for inserting - grab it as an array
					$orig_post_data = get_post( $orig_post_id, ARRAY_A );
					unset( $orig_post_data[ 'ID' ] );

					// prepare the current meta data
					$orig_meta_data = get_post_meta( $orig_post_id );
					foreach ( $orig_meta_data as $meta_key => $meta_rows ) {
						if ( in_array($meta_key, array('_cftp_dep_permalink', '_cftp_dep_orig_post_id', '_cftp_dep_orig_blog_id')) ) {
							unset( $orig_meta_data[ $meta_key ] );
						}
					}

					$orig_url = get_permalink( $orig_post_id );

					// Note the following have to be one item arrays, to fit in with the
					// output of get_post_meta.
					$orig_meta_data[ '_cftp_dep_deported' ] = array( true );
					$orig_meta_data[ '_cftp_dep_permalink' ] = array( $orig_url );
					$orig_meta_data[ '_cftp_dep_orig_post_id' ] = array( $orig_post_id );
					$orig_meta_data[ '_cftp_dep_orig_blog_id' ] = array( $orig_blog_id );

					if (has_post_thumbnail(get_the_ID())) {
	 					$featured_image = wp_get_attachment_url(get_post_thumbnail_id($post_id), 'full');
					}

					// Get all related terms
					$terms = wp_get_object_terms( $orig_post_id, $taxonomies );

					// insert this post into the child blog
					switch_to_blog($target_blog_id);

					// if post exists then update - else insert - THIS CHECK DOES NOT WORK!
					$args = array(
						'post_type' => 'post',
						'post_status' => 'any',
						'meta_query' => array(
							'relation' => 'AND',
							array(
								'key'   => '_cftp_dep_orig_post_id',
								'value' => $orig_post_id,
							),
							array(
								'key'   => '_cftp_dep_orig_blog_id',
								'value' => $orig_blog_id,
							),
						),
					);
					$query = new WP_Query( $args );

					if ( $query->have_posts() ) {
						$target_post_id = $query->post->ID;
						$orig_post_data[ 'ID' ] = $target_post_id;
						wp_update_post( $orig_post_data );
					} else {
						$target_post_id = wp_insert_post( $orig_post_data );
					}
					
					if ($target_post_id) {
						//sleep(1);
						echo "Deported post #$orig_post_id<br/>";
						//flush();

						$target_url = get_permalink( $target_post_id );

						$has_image = get_post_meta($target_post_id, '_thumbnail_id', true);

						// Delete all metadata
						$target_meta_data = get_post_meta( $target_post_id );
						foreach ( $target_meta_data as $meta_key => $meta_rows ) {
							if (!in_array($meta_key, array('_thumbnail_id')) ) {
								delete_post_meta( $target_post_id, $meta_key );
							}
						}

						// Re-add metadata
						foreach ( $orig_meta_data as $meta_key => $meta_rows ) {
							if ( ! in_array( $meta_key, array( '_thumbnail_id' ) ) ) {
								$unique = ( count( $meta_rows ) == 1 );
								foreach ( $meta_rows as $meta_row )
									add_post_meta( $target_post_id, $meta_key, $meta_row, $unique );
							}
						}

						// Assume all the same taxonomies are active in the target site
						// Move across all the

						// sideload and attach the featured image if the post has one
						if (isset($featured_image)) {

							if ($has_image == '') {
								$attachment = sideload_image($featured_image, $target_post_id, null);
							
								if (!is_wp_error( $attachment )) 
									update_post_meta( $target_post_id, '_thumbnail_id', $attachment );
							}

							unset($featured_image);
						}
						
						foreach ( $terms as $term ) {
							$remote_term = get_term_by( 'name', $term->name, $term->taxonomy );
							if ( ! $remote_term )
								$remote_term = wp_insert_term( $term->name, $term->taxonomy, array( 'description' => $term->description, 'slug' => $term->slug ) );
							wp_set_object_terms( $target_post_id, absint( $remote_term->term_id ), $remote_term->taxonomy, true );
						}

						restore_current_blog();

						// add post meta to this post to make it work as a 'promoted' post
						$params = array( 
							'orig_post_id'   => $orig_post_id,
							'orig_url'       => $orig_url,
							'target_blog_id' => $target_blog_id, 
							'target_post_id' => $target_post_id, 
							'target_url'     => $target_url, 
						);
						do_action( 'cftp_dep_deported_post', $target_post_id, $params );

					} else {
						echo "<span style='color: red;'>FAILED</span> on post #$orig_post_id<br/>";
						//flush();
						restore_current_blog();
					}

					//sleep(1);

					$i++;
					if ($i == 25) {
						$i = 0;
						set_time_limit(60);
					}
				}

				echo '</div>';
			} else {
				?>
					<p><strong>No posts found for deportation.</strong></p>
				<?php
			}
		}
	}

	protected function sideload_image( $file, $post_id, $desc ) {
		require_once( ABSPATH . '/wp-admin/includes/file.php' );
		require_once( ABSPATH . '/wp-admin/includes/media.php' );
		require_once( ABSPATH . '/wp-admin/includes/image.php' );
		// START Direct copy/paste from media_sideload_image()
		// Download file to temp location
		$tmp = download_url( $file );

		// Set variables for storage
		// fix file filename for query strings
		preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $file, $matches);
		$file_array['name'] = str_replace( '%20', '-', basename($matches[0]) );
		$file_array['tmp_name'] = $tmp;

		// If error storing temporarily, unlink
		if ( is_wp_error( $tmp ) ) {
			@unlink($file_array['tmp_name']);
			$file_array['tmp_name'] = '';
		}

		// do the validation, attachment and storage stuff
		$id = media_handle_sideload( $file_array, $post_id, $desc );
		// If error storing permanently, unlink
		if ( is_wp_error($id) ) {
			@unlink($file_array['tmp_name']);
			return $id;
		}
		// END Direct copy/paste from media_sideload_image()
		return $id;
	}

	protected function get_taxonomy_name() {
		return apply_filters( 'cftp_dep_taxonomy', 'category' );
	}

	/**
	 * Returns the URL for for a file/dir within this plugin.
	 *
	 * @param  string The path within this plugin, e.g. '/js/clever-fx.js'
	 * @return string URL
	 * @author John Blackbourn
	 **/
	protected function plugin_url( $file = '' ) {
		return $this->plugin( 'url', $file );
	}

	/**
	 * Returns the filesystem path for a file/dir within this plugin.
	 *
	 * @param  string The path within this plugin, e.g. '/js/clever-fx.js'
	 * @return string Filesystem path
	 * @author John Blackbourn
	 **/
	protected function plugin_path( $file = '' ) {
		return $this->plugin( 'path', $file );
	}

	/**
	 * Returns a version number for the given plugin file.
	 *
	 * @param  string The path within this plugin, e.g. '/js/clever-fx.js'
	 * @return string Version
	 * @author John Blackbourn
	 **/
	protected function plugin_ver( $file ) {
		return filemtime( $this->plugin_path( $file ) );
	}

	/**
	 * Returns the current plugin's basename, eg. 'my_plugin/my_plugin.php'.
	 *
	 * @return string Basename
	 * @author John Blackbourn
	 **/
	protected function plugin_base() {
		return $this->plugin( 'base' );
	}

	/**
	 * Populates and returns the current plugin info.
	 *
	 * @author John Blackbourn
	 **/
	protected function plugin( $item, $file = '' ) {
		if ( ! isset( $this->plugin ) ) {
			$this->plugin = array(
				'url'  => plugin_dir_url( $this->file ),
				'path' => plugin_dir_path( $this->file ),
				'base' => plugin_basename( $this->file )
			);
		}
		return $this->plugin[ $item ] . ltrim( $file, '/' );
	}
}


// Initiate the singleton
CFTP_Deporter::init();
