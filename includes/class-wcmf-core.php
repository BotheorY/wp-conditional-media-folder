<?php
namespace WCMF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCMF_Core
 * Handles the logic for filtering uploads, changing directories, and retrieving file paths.
 */
class WCMF_Core {

	/**
	 * Flag to determine if the current upload matches a rule.
	 * @var bool
	 */
	private $is_current_upload_custom = false;

	/**
	 * Init hooks.
	 */
	public function init() {
		// 1. Intercept upload to check rules (runs before file move)
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'check_upload_rules' ] );
		
		// 2. Modify upload directory if rules match
		add_filter( 'upload_dir', [ $this, 'change_upload_dir' ] );

		// 3. Mark the attachment as custom after successful upload
		add_action( 'add_attachment', [ $this, 'mark_attachment_as_custom' ] );

		// 4. Retrieve correct URL for custom files (Frontend/Admin view)
		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 10, 2 );

		// 5. Retrieve correct file path for custom files (Server-side operations like thumbnails)
		add_filter( 'get_attached_file', [ $this, 'filter_attached_file' ], 10, 2 );
	}

	/**
	 * Multibyte-safe lowercase with graceful fallback (mbstring is not guaranteed).
	 */
	private function wcmf_str_lower( $value ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( (string) $value, 'UTF-8' );
		}
		return strtolower( (string) $value );
	}

	private function wcmf_str_pos( $haystack, $needle ) {
		if ( function_exists( 'mb_strpos' ) ) {
			return mb_strpos( (string) $haystack, (string) $needle, 0, 'UTF-8' );
		}
		return strpos( (string) $haystack, (string) $needle );
	}

	private function wcmf_str_rpos( $haystack, $needle ) {
		if ( function_exists( 'mb_strrpos' ) ) {
			return mb_strrpos( (string) $haystack, (string) $needle, 0, 'UTF-8' );
		}
		return strrpos( (string) $haystack, (string) $needle );
	}

	private function wcmf_str_len( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( (string) $value, 'UTF-8' );
		}
		return strlen( (string) $value );
	}

	private function wcmf_substr( $str, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return ( null === $length )
				? mb_substr( (string) $str, (int) $start, null, 'UTF-8' )
				: mb_substr( (string) $str, (int) $start, (int) $length, 'UTF-8' );
		}
		return ( null === $length )
			? substr( (string) $str, (int) $start )
			: substr( (string) $str, (int) $start, (int) $length );
	}

	/* ------------------------- */
	/* Path helpers              */
	/* ------------------------- */

	private function is_traversal_path( $path ) {
		return (bool) preg_match( '#(^|/)\.\.(/|$)#', (string) $path );
	}

	private function wcmf_is_stream( $path ) {
		return function_exists( 'wp_is_stream' ) && wp_is_stream( (string) $path );
	}

	private function wcmf_is_absolute_path( $path ) {
		$path = (string) $path;

		if ( $this->wcmf_is_stream( $path ) ) {
			return false; // treat streams as not acceptable absolute paths here
		}

		$path = wp_normalize_path( $path );
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

	private function wcmf_normalize_relative( $path ) {
		$path = (string) $path;
		$path = wp_normalize_path( $path );

		if ( '' === $path ) {
			return '';
		}

		if ( $this->wcmf_is_stream( $path ) ) {
			return '';
		}

		if ( $this->is_traversal_path( $path ) ) {
			return '';
		}

		// WP stores _wp_attached_file as relative (usually).
		if ( $this->wcmf_is_absolute_path( $path ) ) {
			return '';
		}

		return ltrim( $path, '/' );
	}
	
	/**
	 * Step 1: Checks if the uploaded file matches any defined rules.
	 * @param array $file The file array from $_FILES.
	 * @return array The file array.
	 */
	public function check_upload_rules( $file ) {
		// Reset flag at start of every upload handle
		$this->is_current_upload_custom = false;

		if ( empty( $file['name'] ) ) {
			return $file;
		}

		$rules = get_option( 'wcmf_rules', [] );
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return $file;
		}

		// Robust filename extraction (extension stripped from the last dot)
		$basename     = wp_basename( $file['name'] );
		$last_dot_pos = $this->wcmf_str_rpos( $basename, '.' );
		if ( $last_dot_pos !== false && $last_dot_pos > 0 ) {
			$filename = $this->wcmf_substr( $basename, 0, $last_dot_pos );
		} else {
			$filename = $basename;
		}

		// MIME type: prefer server-side detection (client provided MIME can be spoofed).
		$type_reported = isset( $file['type'] ) ? (string) $file['type'] : '';
		$type          = '';

		$tmp_name = ( ! empty( $file['tmp_name'] ) && @file_exists( $file['tmp_name'] ) ) ? (string) $file['tmp_name'] : '';

		if ( '' !== $tmp_name ) {
			// Prefer WP-native detection when available.
			if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
				$check = wp_check_filetype_and_ext( $tmp_name, $basename );
				if ( ! empty( $check['type'] ) ) {
					$type = (string) $check['type'];
				}
			}

			// Fallback: finfo, if available.
			if ( ( empty( $type ) || 'application/octet-stream' === $type ) && function_exists( 'finfo_open' ) ) {
				$finfo = finfo_open( FILEINFO_MIME_TYPE );
				if ( $finfo ) {
					$detected_type = finfo_file( $finfo, $tmp_name );
					if ( $detected_type ) {
						$type = (string) $detected_type;
					}
					finfo_close( $finfo );
				}
			}
		}

		// If the server cannot determine the type, we cannot trust it matches a safe rule.
		// We leave $type as empty or octet-stream to likely fail the rule check.
 
		// Evaluate Rules
		foreach ( $rules as $rule ) {
			// If we couldn't detect a type, we pass empty string, which will fail MIME rules safely.
			if ( $this->evaluate_rule( $rule, $filename, $type ) ) {
				$this->is_current_upload_custom = true;
				break; // Stop at first match (OR logic)
			}
		}

		return $file;
	}

	/**
	 * Evaluates a single rule set with strict multibyte checking.
	 */
	private function evaluate_rule( $rule, $filename, $mime ) {
		if ( ! is_array( $rule ) ) {
			return false;
		}

		$filename      = (string) $filename;
		$mime          = (string) $mime;
		$filename_lower = $this->wcmf_str_lower( $filename );
		$mime_lower     = $this->wcmf_str_lower( $mime );
		$filename_len   = $this->wcmf_str_len( $filename );

		// 1. Starts With
		if ( ! empty( $rule['starts_with'] ) ) {
			$search = $this->wcmf_str_lower( $rule['starts_with'] );
			if ( '' !== $search && 0 !== $this->wcmf_str_pos( $filename_lower, $search ) ) {
				return false;
			}
		}

		// 2. Ends With
		if ( ! empty( $rule['ends_with'] ) ) {
			$search = $this->wcmf_str_lower( $rule['ends_with'] );
			$len    = $this->wcmf_str_len( $search );
			if ( $len > 0 && $this->wcmf_substr( $filename_lower, -$len ) !== $search ) {
				return false;
			}
		}

		// 3. Contains
		if ( ! empty( $rule['contains'] ) ) {
			$search = $this->wcmf_str_lower( $rule['contains'] );
			if ( '' !== $search && false === $this->wcmf_str_pos( $filename_lower, $search ) ) {
				return false;
			}
		}

		// 4. Min Length
		if ( isset( $rule['min_len'] ) && $rule['min_len'] !== '' ) {
			if ( $filename_len < intval( $rule['min_len'] ) ) {
				return false;
			}
		}

		// 5. Max Length
		if ( isset( $rule['max_len'] ) && $rule['max_len'] !== '' ) {
			if ( $filename_len > intval( $rule['max_len'] ) ) {
				return false;
			}
		}

		// 6. Mime Type (partial match allowed, e.g. "image/" matches "image/jpeg")
		if ( ! empty( $rule['mime_type'] ) ) {
			if ( empty( $mime_lower ) ) {
				return false; // Cannot match MIME rule if type is unknown
			}
			$search = $this->wcmf_str_lower( $rule['mime_type'] );
			if ( '' !== $search && false === $this->wcmf_str_pos( $mime_lower, $search ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Step 2: Modifies the upload directory context.
	 */
	public function change_upload_dir( $uploads ) {
		// Only modify path if the flag is true
		if ( ! $this->is_current_upload_custom ) {
			return $uploads;
		}

		$custom_path_raw = (string) get_option( 'wcmf_custom_path' );
		$custom_url_raw  = (string) get_option( 'wcmf_custom_url' );

		if ( empty( $custom_path_raw ) ) {
			return $uploads;
		}
		
		$custom_path = untrailingslashit( wp_normalize_path( $custom_path_raw ) );
		$custom_url = untrailingslashit( esc_url_raw( $custom_url_raw ) );

		if (
			$custom_path === '' ||
			$this->wcmf_is_stream($custom_path) ||
			! $this->wcmf_is_absolute_path($custom_path) ||
			$this->is_traversal_path($custom_path)
		) {
			// disable custom handling for this upload due to invalid path
			$this->is_current_upload_custom = false;
			return $uploads;
		}	
		
		// If custom URL is empty, we must NOT move the file, 
		// otherwise the media library will link to a non-existent URL.
		if ( empty( $custom_url ) ) {
			$this->is_current_upload_custom = false;
			return $uploads;
		}		
		
		// Defense-in-depth: never allow stream wrappers
		if ( $this->wcmf_is_stream( $custom_path ) ) {
			return $uploads;
		}

		// Preserve subdir (Year/Month) structure to keep folder organized
		$base  = untrailingslashit( $custom_path );
		
		$subdir = isset( $uploads['subdir'] ) ? wp_normalize_path((string)$uploads['subdir']) : '';
		if ($subdir !== '' && ($this->wcmf_is_stream($subdir) || $this->is_traversal_path($subdir))) {
			$subdir = '';
		}		
				
		$target_dir = $base . $subdir;

		// Attempt creation if missing (including subdir)
		if ( ! is_dir( $target_dir ) ) {
			if ( ! wp_mkdir_p( $target_dir ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'WCMF Error: Cannot create directory ' . $target_dir . '. Falling back to default.' );
				}
				return $uploads;
			}
			// Prevent directory listing if the server allows it.
			if ( ! file_exists( $target_dir . '/index.php' ) ) {
				@file_put_contents( $target_dir . '/index.php', '<?php // Silence is golden.' );
			}
		}
		
		// Writable check against the actual target directory when possible.
		$writable_check = is_dir( $target_dir ) ? $target_dir : $custom_path;
		if ( ! is_writable( $writable_check ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WCMF Error: Directory not writable ' . $writable_check . '. Falling back to default.' );
			}
			return $uploads;
		}

		$uploads['basedir'] = $base;
		$uploads['path']    = $target_dir;

		if ( ! empty( $custom_url_raw ) ) {
			$uploads['baseurl'] = $custom_url;
			$uploads['url']     = $custom_url . $subdir;
		}

		return $uploads;
	}

	/**
	 * Step 3: Flag the attachment in DB so we know it's in a custom folder later.
	 */
	public function mark_attachment_as_custom( $post_id ) {
		if ( $this->is_current_upload_custom ) {
			update_post_meta( $post_id, '_wcmf_is_custom', '1' );

			// Store path/url snapshot to be future-proof against settings changes
			$custom_path = (string) get_option( 'wcmf_custom_path' );
			$custom_url  = (string) get_option( 'wcmf_custom_url' );

			update_post_meta( $post_id, '_wcmf_root_path', wp_normalize_path( untrailingslashit( $custom_path ) ) );
			update_post_meta( $post_id, '_wcmf_root_url', untrailingslashit( esc_url_raw( $custom_url ) ) );
		}
	}

	/**
	 * Step 4: Fix the URL when WordPress retrieves it.
	 */
	public function filter_attachment_url( $url, $post_id ) {
		if ( ! $post_id ) {
			return $url;
		}

		$is_custom = get_post_meta( $post_id, '_wcmf_is_custom', true );
		if ( ! $is_custom ) {
			return $url;
		}

		$custom_url = (string) get_post_meta( $post_id, '_wcmf_root_url', true );
		// Fallback to global setting if meta is missing
		if ( empty( $custom_url ) ) {
			$custom_url = (string) get_option( 'wcmf_custom_url' );
		}

		if ( empty( $custom_url ) ) {
			return $url;
		}

		// _wp_attached_file usually stores relative path (e.g. 2023/11/file.jpg)
		$file_relative = (string) get_post_meta( $post_id, '_wp_attached_file', true );
		if ( $file_relative ) {
		// Only accept safe relative paths here.
			$file_relative = $this->wcmf_normalize_relative( $file_relative );
			if ( '' === $file_relative ) {
				return $url;
			}
			return untrailingslashit( $custom_url ) . '/' . $file_relative;
		}

		return $url;
	}

	/**
	 * Step 5: Fix the File Path when WordPress manipulates the file.
	 */
	public function filter_attached_file( $file, $post_id ) {
		if ( ! $post_id ) {
			return $file;
		}

		$is_custom = get_post_meta( $post_id, '_wcmf_is_custom', true );
		if ( ! $is_custom ) {
			return $file;
		}

		$custom_path = (string) get_post_meta( $post_id, '_wcmf_root_path', true );
		if ( empty( $custom_path ) ) {
			$custom_path = wp_normalize_path( get_option( 'wcmf_custom_path' ) );
		}
		$custom_path = (string) $custom_path;

		// Defense-in-depth: don't accept stream wrappers for roots.
		if ( empty( $custom_path ) || $this->wcmf_is_stream( $custom_path ) ) {
			return $file;
		}

		$file_relative = (string) get_post_meta( $post_id, '_wp_attached_file', true );
		if ( ! $file_relative || $this->wcmf_is_stream( $file_relative ) ) {
			return $file;
		}

		$file_relative_norm = wp_normalize_path( (string) $file_relative );

		// If already absolute and inside the custom path, return as-is
		if ( $this->wcmf_is_absolute_path( $file_relative_norm ) ) {
			if ( ! $this->is_traversal_path( $file_relative_norm ) ) {
				$custom_base = untrailingslashit( wp_normalize_path( $custom_path ) ) . '/';
				if ( strpos( $file_relative_norm, $custom_base ) === 0 ) {
					return $file_relative_norm;
				}
			}
			return $file;
		}

		// Normal case: meta is relative.
		$file_rel_norm = $this->wcmf_normalize_relative( $file_relative_norm );
		if ( '' === $file_rel_norm ) {
			return $file;
		}

		return untrailingslashit( $custom_path ) . '/' . $file_rel_norm;
	}
	
}
