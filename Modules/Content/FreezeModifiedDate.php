<?php

/**
 * Freeze Modified Date feature
 *
 * Prevents the post modified date from being updated when the freeze option is enabled.
 *
 * Uses a multi-layer strategy inspired by SEOPress:
 *
 * 1. Preventive: modifies post data in wp_insert_post_data (priority 9999) to preserve
 *    the original date before WordPress writes to the database.
 * 2. Backup capture: pre_post_update captures original dates as a safety net.
 * 3. Corrective: wp_after_insert_post (priority 9999) restores dates via $wpdb->update()
 *    after all save handlers have completed, catching any edge cases.
 * 4. Cross-request: uses a short-lived transient to bridge Gutenberg's two-phase save
 *    (REST API save + separate metabox POST). The REST save captures pre-save dates
 *    into a transient; the metabox POST recovers them when freeze is enabled.
 *
 * @package SEOPulse\Modules\Content
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPulse\Core\Contracts\ExecuteHooks;

/**
 * FreezeModifiedDate class
 */
class FreezeModifiedDate implements ExecuteHooks {

	/**
	 * Meta key used to store the freeze state.
	 *
	 * @var string
	 */
	public const META_KEY = '_freeze_modified_date';

	/**
	 * Nonce action for the metabox field.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'seopulse_freeze_modified_date';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	private const NONCE_NAME = 'seopulse_freeze_modified_date_nonce';

	/**
	 * Transient prefix for storing pre-save dates across HTTP requests.
	 *
	 * Gutenberg fires two separate HTTP requests when classic metaboxes are present:
	 *   1. REST API save (post content + REST-registered meta)
	 *   2. Metabox POST (classic metabox form data)
	 *
	 * The transient bridges these two requests so that the original dates captured
	 * during Request 1 are available during Request 2.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = '_seopulse_freeze_dates_';

	/**
	 * Stores original dates captured before a post update.
	 *
	 * Indexed by post ID to support multiple post saves in the same request
	 * (e.g., WooCommerce saving related products).
	 *
	 * @var array<int, array{post_modified: string, post_modified_gmt: string}>
	 */
	private array $original_dates = array();

	/**
	 * Registers all WordPress hooks.
	 *
	 * Uses ExecuteHooks (not ExecuteHooksAdmin) because the
	 * wp_insert_post_data filter must fire in all contexts
	 * (REST API, WP-CLI, imports, cron, etc.).
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_metabox_field' ) );
		add_action( 'save_post', array( $this, 'save_freeze_meta' ), 10, 2 );

		// Preventive layer: capture original dates AND preserve them in post data.
		// Priority 9999 ensures this runs last, making our date preservation the
		// final modification before WordPress writes to the database.
		add_filter( 'wp_insert_post_data', array( $this, 'maybe_preserve_modified_date' ), 9999, 4 );

		// Backup capture just before the DB write. pre_post_update fires after
		// wp_insert_post_data but before $wpdb->update(), giving us one last
		// chance to capture original dates from the database.
		add_action( 'pre_post_update', array( $this, 'capture_pre_update_date' ), 10, 2 );

		// Corrective layer: restore dates after the post AND meta are both saved.
		// Handles edge cases where something modifies dates after our filter.
		// If WooCommerce already handled the restore, original_dates will be unset.
		add_action( 'wp_after_insert_post', array( $this, 'maybe_restore_date' ), 9999, 4 );

		// WooCommerce CRUD bypasses wp_insert_post_data when doing_action('save_post')
		// is true, using $wpdb->update() directly. We need WC-specific hooks.
		add_action( 'woocommerce_before_product_object_save', array( $this, 'capture_wc_original_date' ), 10 );
		add_action( 'woocommerce_update_product', array( $this, 'maybe_restore_wc_date' ), 10 );
	}

	/**
	 * Returns all public post types that support the freeze feature.
	 *
	 * @return string[]
	 */
	private function get_public_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		// Remove attachment — it does not support standard editing.
		unset( $post_types['attachment'] );

