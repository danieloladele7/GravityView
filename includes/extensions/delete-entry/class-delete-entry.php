<?php
/**
 * The GravityView Delete Entry Extension
 *
 * Delete entries in GravityView.
 *
 * @since     1.5.1
 * @package   GravityView
 * @license   GPL2+
 * @author    GravityView <hello@gravityview.co>
 * @link      http://gravityview.co
 * @copyright Copyright 2014, Katz Web Services, Inc.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * @since 1.5.1
 */
final class GravityView_Delete_Entry {

	static $file;
	static $instance;
	var $entry;
	var $form;
	var $view_id;
	var $is_valid = null;

	/**
	 * The value of the `delete_redirect` option when the setting is to redirect to Multiple Entries after delete
	 * @since 2.9.2
	 */
	const REDIRECT_TO_MULTIPLE_ENTRIES_VALUE = 1;

	/**
	 * The value of the `delete_redirect` option when the setting is to redirect to URL
	 * @since 2.9.2
	 */
	const REDIRECT_TO_URL_VALUE = 2;

	function __construct() {

		self::$file = plugin_dir_path( __FILE__ );

		if ( is_admin() ) {
			$this->load_components( 'admin' );
		}

		$this->add_hooks();
	}

	private function load_components( $component ) {

		$dir = trailingslashit( self::$file );

		$filename  = $dir . 'class-delete-entry-' . $component . '.php';
		$classname = 'GravityView_Delete_Entry_' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $component ) ) );

		// Loads component and pass extension's instance so that component can
		// talk each other.
		require_once $filename;
		$this->instances[ $component ] = new $classname( $this );
		$this->instances[ $component ]->load();

	}

	/**
	 * @since 1.9.2
	 */
	private function add_hooks() {

		add_action( 'wp', array( $this, 'process_delete' ), 10000 );

		add_filter( 'gravityview_entry_default_fields', array( $this, 'add_default_field' ), 10, 3 );

		add_action( 'gravityview_before', array( $this, 'maybe_display_message' ) );

		// For the Delete Entry Link, you don't want visible to all users.
		add_filter( 'gravityview_field_visibility_caps', array( $this, 'modify_visibility_caps' ), 10, 5 );

		// Modify the field options based on the name of the field type
		add_filter( 'gravityview_template_delete_link_options', array( $this, 'delete_link_field_options' ), 10, 5 );

		// add template path to check for field
		add_filter( 'gravityview_template_paths', array( $this, 'add_template_path' ) );

		add_action( 'gravityview/edit-entry/publishing-action/after', array( $this, 'add_delete_button' ), 10, 4 );

		add_action( 'gravityview/delete-entry/deleted', array( $this, 'process_connected_posts' ), 10, 2 );
		add_action( 'gravityview/delete-entry/trashed', array( $this, 'process_connected_posts' ), 10, 2 );

		add_filter( 'gravityview/field/is_visible', array( $this, 'maybe_not_visible' ), 10, 3 );

		add_action( 'gravityview/metaboxes/delete_entry', array( $this, 'view_settings_delete_entry_metabox' ), 7 );
	}

	/**
	 * Return the instantiated class object
	 *
	 * @since  1.5.1
	 * @return GravityView_Delete_Entry
	 */
	static function getInstance() {

		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hide the field or not.
	 *
	 * For non-logged in users.
	 * For users that have no delete rights on any of the current entries.
	 *
	 * @param bool $visible Visible or not.
	 * @param \GV\Field $field The field.
	 * @param \GV\View $view The View context.
	 *
	 * @return bool
	 */
	public function maybe_not_visible( $visible, $field, $view ) {
		if ( 'delete_link' !== $field->ID ) {
			return $visible;
		}

		if ( ! $view ) {
			return $visible;
		}

		static $visibility_cache_for_view = array();

		if ( ! is_null( $result = \GV\Utils::get( $visibility_cache_for_view, $view->ID, null ) ) ) {
			return $result;
		}

		foreach ( $view->get_entries()->all() as $entry ) {
			if ( self::check_user_cap_delete_entry( $entry->as_entry(), $field->as_configuration(), $view ) ) {
				// At least one entry is deletable for this user
				$visibility_cache_for_view[ $view->ID ] = true;
				return true;
			}
		}

		$visibility_cache_for_view[ $view->ID ] = false;

		return false;
	}

	/**
	 * Include this extension templates path
	 *
	 * @since  1.5.1
	 * @param array $file_paths List of template paths ordered
	 */
	function add_template_path( $file_paths ) {

		// Index 100 is the default GravityView template path.
		// Index 110 is Edit Entry link
		$file_paths[115] = self::$file;

		return $file_paths;
	}

	/**
	 * Add "Delete Link Text" setting to the edit_link field settings
	 *
	 * @since 1.5.1
	 *
	 * @param array  $field_options
	 * @param string $template_id
	 * @param string $field_id
	 * @param string $context
	 * @param string $input_type
	 *
	 * @return array $field_options, with "Delete Link Text" and "Allow the following users to delete the entry:" field options.
	 */
	function delete_link_field_options( $field_options, $template_id, $field_id, $context, $input_type ) {

		// Always a link, never a filter
		unset( $field_options['show_as_link'], $field_options['search_filter'] );

		// Delete Entry link should only appear to visitors capable of editing entries
		unset( $field_options['only_loggedin'], $field_options['only_loggedin_cap'] );

		$add_option['delete_link'] = array(
			'type' => 'text',
			'label' => __( 'Delete Link Text', 'gravityview' ),
			'desc' => null,
			'value' => __( 'Delete Entry', 'gravityview' ),
			'merge_tags' => true,
		);

		$field_options['allow_edit_cap'] = array(
			'type' => 'select',
			'label' => __( 'Allow the following users to delete the entry:', 'gravityview' ),
			'choices' => GravityView_Render_Settings::get_cap_choices( $template_id, $field_id, $context, $input_type ),
			'tooltip' => 'allow_edit_cap',
			'class' => 'widefat',
			'value' => 'read', // Default: entry creator
		);

		return array_merge( $add_option, $field_options );
	}


	/**
	 * Add Edit Link as a default field, outside those set in the Gravity Form form
	 *
	 * @since 1.5.1
	 * @param array $entry_default_fields Existing fields
	 * @param  string|array $form form_ID or form object
	 * @param  string $zone   Either 'single', 'directory', 'edit', 'header', 'footer'
	 */
	function add_default_field( $entry_default_fields, $form = array(), $zone = '' ) {

		if ( 'edit' !== $zone ) {
			$entry_default_fields['delete_link'] = array(
				'label' => __( 'Delete Entry', 'gravityview' ),
				'type'  => 'delete_link',
				'desc'  => __( 'A link to delete the entry. Respects the Delete Entry permissions.', 'gravityview' ),
				'icon' => 'dashicons-trash',
			);
		}

		return $entry_default_fields;
	}

	/**
	 * Add Delete Entry Link to the Add Field dialog
	 * @since 1.5.1
	 * @param array $available_fields
	 */
	function add_available_field( $available_fields = array() ) {

		$available_fields['delete_link'] = array(
			'label_text' => __( 'Delete Entry', 'gravityview' ),
			'field_id' => 'delete_link',
			'label_type' => 'field',
			'input_type' => 'delete_link',
			'field_options' => null,
			'icon' => 'dashicons-trash',
		);

		return $available_fields;
	}

	/**
	 * Render Delete Entry Permissions settings
	 *
	 * @since 2.9
	 *
	 * @param $current_settings
	 *
	 * @return void
	 */
	public function view_settings_delete_entry_metabox( $current_settings ) {

		GravityView_Render_Settings::render_setting_row( 'user_delete', $current_settings );

	}

	/**
	 * Change wording for the Edit context to read Entry Creator
	 *
	 * @since 1.5.1
	 * @param  array       $visibility_caps        Array of capabilities to display in field dropdown.
	 * @param  string      $field_type  Type of field options to render (`field` or `widget`)
	 * @param  string      $template_id Table slug
	 * @param  float       $field_id    GF Field ID - Example: `3`, `5.2`, `entry_link`, `created_by`
	 * @param  string      $context     What context are we in? Example: `single` or `directory`
	 * @param  string      $input_type  (textarea, list, select, etc.)
	 * @return array                   Array of field options with `label`, `value`, `type`, `default` keys
	 */
	public function modify_visibility_caps( $visibility_caps = array(), $template_id = '', $field_id = '', $context = '', $input_type = '' ) {

		$caps = $visibility_caps;

		// If we're configuring fields in the edit context, we want a limited selection
		if ( $field_id === 'delete_link' ) {

			// Remove other built-in caps.
			unset( $caps['publish_posts'], $caps['gravityforms_view_entries'], $caps['delete_others_posts'] );

			$caps['read'] = _x( 'Entry Creator', 'User capability', 'gravityview' );
		}

		return $caps;
	}

	/**
	 * Make sure there's an entry
	 *
	 * @since 1.5.1
	 * @param [type] $entry [description]
	 */
	function set_entry( $entry = null ) {
		_deprecated_function( __METHOD__, '2.9.2' );
	}

	/**
	 * Generate a consistent nonce key based on the Entry ID
	 *
	 * @since 1.5.1
	 * @param  int $entry_id Entry ID
	 * @return string           Key used to validate request
	 */
	public static function get_nonce_key( $entry_id ) {
		return sprintf( 'delete_%s', $entry_id );
	}


	/**
	 * Generate a nonce link with the base URL of the current View embed
	 *
	 * We don't want to link to the single entry, because when deleted, there would be nothing to return to.
	 *
	 * @since 1.5.1
	 * @param  array       $entry Gravity Forms entry array
	 * @param  int         $view_id The View id. Not optional since 2.0
	 * @return string|null If directory link is valid, the URL to process the delete request. Otherwise, `NULL`.
	 */
	public static function get_delete_link( $entry, $view_id = 0, $post_id = null ) {
		if ( ! $view_id ) {
			/** @deprecated path */
			$view_id = gravityview_get_view_id();
		}
		$base = GravityView_API::directory_link( $post_id ? : $view_id, true );

		if ( empty( $base ) ) {
			gravityview()->log->error( 'Post ID does not exist: {post_id}', array( 'post_id' => $post_id ) );
			return null;
		}

		$gv_entry = \GV\GF_Entry::from_entry( $entry );

		// Use the slug instead of the ID for consistent security
		$entry_slug = $gv_entry->get_slug();

		$actionurl = add_query_arg(
			array(
				'action'    => 'delete',
				'entry_id'      => $entry_slug,
				'gvid' => $view_id,
				'view_id' => $view_id,
			),
			$base
		);

		$url = wp_nonce_url( $actionurl, 'delete_' . $entry_slug, 'delete' );

		return $url;
	}


	/**
	 * Add a Delete button to the "#publishing-action" section of the Delete Entry form
	 *
	 * @since 1.5.1
	 * @since 2.0.13 Added $post_id
	 *
	 * @param array $form    Gravity Forms form array
	 * @param array $entry   Gravity Forms entry array
	 * @param int $view_id GravityView View ID
	 * @param int $post_id Current post ID. May be same as View ID.
	 *
	 * @return void
	 */
	public function add_delete_button( $form = array(), $entry = array(), $view_id = null, $post_id = null ) {

		// Only show the link to those who are allowed to see it.
		if ( ! self::check_user_cap_delete_entry( $entry, array(), $view_id ) ) {
			return;
		}

		/**
		 * @filter `gravityview/delete-entry/show-delete-button` Should the Delete button be shown in the Edit Entry screen?
		 * @param boolean $show_entry Default: true
		 */
		$show_delete_button = apply_filters( 'gravityview/delete-entry/show-delete-button', true );

		// If the button is hidden by the filter, don't show.
		if ( ! $show_delete_button ) {
			return;
		}

		$attributes = array(
			'class' => 'btn btn-sm button button-small alignright pull-right btn-danger gv-button-delete',
			'tabindex' => ( GFCommon::$tab_index ++ ),
			'onclick' => self::get_confirm_dialog(),
		);

		echo gravityview_get_link( self::get_delete_link( $entry, $view_id, $post_id ), esc_attr__( 'Delete', 'gravityview' ), $attributes );

	}

	/**
	 * Handle the deletion request, if $_GET['action'] is set to "delete"
	 *
	 * 1. Check referrer validity
	 * 2. Make sure there's an entry with the slug of $_GET['entry_id']
	 * 3. If so, attempt to delete the entry. If not, set the error status
	 * 4. Remove `action=delete` from the URL
	 * 5. Redirect to the page using `wp_redirect()`
	 *
	 * @since 1.5.1
	 * @uses wp_redirect()
	 * @return void
	 */
	public function process_delete() {

		/* Unslash and Parse $_GET array. */
		$get_fields = wp_parse_args(
			wp_unslash( $_GET ),
			array(
				'action'   => '',
				'entry_id' => '',
				'gvid'     => '',
				'view_id'  => '',
				'delete'   => '',
			)
		);

		// If the form is not submitted, return early
		if ( 'delete' !== $get_fields['action'] || empty( $get_fields['entry_id'] ) ) {
			return;
		}

		// Make sure it's a GravityView request
		$valid_nonce_key = wp_verify_nonce( $get_fields['delete'], self::get_nonce_key( $get_fields['entry_id'] ) );

		if ( ! $valid_nonce_key ) {
			gravityview()->log->debug( 'Delete entry not processed: nonce validation failed.' );
			return;
		}

		// Get the entry slug
		$entry_slug = esc_attr( $get_fields['entry_id'] );

		// See if there's an entry there
		$entry = gravityview_get_entry( $entry_slug, true, false );

		if ( $entry ) {

			$has_permission = $this->user_can_delete_entry( $entry, \GV\Utils::_GET( 'gvid', \GV\Utils::_GET( 'view_id' ) ) );

			if ( is_wp_error( $has_permission ) ) {

				$messages = array(
					'message' => urlencode( $has_permission->get_error_message() ),
					'status' => 'error',
				);

			} else {

				// Delete the entry
				$delete_response = $this->delete_or_trash_entry( $entry );

				if ( is_wp_error( $delete_response ) ) {

					$messages = array(
						'message' => urlencode( $delete_response->get_error_message() ),
						'status' => 'error',
					);

				} else {
		if ( (int) $view->settings->get( 'delete_redirect' ) === self::REDIRECT_TO_URL_VALUE ) {

					$messages = array(
						'status' => $delete_response,
					);

				}
			}
		} else {

			gravityview()->log->debug( 'Delete entry failed: there was no entry with the entry slug {entry_slug}', array( 'entry_slug' => $entry_slug ) );

			$messages = array(
				'message' => urlencode( __( 'The entry does not exist.', 'gravityview' ) ),
				'status' => 'error',
			);
		}

		// Redirect after deleting the entry.
		$view                = \GV\View::by_id( $get_fields['view_id'] );
		$delete_redirect     = $view->settings->get( 'delete_redirect' );
		$delete_redirect_url = $view->settings->get( 'delete_redirect_url' );

			$delete_redirect_url = get_post_permalink( $get_fields['view_id'] );
		}

		wp_redirect( $delete_redirect_url );

		exit();

	}

	/**
	 * Delete mode: permanently delete, or move to trash?
	 *
	 * @return string `delete` or `trash`
	 */
	private function get_delete_mode() {

		/**
		 * @filter `gravityview/delete-entry/mode` Delete mode: permanently delete, or move to trash?
		 * @since 1.13.1
		 * @param string $delete_mode Delete mode: `trash` or `delete`. Default: `delete`
		 */
		$delete_mode = apply_filters( 'gravityview/delete-entry/mode', 'delete' );

		return ( 'trash' === $delete_mode ) ? 'trash' : 'delete';
	}

	/**
	 * @since 1.13.1
	 * @see GFAPI::delete_entry()
	 * @return WP_Error|boolean GFAPI::delete_entry() returns a WP_Error on error
	 */
	private function delete_or_trash_entry( $entry ) {

		$entry_id = $entry['id'];

		$mode = $this->get_delete_mode();

		if ( 'delete' === $mode ) {

			gravityview()->log->debug( 'Starting delete entry: {entry_id}', array( 'entry_id' => $entry_id ) );

			// Delete the entry
			$delete_response = GFAPI::delete_entry( $entry_id );

			if ( ! is_wp_error( $delete_response ) ) {
				$delete_response = 'deleted';

				/**
				 * @action `gravityview/delete-entry/deleted` Triggered when an entry is deleted
				 * @since 1.16.4
				 * @param  int $entry_id ID of the Gravity Forms entry
				 * @param  array $entry Deleted entry array
				*/
				do_action( 'gravityview/delete-entry/deleted', $entry_id, $entry );
			}

			gravityview()->log->debug( 'Delete response: {delete_response}', array( 'delete_response' => $delete_response ) );

		} else {

			gravityview()->log->debug( 'Starting trash entry: {entry_id}', array( 'entry_id' => $entry_id ) );

			$trashed = GFAPI::update_entry_property( $entry_id, 'status', 'trash' );
			new GravityView_Cache();

			if ( ! $trashed ) {
				$delete_response = new WP_Error( 'trash_entry_failed', __( 'Moving the entry to the trash failed.', 'gravityview' ) );
			} else {

				/**
				 * @action `gravityview/delete-entry/trashed` Triggered when an entry is trashed
				 * @since 1.16.4
				 * @param  int $entry_id ID of the Gravity Forms entry
				 * @param  array $entry Deleted entry array
				 */
				do_action( 'gravityview/delete-entry/trashed', $entry_id, $entry );

				$delete_response = 'trashed';
			}

			gravityview()->log->debug( ' Trashed? {delete_response}', array( 'delete_response' => $delete_response ) );
		}

		return $delete_response;
	}

	/**
	 * Delete or trash a post connected to an entry
	 *
	 * @since 1.17
	 *
	 * @param int $entry_id ID of entry being deleted/trashed
	 * @param array $entry Array of the entry being deleted/trashed
	 */
	public function process_connected_posts( $entry_id = 0, $entry = array() ) {

		// The entry had no connected post
		if ( empty( $entry['post_id'] ) ) {
			return;
		}

		/**
		 * @filter `gravityview/delete-entry/delete-connected-post` Should posts connected to an entry be deleted when the entry is deleted?
		 * @since 1.17
		 * @param boolean $delete_post If trashing an entry, trash the post. If deleting an entry, delete the post. Default: true
		 */
		$delete_post = apply_filters( 'gravityview/delete-entry/delete-connected-post', true );

		if ( false === $delete_post ) {
			return;
		}

		$action = current_action();

		if ( 'gravityview/delete-entry/deleted' === $action ) {
			$result = wp_delete_post( $entry['post_id'], true );
		} else {
			$result = wp_trash_post( $entry['post_id'] );
		}

		if ( false === $result ) {
			gravityview()->log->error(
				'(called by {action}): Error processing the Post connected to the entry.',
				array(
					'action' => $action,
					'data' => $entry,
				)
			);
		} else {
			gravityview()->log->debug(
				'(called by {action}): Successfully processed Post connected to the entry.',
				array(
					'action' => $action,
					'data' => $entry,
				)
			);
		}
	}

	/**
	 * Is the current nonce valid for editing the entry?
	 *
	 * @since 1.5.1
	 * @return boolean
	 */
	public function verify_nonce() {

		// No delete entry request was made
		if ( empty( $_GET['entry_id'] ) || empty( $_GET['delete'] ) ) {
			return false;
		}

		$nonce_key = self::get_nonce_key( $_GET['entry_id'] );

		$valid = wp_verify_nonce( $_GET['delete'], $nonce_key );

		/**
		 * @filter `gravityview/delete-entry/verify_nonce` Override Delete Entry nonce validation. Return true to declare nonce valid.
		 * @since 1.15.2
		 * @see wp_verify_nonce()
		 * @param int|boolean $valid False if invalid; 1 or 2 when nonce was generated
		 * @param string $nonce_key Name of nonce action used in wp_verify_nonce. $_GET['delete'] holds the nonce value itself. Default: `delete_{entry_id}`
		 */
		$valid = apply_filters( 'gravityview/delete-entry/verify_nonce', $valid, $nonce_key );

		return $valid;
	}

	/**
	 * Get the onclick attribute for the confirm dialogs that warns users before they delete an entry
	 *
	 * @since 1.5.1
	 * @return string HTML `onclick` attribute
	 */
	public static function get_confirm_dialog() {

		$confirm = __( 'Are you sure you want to delete this entry? This cannot be undone.', 'gravityview' );

		/**
		 * @filter `gravityview/delete-entry/confirm-text` Modify the Delete Entry Javascript confirmation text
		 * @param string $confirm Default: "Are you sure you want to delete this entry? This cannot be undone."
		 */
		$confirm = apply_filters( 'gravityview/delete-entry/confirm-text', $confirm );

		return 'return window.confirm(\'' . esc_js( $confirm ) . '\');';
	}

	/**
	 * Check if the user can edit the entry
	 *
	 * - Is the nonce valid?
	 * - Does the user have the right caps for the entry
	 * - Is the entry in the trash?
	 *
	 * @since 1.5.1
	 * @param  array $entry Gravity Forms entry array
	 * @return boolean|WP_Error        True: can edit form. WP_Error: nope.
	 */
	function user_can_delete_entry( $entry = array(), $view_id = null ) {

		$error = null;

		if ( ! $this->verify_nonce() ) {
			$error = __( 'The link to delete this entry is not valid; it may have expired.', 'gravityview' );
		}

		if ( ! self::check_user_cap_delete_entry( $entry, array(), $view_id ) ) {
			$error = __( 'You do not have permission to delete this entry.', 'gravityview' );
		}

		if ( $entry['status'] === 'trash' ) {
			if ( 'trash' === $this->get_delete_mode() ) {
				$error = __( 'The entry is already in the trash.', 'gravityview' );
			} else {
				$error = __( 'You cannot delete the entry; it is already in the trash.', 'gravityview' );
			}
		}

		// No errors; everything's fine here!
		if ( empty( $error ) ) {
			return true;
		}

		gravityview()->log->error( '{error}', array( 'erorr' => $error ) );

		return new WP_Error( 'gravityview-delete-entry-permissions', $error );
	}


	/**
	 * checks if user has permissions to view the link or delete a specific entry
	 *
	 * @since 1.5.1
	 * @since 1.15 Added `$view_id` param
	 *
	 * @param  array $entry Gravity Forms entry array
	 * @param array $field Field settings (optional)
	 * @param int|\GV\View $view Pass a View ID to check caps against. If not set, check against current View (@deprecated no longer optional)
	 * @return bool
	 */
	public static function check_user_cap_delete_entry( $entry, $field = array(), $view = 0 ) {
		if ( ! $view ) {
			/** @deprecated path */
			$view_id = GravityView_View::getInstance()->getViewId();
			$view = \GV\View::by_id( $view_id );
		} else {
			if ( ! $view instanceof \GV\View ) {
				$view = \GV\View::by_id( $view );
			}
			$view_id = $view->ID;
		}

		$current_user = wp_get_current_user();

		$entry_id = isset( $entry['id'] ) ? $entry['id'] : null;

		// Or if they can delete any entries (as defined in Gravity Forms), we're good.
		if ( GVCommon::has_cap( array( 'gravityforms_delete_entries', 'gravityview_delete_others_entries' ), $entry_id ) ) {

			gravityview()->log->debug( 'Current user has `gravityforms_delete_entries` or `gravityview_delete_others_entries` capability.' );

			return true;
		}

		// If field options are passed, check if current user can view the link
		if ( ! empty( $field ) ) {

			// If capability is not defined, something is not right!
			if ( empty( $field['allow_edit_cap'] ) ) {

				gravityview()->log->error( 'Cannot read delete entry field caps', array( 'data' => $field ) );

				return false;
			}

			if ( GVCommon::has_cap( $field['allow_edit_cap'] ) ) {

				// Do not return true if cap is read, as we need to check if the current user created the entry
				if ( $field['allow_edit_cap'] !== 'read' ) {
					return true;
				}
			} else {

				gravityview()->log->debug( 'User {user_id} is not authorized to view delete entry link ', array( 'user_id' => $current_user->ID ) );

				return false;
			}
		}

		if ( ! isset( $entry['created_by'] ) ) {

			gravityview()->log->error( 'Entry `created_by` doesn\'t exist.' );

			return false;
		}

		$user_delete = $view->settings->get( 'user_delete' );

		// Only checks user_delete view option if view is already set
		if ( $view && empty( $user_delete ) ) {
			gravityview()->log->debug( 'User Delete is disabled. Returning false.' );
			return false;
		}

		// If the logged-in user is the same as the user who created the entry, we're good.
		if ( is_user_logged_in() && intval( $current_user->ID ) === intval( $entry['created_by'] ) ) {

			gravityview()->log->debug( 'User {user_id} created the entry.', array( 'user_id' => $current_user->ID ) );

			return true;
		}

		return false;
	}


	/**
	 * After processing delete entry, the user will be redirected to the referring View or embedded post/page. Display a message on redirection.
	 *
	 * If success, there will be `status` URL parameters `status=>success`
	 * If an error, there will be `status` and `message` URL parameters `status=>error&message=example`
	 *
	 * @since 1.15.2 Only show message when the URL parameter's View ID matches the current View ID
	 * @since 1.5.1
	 *
	 * @param int $current_view_id The ID of the View being rendered
	 * @return void
	 */
	public function maybe_display_message( $current_view_id = 0 ) {
		if ( empty( $_GET['status'] ) || ! self::verify_nonce() ) {
			return;
		}

		// Entry wasn't deleted from current View
		if ( isset( $_GET['view_id'] ) && intval( $_GET['view_id'] ) !== intval( $current_view_id ) ) {
			return;
		}

		$this->display_message();
	}

	public function display_message() {

		if ( empty( $_GET['status'] ) || empty( $_GET['delete'] ) ) {
			return;
		}

		$status = esc_attr( $_GET['status'] );
		$message_from_url = \GV\Utils::_GET( 'message' );
		$message_from_url = rawurldecode( stripslashes_deep( $message_from_url ) );
		$class = '';

		switch ( $status ) {
			case 'error':
				$class = ' gv-error error';
				$error_message = __( 'There was an error deleting the entry: %s', 'gravityview' );
				$message = sprintf( $error_message, $message_from_url );
				break;
			case 'trashed':
				$message = __( 'The entry was successfully moved to the trash.', 'gravityview' );
				break;
			default:
				$message = __( 'The entry was successfully deleted.', 'gravityview' );
				break;
		}

		/**
		 * @filter `gravityview/delete-entry/message` Modify the Delete Entry messages
		 * @since 1.13.1
		 * @param string $message Message to be displayed
		 * @param string $status Message status (`error` or `success`)
		 * @param string $message_from_url The original error message, if any, without the "There was an error deleting the entry:" prefix
		 */
		$message = apply_filters( 'gravityview/delete-entry/message', esc_attr( $message ), $status, $message_from_url );

		// DISPLAY ERROR/SUCCESS MESSAGE
		echo '<div class="gv-notice' . esc_attr( $class ) . '">' . $message . '</div>';
	}


} // end class

GravityView_Delete_Entry::getInstance();

