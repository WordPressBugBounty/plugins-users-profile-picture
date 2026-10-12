<?php
/**
 * Plugin Name: User Profile Picture - Custom Local Avatars
 * Description: Replace Gravatar with a custom profile picture from the Media Library, shown wherever WordPress displays avatars.
 * Version: 1.0.9
 * Plugin URI: http://dewtechnolab.com/project/user-profile-picture-plugin/
 * Author: Dew Technolab
 * Author URI: http://dewtechnolab.com/
 * Requires at least: 4.5
 * Requires PHP: 7.4
 * Text Domain: users-profile-picture
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
**/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Dew_Profile_Picture {
	// Plugin version, used to bust browser caches when the script changes
	const VERSION = '1.0.9';

	// Where the review request and the plugins-screen link send people
	const REVIEW_URL = 'https://wordpress.org/support/plugin/users-profile-picture/reviews/#new-post';

	//private
	private $plugin_url = '';
	private $plugin_dir = '';
	private $plugin_path = '';
	/**
	* __construct()
	*
	* Class constructor
	*
	*/
	function __construct(){
		// Translations: WordPress.org language packs load automatically for the "users-profile-picture" text domain.
		$this->plugin_path = plugin_basename( __FILE__ );
		$this->plugin_url = rtrim( plugin_dir_url(__FILE__), '/' );
		$this->plugin_dir = rtrim( plugin_dir_path(__FILE__), '/' );
		add_action( 'show_user_profile', array( $this, 'insert_upload_form' ) );
		add_action( 'edit_user_profile', array( $this, 'insert_upload_form' ) );
		add_action( 'user_new_form', array( $this, 'insert_upload_form' ) );
		//Scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'print_media_scripts' ) );
		//User update action
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile' ) );
		// Picture chosen on Users > Add New
		add_action( 'user_register', array( $this, 'save_new_user_profile' ) );
		//User Avatar override
		add_filter( 'get_avatar', array( $this, 'avatar_override' ), 10, 6 );
		// Review request (profile screens only) and plugins-screen link
		add_action( 'admin_notices', array( $this, 'review_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_review_notice' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
	}
	/* end constructor */

	/**
	* review_notice()
	*
	* Asks administrators for a WordPress.org review on the user profile screens, two weeks after first use.
	* "Maybe later" hides it for 30 days; "Leave a review" and "I already did" hide it for good.
	**/
	public function review_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit', 'users' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$first_use = (int) get_option( 'dew_pp_installed_time' );
		if ( ! $first_use ) {
			add_option( 'dew_pp_installed_time', time(), '', false );
			return;
		}
		$state = get_option( 'dew_pp_review_notice' );
		if ( 'done' === $state || ( is_numeric( $state ) && time() < (int) $state ) || time() - $first_use < 14 * DAY_IN_SECONDS ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Thanks for using User Profile Picture! If it helps your site, would you leave a review on WordPress.org? It takes a minute and helps other people find the plugin.', 'users-profile-picture' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->review_action_url( 'review' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review', 'users-profile-picture' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->review_action_url( 'later' ) ); ?>"><?php esc_html_e( 'Maybe later', 'users-profile-picture' ); ?></a>
				<a href="<?php echo esc_url( $this->review_action_url( 'done' ) ); ?>"><?php esc_html_e( 'I already did', 'users-profile-picture' ); ?></a>
			</p>
		</div>
		<?php
	} //end review_notice

	/**
	* review_action_url()
	*
	* @param string $action review, later or done.
	* @return string Nonce-protected link for the review notice buttons.
	**/
	private function review_action_url( $action ) {
		return wp_nonce_url( add_query_arg( 'dew_pp_review', $action ), 'dew_pp_review' );
	} //end review_action_url

	/**
	* handle_review_notice()
	*
	* Handles the review notice buttons.
	**/
	public function handle_review_notice() {
		if ( empty( $_GET['dew_pp_review'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'dew_pp_review' );
		$action = sanitize_key( wp_unslash( $_GET['dew_pp_review'] ) );
		update_option( 'dew_pp_review_notice', 'later' === $action ? (string) ( time() + 30 * DAY_IN_SECONDS ) : 'done', false );
		if ( 'review' === $action ) {
			wp_redirect( self::REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed WordPress.org address.
			exit;
		}
		wp_safe_redirect( remove_query_arg( array( 'dew_pp_review', '_wpnonce' ) ) );
		exit;
	} //end handle_review_notice

	/**
	* plugin_row_meta()
	*
	* Adds a review link under the plugin on the Plugins screen.
	*
	* @param array  $links Row meta links.
	* @param string $file  Plugin file.
	* @return array
	**/
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$links[] = '<a href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Leave a review', 'users-profile-picture' ) . '</a>';
		}
		return $links;
	} //end plugin_row_meta

	/**
	* insert_upload_form
	*
	* Adds an upload form to the user profile page and outputs profile image if there is one
	*
	* @param WP_User|string $user The profile being edited, or the form context on Users > Add New.
	*/
	public function insert_upload_form( $user = null ) {
		// Multisite "Add Existing User" form: there is no new profile to attach a picture to.
		if ( 'add-existing-user' === $user ) {
			return;
		}
		$user_id = $user instanceof WP_User ? $user->ID : 0;
		$profile_pic = $user_id ? get_user_meta( $user_id, 'profilepicture', true ) : '';
		?>
		<table class="form-table fh-profile-upload-options">
			<tr>
				<th>
					<label for="dew-image"><?php esc_html_e( 'Profile Picture', 'users-profile-picture' ); ?></label>
				</th>

				<td>
					<input type="button" data-id="dew_image_id" data-src="dew-img" class="button dew-image" name="dew_image" id="dew-image" value="<?php esc_attr_e( 'Upload', 'users-profile-picture' ); ?>" />
					<input type="hidden" class="button" name="dew_image_id" id="dew_image_id" value="<?php echo !empty($profile_pic) ? esc_url($profile_pic) : ''; ?>" />
					<img id="dew-img" src="<?php echo esc_url( ! empty( $profile_pic ) ? $profile_pic : $this->get_plugin_url( 'img/placeholder.png' ) ); ?>" alt="" style="max-width: 100px; max-height: 100px;"/>
				</td>
			</tr>
		</table><?php
	} //end insert_upload_form

	/**
	* print_media_scripts
	*
	* Output media scripts for thickbox and media uploader, only on the user profile screens
	*
	* @param string $hook_suffix Current admin page.
	**/
	public function print_media_scripts( $hook_suffix = '' ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'user-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'dew-uploader', $this->get_plugin_url( '/js/uploaders.js' ), array( 'jquery' ), self::VERSION, true );
	} //end print_media_scripts

	/**
	* get_plugin_dir()
	*
	* Returns an absolute path to a plugin item
	*
	* @param		string    $path	Relative path to make absolute (e.g., /css/image.png)
	* @return		string               An absolute path (e.g., /htdocs/ithemes/wp-content/.../css/image.png)
	*/
	public function get_plugin_dir( $path = '' ) {
		$dir = $this->plugin_dir;
		if ( !empty( $path ) && is_string( $path) )
			$dir .= '/' . ltrim( $path, '/' );
		return $dir;
	} //end get_plugin_dir


	/**
	* get_plugin_url()
	*
	* Returns an absolute url to a plugin item
	*
	* @param		string    $path	Relative path to plugin (e.g., /css/image.png)
	* @return		string               An absolute url (e.g., http://www.domain.com/plugin_url/.../css/image.png)
	*/
	public function get_plugin_url( $path = '' ) {
		$dir = $this->plugin_url;
		if ( !empty( $path ) && is_string( $path) )
			$dir .= '/' . ltrim( $path, '/' );
		return $dir;
	} //get_plugin_url

	/**
	* save_user_profile()
	*
	* Saves user profile fields. Core verifies the profile form's nonce before firing
	* personal_options_update / edit_user_profile_update.
	*
	* @param int $user_id
	**/
	public function save_user_profile( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// Only when the picture field was submitted: other code that fires these hooks must not wipe the picture.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by core before these hooks run.
		if ( ! isset( $_POST['dew_image_id'] ) ) {
			return;
		}
		// Stored raw (escaped on output); only http(s) image URLs are accepted.
		$profile_pic = esc_url_raw( wp_unslash( $_POST['dew_image_id'] ), array( 'http', 'https' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		update_user_meta( $user_id, 'profilepicture', $profile_pic );
	} //end save_user_profile

	/**
	* save_new_user_profile()
	*
	* Saves the picture chosen on Users > Add New (core verifies that form's nonce before creating the user).
	*
	* @param int $user_id New user ID.
	**/
	public function save_new_user_profile( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by core, see above.
		if ( ! is_admin() || ! isset( $_POST['action'] ) || 'createuser' !== $_POST['action'] ) {
			return;
		}
		$this->save_user_profile( $user_id );
	} //end save_new_user_profile

	/**
	* avatar_override()
	*
	* Overrides an avatar with a profile image
	*
	* @param string $avatar SRC to the avatar
	* @param mixed $id_or_email
	* @param int $size Size of the image
	* @param string $default URL to the default image
	* @param string $alt Alternative text
	* @param array $args Arguments passed to get_avatar()
	**/
	public function avatar_override( $avatar, $id_or_email, $size, $default, $alt, $args = array() ) {
		global $pagenow;
		if ( 'options-discussion.php' == $pagenow ) return $avatar; //Stop overriding gravatars on options-discussion page
		if ( ! empty( $args['force_default'] ) ) return $avatar;

		//Get user data
		$user = $this->get_avatar_user( $id_or_email );
		if ( ! $user ) return $avatar;
		$user_id = $user->ID;

		//Build classes array based on passed in args, else set defaults - see get_avatar in /wp-includes/pluggable.php
		$classes = array(
			'avatar',
			sprintf( 'avatar-%s', absint( $size ) ),
			'photo'
		);
		if ( ! empty( $args['class'] ) ) {
			$classes = array_merge( $classes, is_array( $args['class'] ) ? $args['class'] : explode( ' ', $args['class'] ) );
		}

		//Get custom filter classes
		$classes = (array)apply_filters( 'dpp_avatar_classes', $classes );

		// Keep the lazy-loading hints WordPress computed for this image
		$attr = array( 'alt' => $alt, 'class' => implode( ' ', $classes ) );
		foreach ( array( 'loading', 'decoding', 'fetchpriority' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$attr[ $key ] = $args[ $key ];
			}
		}

		//Determine if the user has a profile image
		$custom_avatar = dew_profile_img( $user_id, array(
			'size' => array( $size, $size ),
			'attr' => $attr,
			'echo' => false )
		 );

		if ( ! $custom_avatar ) return $avatar;
		return $custom_avatar;
	} //end avatar_override

	/**
	* get_avatar_user()
	*
	* The user an avatar request refers to: a user ID, email, WP_User, WP_Post (its author) or a comment
	* left by a logged-in member. Guest comments keep their default avatar.
	*
	* @param mixed $id_or_email Value passed to get_avatar().
	* @return WP_User|false
	**/
	private function get_avatar_user( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return get_user_by( 'id', absint( $id_or_email ) );
		}
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email->exists() ? $id_or_email : false;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return get_user_by( 'id', (int) $id_or_email->post_author );
		}
		if ( is_object( $id_or_email ) ) {
			return ! empty( $id_or_email->user_id ) ? get_user_by( 'id', (int) $id_or_email->user_id ) : false;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			return get_user_by( 'email', $id_or_email );
		}
		return false;
	} //end get_avatar_user
}
/* end Dew_Profile_Picture */
//instantiate the class
global $dew_pp;
if (class_exists('Dew_Profile_Picture')) {
	if ( version_compare( get_bloginfo( 'version' ), '4.5', '>=' ) ) {
		add_action( 'plugins_loaded', 'dew_pp_instantiate' );
	}
}
function dew_pp_instantiate() {
	global $dew_pp;
	$dew_pp = new Dew_Profile_Picture();
}
/**
* dew_profile_img
*
* Adds a profile image
*
@param $user_id INT - The user ID for the user to retrieve the image for
@param $args array - 'size' (array( width, height ), a number or a named size), 'attr' (img attributes), 'echo'
*/
function dew_profile_img( $user_id, $args = array() ) {

	$defaults = array(
		'size' => 'thumbnail',
		'attr' => '',
		'echo' => true
	);
	$args = wp_parse_args( $args, $defaults );

	$profile = '';
	$profile_pic = get_user_meta($user_id, 'profilepicture', true);
	if ($profile_pic) {
		// Named sizes such as the default 'thumbnail' fall back to the standard avatar size.
		$size = is_array( $args['size'] ) ? reset( $args['size'] ) : $args['size'];
		$size = is_numeric( $size ) ? absint( $size ) : 96;
		$attr = is_array( $args['attr'] ) ? $args['attr'] : array();
		$attr = wp_parse_args( $attr, array( 'alt' => '', 'class' => 'avatar avatar-' . $size . ' photo' ) );

		$profile = '<img alt="' . esc_attr( $attr['alt'] ) . '" src="' . esc_url( $profile_pic ) . '" class="' . esc_attr( $attr['class'] ) . '" height="' . $size . '" width="' . $size . '"';
		foreach ( array( 'loading', 'decoding', 'fetchpriority' ) as $key ) {
			if ( ! empty( $attr[ $key ] ) ) {
				$profile .= ' ' . $key . '="' . esc_attr( $attr[ $key ] ) . '"';
			}
		}
		$profile .= '>';
	}
	if ( $args['echo'] ) {
		echo $profile; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	} else {
		return $profile;
	}
} //end dew_profile_img