		/**
		 * Filters the post types that support the freeze modified date feature.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $post_types Array of post type slugs.
		 */
		return (array) apply_filters( 'seopulse_freeze_modified_date_post_types', array_values( $post_types ) );
	}

	/**
	 * Registers the post meta for all supported post types.
	 *
	 * Uses register_post_meta() so the field is available in
	 * the REST API and the Block Editor natively.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$post_types = $this->get_public_post_types();

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'single'            => true,
					'type'              => 'boolean',
					'default'           => false,
					'show_in_rest'      => true,
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
					'sanitize_callback' => static function ( $value ): bool {
						return (bool) $value;
					},
				),
			);
		}
	}

	/**
	 * Registers a lightweight sidebar metabox for the freeze toggle.
	 *
	 * @return void
	 */
	public function register_metabox_field(): void {
		$post_types = $this->get_public_post_types();

		add_meta_box(
			'seopulse-freeze-modified-date',
			__( 'SEOPulse — Modified Date', 'seopulse' ),
			array( $this, 'render_freeze_field' ),
			$post_types,
			'side',
			'default',
		);
	}

	/**
	 * Renders the freeze checkbox field inside the metabox.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_freeze_field( \WP_Post $post ): void {
		$frozen = (bool) get_post_meta( $post->ID, self::META_KEY, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
<fieldset>
	<label for="seopulse-freeze-modified-date" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
		<input type="checkbox" id="seopulse-freeze-modified-date"
			name="<?php echo esc_attr( self::META_KEY ); ?>"
			value="1" <?php checked( $frozen ); ?>
		/>
		<span><?php esc_html_e( 'Freeze modified date', 'seopulse' ); ?></span>
	</label>
	<p class="description" style="margin-top:6px;">
		<?php esc_html_e( 'Prevents WordPress from automatically updating the modified date when saving.', 'seopulse' ); ?>
	</p>
</fieldset>
		<?php
	}

	/**
	 * Saves the freeze meta value on post save (classic editor only).
	 *
	 * REST API saves are handled natively by register_post_meta's show_in_rest.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function save_freeze_meta( int $post_id, \WP_Post $post ): void {
		// Skip autosaves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Nonce not present means this save did not originate from our form.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		// Verify nonce.
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// Verify permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Sanitize and save. When unchecked, checkbox is absent from $_POST.
		$value = ! empty( $_POST[ self::META_KEY ] );
		update_post_meta( $post_id, self::META_KEY, $value );
	}

	/**
	 * Capture original dates and preserve them in the post data being written.
	 *
	 * This filter both captures the original modified date from the database AND
	 * modifies the data array to keep the original date, preventing WordPress from
	 * setting post_modified to the current time.
	 *
	 * For classic editor: checks $_POST for the freeze checkbox value, since the
	 * metabox save handler runs later on save_post and the DB meta value is stale.
	 *
	 * For block editor / REST API: checks post meta (saved via REST before post save).
	 *
	 * Gutenberg two-phase save handling:
	 *   - Request 1 (REST): freeze may NOT be enabled (checkbox in classic metabox).
	 *     We save original dates to a transient so Request 2 can recover them.
	 *   - Request 2 (Metabox POST): freeze IS enabled (our nonce is present in $_POST).
	 *     We recover the true pre-save dates from the transient (because get_post()
	 *     would return dates already updated by Request 1).
	 *
	 * @param array $data Slashed post data being saved.
	 * @param array $postarr Raw post data.
	 * @param array $unsanitized_postarr Unsanitized post data.
	 * @param bool  $update Whether this is an update.
	 * @return array Post data, with post_modified preserved if freeze is enabled.
	 */
	public function maybe_preserve_modified_date( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		if ( ! $update ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;

		if ( ! $post_id ) {
			return $data;
		}

		// Capture original dates if not already captured.
		// Skips re-capture on nested wp_update_post() calls, preserving the true
		// original dates from before the first write.
		if ( ! isset( $this->original_dates[ $post_id ] ) ) {
			$original_post = get_post( $post_id );

			if ( $original_post instanceof \WP_Post ) {
				$this->original_dates[ $post_id ] = array(
					'post_modified'     => $original_post->post_modified,
					'post_modified_gmt' => $original_post->post_modified_gmt,
				);
			}
		}

		if ( ! isset( $this->original_dates[ $post_id ] ) ) {
			return $data;
		}

		$is_frozen = $this->is_freeze_enabled( $post_id );

		// In REST requests (Gutenberg Request 1), save pre-save dates to a transient.
		// A subsequent metabox POST (Request 2) may need them if the user checked
		// the freeze checkbox in the classic metabox.
		if ( ! $is_frozen && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			set_transient(
				self::TRANSIENT_PREFIX . $post_id,
				$this->original_dates[ $post_id ],
				30,
			);
		}

		if ( ! $is_frozen ) {
			return $data;
		}

		// In non-REST context (classic metabox Request 2), the dates from get_post()
		// may already be stale (updated by Gutenberg's REST save in Request 1).
		// Recover the true pre-save dates from the transient if available.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			$transient_dates = get_transient( self::TRANSIENT_PREFIX . $post_id );

			if ( is_array( $transient_dates ) ) {
				$this->original_dates[ $post_id ] = $transient_dates;
				delete_transient( self::TRANSIENT_PREFIX . $post_id );
			}
		}

		// Preserve the original modified dates in the data being written to the DB.
		$data['post_modified']     = $this->original_dates[ $post_id ]['post_modified'];
		$data['post_modified_gmt'] = $this->original_dates[ $post_id ]['post_modified_gmt'];

		return $data;
	}

	/**
	 * Backup capture of original dates just before the database write.
	 *
	 * pre_post_update fires after wp_insert_post_data but immediately before
	 * $wpdb->update(). If the dates were not captured by maybe_preserve_modified_date()
	 * for any reason, this ensures we still have them before they are overwritten.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data Array of unslashed post data about to be written.
	 * @return void
	 */
	public function capture_pre_update_date( int $post_id, array $data ): void {
		$post_id = absint( $post_id );

		if ( ! $post_id || isset( $this->original_dates[ $post_id ] ) ) {
			return;
		}

		$original_post = get_post( $post_id );

		if ( $original_post instanceof \WP_Post ) {
			$this->original_dates[ $post_id ] = array(
				'post_modified'     => $original_post->post_modified,
				'post_modified_gmt' => $original_post->post_modified_gmt,
			);
		}
	}

	/**
	 * Restore the original modified date after the post and meta are both saved.
	 *
	 * Acts as a safety net. wp_after_insert_post fires after wp_insert_post AND
	 * after all save_post handlers (including meta saves) have completed.
	 *
	 * This catches edge cases where something modifies dates after our
	 * wp_insert_post_data filter ran.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an update.
	 * @param \WP_Post $post_before Post object before the update (WP 6.0+, nullable).
	 * @return void
	 */
	public function maybe_restore_date( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( ! $update || ! isset( $this->original_dates[ $post_id ] ) ) {
			return;
		}

		$is_frozen = $this->is_freeze_enabled( $post_id );

		if ( ! $is_frozen ) {
			// In REST context, save dates to transient before discarding.
			// A subsequent metabox POST may need them.
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				set_transient(
					self::TRANSIENT_PREFIX . $post_id,
					$this->original_dates[ $post_id ],
					30,
				);
			}

			unset( $this->original_dates[ $post_id ] );

			return;
		}

		// In non-REST context, recover true pre-save dates from transient
		// (set during Gutenberg's REST save in Request 1).
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			$transient_dates = get_transient( self::TRANSIENT_PREFIX . $post_id );

			if ( is_array( $transient_dates ) ) {
				$this->original_dates[ $post_id ] = $transient_dates;
				delete_transient( self::TRANSIENT_PREFIX . $post_id );
			}
		}

		$frozen = $this->original_dates[ $post_id ];

		global $wpdb;

		// Use direct $wpdb->update() to bypass wp_update_post() which would
		// trigger wp_insert_post_data again causing an infinite loop.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $frozen['post_modified'],
				'post_modified_gmt' => $frozen['post_modified_gmt'],
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' ),
		);

		clean_post_cache( $post_id );
		unset( $this->original_dates[ $post_id ] );
	}

	/**
	 * Capture original date before WooCommerce saves a product.
	 *
	 * WooCommerce CRUD bypasses wp_insert_post_data entirely when
	 * doing_action('save_post') is true. This hook captures the dates
	 * for pure CRUD saves that happen outside of wp_insert_post().
	 *
	 * @param object $product The WooCommerce product object (\WC_Product).
	 * @return void
	 */
	public function capture_wc_original_date( object $product ): void {
		$post_id = $product->get_id();

		if ( ! $post_id || isset( $this->original_dates[ $post_id ] ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$this->original_dates[ $post_id ] = array(
				'post_modified'     => $post->post_modified,
				'post_modified_gmt' => $post->post_modified_gmt,
			);
		}
	}

	/**
	 * Restore the frozen modified date after WooCommerce saves a product.
	 *
	 * WooCommerce data store uses $wpdb->update() directly when
	 * doing_action('save_post'), bypassing our wp_insert_post_data filter.
	 * This hook runs after WC has written its changes and restores the frozen dates.
	 *
	 * @param int $product_id The product post ID.
	 * @return void
	 */
	public function maybe_restore_wc_date( int $product_id ): void {
		if ( ! isset( $this->original_dates[ $product_id ] ) ) {
			return;
		}

		$is_frozen = $this->is_freeze_enabled( $product_id );

		if ( ! $is_frozen ) {
			unset( $this->original_dates[ $product_id ] );

			return;
		}

		$frozen = $this->original_dates[ $product_id ];

		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $frozen['post_modified'],
				'post_modified_gmt' => $frozen['post_modified_gmt'],
			),
			array( 'ID' => $product_id ),
			array( '%s', '%s' ),
			array( '%d' ),
		);

		clean_post_cache( $product_id );
		unset( $this->original_dates[ $product_id ] );
	}

	/**
	 * Check if the freeze modified date option is enabled for a post.
	 *
	 * For classic editor: reads from $_POST because the metabox save handler
	 * (save_freeze_meta on save_post) may not have fired yet when this is called
	 * from wp_insert_post_data.
	 *
	 * For block editor / REST API: reads from post meta, which was already
	 * saved via the REST API before the post save.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether freeze is enabled.
	 */
	private function is_freeze_enabled( int $post_id ): bool {
		// Classic editor: our nonce is present in $_POST.
		$is_classic_editor = isset( $_POST[ self::NONCE_NAME ] );

		if ( $is_classic_editor ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
			if ( wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return ! empty( $_POST[ self::META_KEY ] );
			}
		}

		// REST API / Block Editor / WP-CLI / imports: read persisted meta.
		$value = get_post_meta( $post_id, self::META_KEY, true );

		/**
		 * Filters whether the modified date should be frozen for a specific post.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $is_frozen Whether the date is frozen.
		 * @param int $post_id Post ID.
		 */
		return (bool) apply_filters( 'seopulse_is_modified_date_frozen', (bool) $value, $post_id );
	}
}
?>