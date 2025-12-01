<?php
namespace WCMF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCMF_Settings {

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_admin_menu() {
		add_options_page(
			__( 'Conditional Media Folder', 'wp-conditional-media-folder' ),
			__( 'Conditional Media Folder', 'wp-conditional-media-folder' ),
			'manage_options',
			'wcmf-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'wcmf_options_group', 'wcmf_custom_path', [
			'sanitize_callback' => [ $this, 'sanitize_path_secure' ]
		] );

		register_setting( 'wcmf_options_group', 'wcmf_custom_url', [
			'sanitize_callback' => [ $this, 'sanitize_url_http' ]
		] );

		register_setting( 'wcmf_options_group', 'wcmf_rules', [
			'sanitize_callback' => [ $this, 'sanitize_rules' ]
		] );
	}

	public function sanitize_url_http( $url ) {
		$url = (string) $url;
		$url = esc_url_raw( trim( $url ) );
		$url = untrailingslashit( $url );

		if ( '' === $url ) {
			return '';
		}

		$scheme = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_SCHEME ) : parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( strtolower( (string) $scheme ), [ 'http', 'https' ], true ) ) {
			add_settings_error( 'wcmf_custom_url', 'wcmf_url_scheme', __( 'Invalid URL: only http and https schemes are supported.', 'wp-conditional-media-folder' ) );
			return get_option( 'wcmf_custom_url' );
		}

		return $url;
	}

	private function is_absolute_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path ) {
			return false;
		}

		// Unix, UNC, or Windows drive letter.
		return (
			0 === strpos( $path, '/' ) ||
			0 === strpos( $path, '//' ) ||
			(bool) preg_match( '#^[a-zA-Z]:/#', $path )
		);
	}

	/**
	 * Sanitizes AND validates system paths.
	 * Adds security checks to prevent overwriting core WordPress folders.
	 */
	public function sanitize_path_secure( $path ) {
		$path = sanitize_text_field( $path );
		$path = wp_normalize_path( trim( $path ) );
		$path = untrailingslashit( $path );

		// Allow clearing the value.
		if ( '' === $path ) {
			return '';
		}

		// Defense-in-depth: block streams/wrappers.
		if ( function_exists( 'wp_is_stream' ) && wp_is_stream( $path ) ) {
			add_settings_error(
				'wcmf_custom_path',
				'wcmf_path_stream',
				__( 'Invalid path: stream wrappers are not allowed.', 'wp-conditional-media-folder' )
			);
			return get_option( 'wcmf_custom_path' );
		}

		// Must be absolute.
		if ( ! $this->is_absolute_path( $path ) ) {
			add_settings_error(
				'wcmf_custom_path',
				'wcmf_path_not_absolute',
				__( 'Invalid path: please enter an absolute server path.', 'wp-conditional-media-folder' )
			);
			return get_option( 'wcmf_custom_path' );
		}

		// No traversal.
		if ( preg_match( '#(^|/)\.\.(?:/|$)#', $path ) ) {
			add_settings_error(
				'wcmf_custom_path',
				'wcmf_path_traversal',
				__( 'Invalid path: directory traversal sequences are not allowed.', 'wp-conditional-media-folder' )
			);
			return get_option( 'wcmf_custom_path' );
		}

		// Prevent Root directory usage or suspiciously short paths
		if ( strlen( $path ) < 2 || $path === '/' || ( isset( $_SERVER['DOCUMENT_ROOT'] ) && $path === wp_normalize_path( $_SERVER['DOCUMENT_ROOT'] ) ) ) {
			add_settings_error(
				'wcmf_custom_path',
				'wcmf_path_root',
				__( 'Security Warning: You cannot use the server root or document root as an upload folder.', 'wp-conditional-media-folder' )
			);
			return get_option( 'wcmf_custom_path' );
		}

		$blocked_exact = [
			wp_normalize_path( ABSPATH ),
			wp_normalize_path( ABSPATH . 'wp-admin' ),
			wp_normalize_path( ABSPATH . 'wp-includes' ),
			wp_normalize_path( WP_CONTENT_DIR ),
		];

		// Block Plugins and Themes directories to prevent overwriting/cluttering
		$blocked_prefix = [
			wp_normalize_path( ABSPATH . 'wp-admin' ),
			wp_normalize_path( ABSPATH . 'wp-includes' ),
			wp_normalize_path( WP_PLUGIN_DIR ),
			wp_normalize_path( get_theme_root() )
		];

		foreach ( $blocked_exact as $blocked ) {
			if ( $path === $blocked ) {
				add_settings_error(
					'wcmf_custom_path',
					'wcmf_path_invalid',
					__( 'Security Warning: You cannot use a core WordPress directory as a custom upload folder.', 'wp-conditional-media-folder' )
				);
				return get_option( 'wcmf_custom_path' );
			}
		}

		foreach ( $blocked_prefix as $blocked ) {
			$blocked = untrailingslashit( $blocked );
			if ( $path === $blocked || 0 === strpos( $path, $blocked . '/' ) ) {
				add_settings_error(
					'wcmf_custom_path',
					'wcmf_path_invalid_prefix',
					__( 'Security Warning: You cannot use core WordPress, Plugin, or Theme directories as a custom upload folder.', 'wp-conditional-media-folder' )
				);
				return get_option( 'wcmf_custom_path' );
			}
		}


		if ( file_exists( $path ) ) {
			if ( ! is_dir( $path ) ) {
				add_settings_error(
					'wcmf_custom_path',
					'wcmf_path_not_dir',
					__( 'Invalid path: the specified location exists but is not a directory.', 'wp-conditional-media-folder' )
				);
				return get_option( 'wcmf_custom_path' );
			}

			if ( ! is_writable( $path ) ) {
				add_settings_error(
					'wcmf_custom_path',
					'wcmf_path_not_writable',
					__( 'Invalid path: directory is not writable by the web server user.', 'wp-conditional-media-folder' )
				);
				return get_option( 'wcmf_custom_path' );
			}
		} else {
			// Check if parent is writable if path doesn't exist yet
			$parent = dirname( $path );
			if ( file_exists( $parent ) && ! is_writable( $parent ) ) {
				add_settings_error(
					'wcmf_custom_path',
					'wcmf_parent_not_writable',
					__( 'Invalid path: The directory does not exist, and the parent directory is not writable, so it cannot be created.', 'wp-conditional-media-folder' )
				);
				return get_option( 'wcmf_custom_path' );
			}

			add_settings_error(
				'wcmf_custom_path',
				'wcmf_path_missing',
				__( 'Note: The directory does not currently exist. It will be created automatically during upload if possible.', 'wp-conditional-media-folder' )
			);
		}

		return $path;
	}

	public function sanitize_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $input as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$starts_with = isset( $rule['starts_with'] ) ? sanitize_text_field( $rule['starts_with'] ) : '';
			$ends_with   = isset( $rule['ends_with'] ) ? sanitize_text_field( $rule['ends_with'] ) : '';
			$contains    = isset( $rule['contains'] ) ? sanitize_text_field( $rule['contains'] ) : '';

			$min_len = ( isset( $rule['min_len'] ) && is_numeric( $rule['min_len'] ) ) ? max( 0, intval( $rule['min_len'] ) ) : '';
			$max_len = ( isset( $rule['max_len'] ) && is_numeric( $rule['max_len'] ) ) ? max( 0, intval( $rule['max_len'] ) ) : '';

			if ( '' !== $min_len ) {
				$min_len = max( 0, $min_len );
			}
			if ( '' !== $max_len ) {
				$max_len = max( 0, $max_len );
			}

			// Normalize inverted ranges only if both are set.
			if ( '' !== $min_len && '' !== $max_len && $min_len > $max_len ) {
				$tmp     = $min_len;
				$min_len = $max_len;
				$max_len = $tmp;
				add_settings_error(
					'wcmf_rules',
					'wcmf_rules_len_invalid',
					__( 'A rule has min length greater than max length. The values have been swapped.', 'wp-conditional-media-folder' )
				);
			}

			// New: sanitize and validate MIME type field.
			$mime_type = isset( $rule['mime_type'] ) ? sanitize_text_field( $rule['mime_type'] ) : '';
			if ( '' !== $mime_type ) {
				$mime_type = strtolower( trim( $mime_type ) );

				// Accept:
				// 1) "type/subtype" -> exact match
				// 2) "type/*"       -> wildcard subtype, normalized as "type/"
				// 3) "type/"        -> prefix match
				// 4) "type"         -> prefix match, normalized as "type/"
				if ( preg_match( '/^([a-z0-9!#$&^_.+-]+)\/\*$/i', $mime_type, $m ) ) {
					// "image/*" => "image/"
					$mime_type = strtolower( $m[1] ) . '/';
				} elseif ( preg_match( '/^([a-z0-9!#$&^_.+-]+)$/i', $mime_type, $m ) ) {
					// "image" => "image/"
					$mime_type = strtolower( $m[1] ) . '/';
				} elseif ( preg_match( '/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]*$/i', $mime_type ) ) {
					// "image/jpeg" or "image/"
					// Already valid, keep as-is.
				} else {
					add_settings_error(
						'wcmf_rules',
						'wcmf_rules_mime_invalid',
						__( 'A rule has an invalid MIME type format. Expected "type/subtype", "type/*", "type/", or "type".', 'wp-conditional-media-folder' )
					);
					$mime_type = '';
				}
			}

			$clean_rule = [
				'starts_with' => $starts_with,
				'ends_with'   => $ends_with,
				'contains'    => $contains,
				'min_len'     => $min_len,
				'max_len'     => $max_len,
				'mime_type'   => $mime_type,
			];

			// Only save if at least one condition is present.
			$has_string_condition = (
				$starts_with !== '' ||
				$ends_with   !== '' ||
				$contains    !== '' ||
				$mime_type   !== ''
			);
			$has_length_condition = (
				( $min_len !== '' && $min_len > 0 ) ||
				( $max_len !== '' && $max_len > 0 )
			);

			if ( $has_string_condition || $has_length_condition ) {
				$sanitized[] = $clean_rule;
			}
		}

		return array_values( $sanitized );
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_wcmf-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wcmf-admin-css', WCMF_PLUGIN_URL . 'assets/css/admin.css', [], WCMF_VERSION );
		wp_enqueue_script( 'wcmf-admin-js', WCMF_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], WCMF_VERSION, true );

		wp_localize_script(
			'wcmf-admin-js',
			'wcmfData',
			[
				'strings' => [
					'remove'  => __( 'Remove Criteria', 'wp-conditional-media-folder' ),
					'confirm' => __( 'Are you sure you want to remove this criteria set?', 'wp-conditional-media-folder' ),
				]
			]
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-conditional-media-folder' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Conditional Media Folder Settings', 'wp-conditional-media-folder' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wcmf_options_group' );
				do_settings_sections( 'wcmf_options_group' );
				?>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Custom Folder Path (Absolute)', 'wp-conditional-media-folder' ); ?></th>
						<td>
							<input type="text" name="wcmf_custom_path" value="<?php echo esc_attr( get_option( 'wcmf_custom_path' ) ); ?>" class="regular-text" style="width: 100%;" />
							<p class="description">
								<?php esc_html_e( 'Enter the absolute server path. Ensure this directory is writable by the web server.', 'wp-conditional-media-folder' ); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Custom Folder URL', 'wp-conditional-media-folder' ); ?></th>
						<td>
							<input type="url" name="wcmf_custom_url" value="<?php echo esc_attr( get_option( 'wcmf_custom_url' ) ); ?>" class="regular-text" style="width: 100%;" />
							<p class="description">
								<?php esc_html_e( 'The full URL to access files in the folder above.', 'wp-conditional-media-folder' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<hr>
				<h2><?php esc_html_e( 'Detection Criteria', 'wp-conditional-media-folder' ); ?></h2>
				<p><?php esc_html_e( 'Files matching ANY of the following criteria sets will be saved to the custom folder.', 'wp-conditional-media-folder' ); ?></p>

				<div id="wcmf-rules-container">
					<?php
					$rules = get_option( 'wcmf_rules', [] );
					if ( empty( $rules ) ) {
						$rules = [ [] ];
					}

					foreach ( $rules as $index => $rule ) :
						$this->render_rule_row( $index, $rule );
					endforeach;
					?>
				</div>

				<div style="margin-top: 20px;">
					<button type="button" id="wcmf-add-rule" class="button button-secondary"><?php esc_html_e( 'Add New Criteria Set', 'wp-conditional-media-folder' ); ?></button>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>

		<script type="text/template" id="wcmf-rule-template">
			<?php $this->render_rule_row( 'INDEX', [] ); ?>
		</script>
		<?php
	}

	private function render_rule_row( $index, $rule ) {
		$starts_with = isset( $rule['starts_with'] ) ? $rule['starts_with'] : '';
		$ends_with   = isset( $rule['ends_with'] ) ? $rule['ends_with'] : '';
		$contains    = isset( $rule['contains'] ) ? $rule['contains'] : '';
		$min_len     = isset( $rule['min_len'] ) ? $rule['min_len'] : '';
		$max_len     = isset( $rule['max_len'] ) ? $rule['max_len'] : '';
		$mime        = isset( $rule['mime_type'] ) ? $rule['mime_type'] : '';

		$is_template   = ( $index === 'INDEX' );
		$display_index = $is_template ? '' : ( is_numeric( $index ) ? $index + 1 : 1 );
		?>
		<div class="wcmf-rule-box" data-index="<?php echo esc_attr( $index ); ?>">
			<h3 class="wcmf-rule-title">
				<?php esc_html_e( 'Criteria Set', 'wp-conditional-media-folder' ); ?> <span class="rule-number"><?php echo esc_html( $display_index ); ?></span>
				<button type="button" class="button-link wcmf-remove-rule" style="float:right; color: #d63638; text-decoration: none;"><?php esc_html_e( 'Remove', 'wp-conditional-media-folder' ); ?></button>
			</h3>
			<div class="wcmf-rule-grid">
				<div class="wcmf-field">
					<label><?php esc_html_e( 'Starts With', 'wp-conditional-media-folder' ); ?></label>
					<input type="text" name="wcmf_rules[<?php echo esc_attr( $index ); ?>][starts_with]" value="<?php echo esc_attr( $starts_with ); ?>" />
				</div>
				<div class="wcmf-field">
					<label><?php esc_html_e( 'Ends With', 'wp-conditional-media-folder' ); ?></label>
					<input type="text" name="wcmf_rules[<?php echo esc_attr( $index ); ?>][ends_with]" value="<?php echo esc_attr( $ends_with ); ?>" />
				</div>
				<div class="wcmf-field">
					<label><?php esc_html_e( 'Contains', 'wp-conditional-media-folder' ); ?></label>
					<input type="text" name="wcmf_rules[<?php echo esc_attr( $index ); ?>][contains]" value="<?php echo esc_attr( $contains ); ?>" />
				</div>
				<div class="wcmf-field">
					<label><?php esc_html_e( 'Min Length', 'wp-conditional-media-folder' ); ?></label>
					<input type="number" name="wcmf_rules[<?php echo esc_attr( $index ); ?>][min_len]" value="<?php echo esc_attr( $min_len ); ?>" min="0" />
				</div>
				<div class="wcmf-field">
					<label><?php esc_html_e( 'Max Length', 'wp-conditional-media-folder' ); ?></label>
					<input type="number" name="wcmf_rules[<?php echo esc_attr( $index ); ?>][max_len]" value="<?php echo esc_attr( $max_len ); ?>" min="0" />
				</div>
                <div class="wcmf-field">
                    <label><?php esc_html_e( 'MIME Type', 'wp-conditional-media-folder' ); ?></label>
                    <input type="text" name="wcmf_rules[<?php echo esc_attr( $index ); ?>][mime_type]" value="<?php echo esc_attr( $mime ); ?>" placeholder="image/jpeg or image/*" />
                </div>
			</div>
		</div>
		<?php
	}
}
