<?php
/**
 * WPML access layer.
 *
 * @package TranslationApi
 */

declare( strict_types=1 );

namespace TranslationApi\Wpml;

defined( 'ABSPATH' ) || exit;

/**
 * Reads language + translation data from WPML through its public
 * filter API — never by querying the `wp_icl_*` tables directly.
 *
 * Why filters, not SQL: the `icl_translations` / `icl_strings` /
 * `icl_translate_job` schema is WPML-internal and changes between major
 * versions. The documented `wpml_*` filters are the stable contract:
 *
 *   - wpml_active_languages       — configured locales
 *   - wpml_default_language       — site source language
 *   - wpml_element_language_code  — a post's language
 *   - wpml_element_trid           — the translation-group id for a post
 *   - wpml_get_element_translations — every language sibling in that group
 *
 * "Segments" at this layer are *translatable fields* (title, content,
 * excerpt, selected meta) flattened into dotted keys. Sentence-level
 * segmentation is the consuming translation system's job, so we keep this
 * layer at field granularity and hand out whole field values.
 */
final class WpmlReader {

	/**
	 * Core post fields we treat as translatable, mapped to canonical keys.
	 * The post-object property => canonical dotted key.
	 */
	private const TRANSLATABLE_FIELDS = array(
		'post_title'   => 'title',
		'post_content' => 'content',
		'post_excerpt' => 'excerpt',
	);

	/**
	 * Statuses the API may list and translate. Includes drafts, pending,
	 * scheduled and private so translations can be prepared *before* a page
	 * (or the whole site) goes live — the common pre-launch workflow.
	 * `trash` / `auto-draft` / `inherit` are intentionally excluded.
	 */
	private const LISTED_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	public function is_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
	}

	public function default_language(): string {
		$code = apply_filters( 'wpml_default_language', null );
		return is_string( $code ) && '' !== $code ? $code : 'en';
	}

	/**
	 * Configured locales as canonical rows: [{code, name, is_default}].
	 *
	 * WPML language codes (`en`, `de`, `pt-pt`) are close to BCP-47 but not
	 * identical; leave normalisation to the client. We pass WPML's code through
	 * and include `default_locale` (e.g. `de_DE`) in case the client wants it.
	 *
	 * @return array<int, array{code:string, name:string, is_default:bool, locale:?string}>
	 */
	public function locales(): array {
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $active ) ) {
			return array();
		}
		$default = $this->default_language();
		$out     = array();
		foreach ( $active as $code => $lang ) {
			$out[] = array(
				'code'       => (string) $code,
				'name'       => isset( $lang['translated_name'] ) ? (string) $lang['translated_name'] : (string) $code,
				'is_default' => ( (string) $code === $default ),
				'locale'     => isset( $lang['default_locale'] ) ? (string) $lang['default_locale'] : null,
			);
		}
		return $out;
	}

	/**
	 * A paginated slice of translatable resources of one post type.
	 *
	 * Lists only source-language rows so the client sees each piece of
	 * content once (its siblings are reachable via get_translations()).
	 *
	 * @param string  $post_type WordPress post type (page, post, ...).
	 * @param ?string $locale    Source locale to list; defaults to site default.
	 * @param int     $offset    Pagination cursor (numeric offset).
	 * @param int     $limit     Page size.
	 * @return array{resources: array<int, array{id:string,type:string,title:?string,metadata:array<string,mixed>}>, next_cursor: ?string}
	 */
	public function list_resources( string $post_type, ?string $locale, int $offset, int $limit ): array {
		$locale = $locale ?: $this->default_language();

		// Scope the WP_Query to one language for this request.
		$prev = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $locale );

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => self::LISTED_STATUSES,
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
				'suppress_filters' => false,
			)
		);

		$resources = array();
		foreach ( $query->posts as $post ) {
			$resources[] = $this->resource_summary( $post );
		}

		do_action( 'wpml_switch_language', $prev );

		$returned    = count( $resources );
		$next_cursor = ( $returned === $limit ) ? (string) ( $offset + $limit ) : null;

		return array(
			'resources'   => $resources,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * A resource plus every translatable key and every known translation.
	 *
	 * Response shape:
	 *   resource, source_locale, keys[], translations{key:{locale:value}}, metadata.
	 *
	 * @param int           $post_id Source post id.
	 * @param array<string> $locales Restrict to these locales; empty = all.
	 * @return ?array<string, mixed> Null when the post does not exist.
	 */
	public function get_resource_translations( int $post_id, array $locales = array() ): ?array {
		$source = get_post( $post_id );
		if ( ! $source instanceof \WP_Post ) {
			return null;
		}

		$element_type  = 'post_' . $source->post_type;
		$source_locale = (string) apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => $element_type,
			)
		);
		if ( '' === $source_locale ) {
			$source_locale = $this->default_language();
		}

		$keys = $this->flatten_post( $source, $source_locale );

		// Walk the translation group (trid) for sibling-language posts.
		$translations = array();
		$trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		if ( $trid ) {
			$group = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
			if ( is_array( $group ) ) {
				foreach ( $group as $lang_code => $entry ) {
					$lang_code = (string) $lang_code;
					if ( $lang_code === $source_locale ) {
						continue;
					}
					if ( ! empty( $locales ) && ! in_array( $lang_code, $locales, true ) ) {
						continue;
					}
					$translated_id = isset( $entry->element_id ) ? (int) $entry->element_id : 0;
					if ( $translated_id <= 0 ) {
						continue;
					}
					$translated_post = get_post( $translated_id );
					if ( ! $translated_post instanceof \WP_Post ) {
						continue;
					}
					foreach ( $this->flatten_post( $translated_post, $lang_code ) as $tk ) {
						$translations[ $tk['key'] ][ $lang_code ] = $tk['source_value'];
					}
				}
			}
		}

		return array(
			'resource'      => $this->resource_summary( $source ),
			'source_locale' => $source_locale,
			'keys'          => $keys,
			// Cast to object so an empty map serializes as JSON `{}`, not `[]`.
			// The shape is a `{key: {locale: value}}` object even when empty.
			'translations'  => (object) $translations,
			'metadata'      => $this->post_metadata( $source ),
		);
	}

	/**
	 * Resolve the post id of a resource in a target locale, if it exists.
	 * Used by write-back (Phase: approval-gated).
	 */
	public function translated_post_id( int $source_post_id, string $post_type, string $locale ): ?int {
		$translated = apply_filters(
			'wpml_object_id',
			$source_post_id,
			$post_type,
			false,
			$locale
		);
		return is_numeric( $translated ) ? (int) $translated : null;
	}

	/**
	 * Create or update the WPML translation of a resource in one locale.
	 *
	 * This is the write-back a client calls after translating: it takes the
	 * translated values, writes them onto the target-language post (creating
	 * it if absent), and links it into the source's `trid` group so WPML
	 * treats it as the translation. One locale per call.
	 *
	 * @param int                  $source_post_id Source (default-language) post id.
	 * @param string               $locale         Target WPML locale code.
	 * @param array<string,string> $values         {canonical_key: translated_value}.
	 * @return array{resource_id:string,locale:string,keys_written:int,keys_skipped:int,errors:array<int,string>}|null
	 *         Null when the source post does not exist.
	 */
	public function set_resource_translations( int $source_post_id, string $locale, array $values ): ?array {
		$source = get_post( $source_post_id );
		if ( ! $source instanceof \WP_Post ) {
			return null;
		}

		$post_type    = $source->post_type;
		$element_type  = 'post_' . $post_type;
		$source_locale = (string) apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $source_post_id,
				'element_type' => $element_type,
			)
		);
		if ( '' === $source_locale ) {
			$source_locale = $this->default_language();
		}

		// Map canonical keys back onto post fields; collect the rest for the
		// symmetric custom-field hook. Title is plain text; content/excerpt
		// keep their markup (Gutenberg block delimiters are HTML comments and
		// must survive — so no wp_kses here).
		$field_map = array_flip( self::TRANSLATABLE_FIELDS );
		$postarr   = array();
		$extra     = array();
		$written   = 0;
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( isset( $field_map[ $key ] ) ) {
				$property             = $field_map[ $key ];
				$postarr[ $property ] = ( 'post_title' === $property )
					? sanitize_text_field( (string) $value )
					: (string) $value;
				++$written;
			} else {
				$extra[ $key ] = (string) $value;
			}
		}

		$postarr['post_type']   = $post_type;
		$postarr['post_status'] = $source->post_status;

		$existing_id = $this->translated_post_id( $source_post_id, $post_type, $locale );
		if ( $existing_id && $existing_id !== $source_post_id ) {
			$postarr['ID'] = $existing_id;
			$result_id     = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $result_id ) ) {
			return array(
				'resource_id'  => (string) $source_post_id,
				'locale'       => $locale,
				'keys_written' => 0,
				'keys_skipped' => 0,
				'errors'       => array( $result_id->get_error_message() ),
			);
		}

		// Link the post into the source's translation group so WPML sees it
		// as the $locale translation of $source_post_id.
		$trid = apply_filters( 'wpml_element_trid', null, $source_post_id, $element_type );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => (int) $result_id,
				'element_type'         => $element_type,
				'trid'                 => $trid,
				'language_code'        => $locale,
				'source_language_code' => $source_locale,
			)
		);

		/**
		 * Write non-core translated keys (custom fields, ACF, SEO meta) onto
		 * the translation post. Symmetric to `translation_api_extra_translation_keys`.
		 *
		 * @param int    $translation_id Target post id.
		 * @param array  $extra          {key: translated_value} not mapped to core fields.
		 * @param string $locale         Target locale.
		 * @param WP_Post $source         Source post.
		 */
		$extra = apply_filters( 'translation_api_set_extra_translation_keys', $extra, (int) $result_id, $locale, $source );

		return array(
			'resource_id'  => (string) $source_post_id,
			'locale'       => $locale,
			'keys_written' => $written,
			// Anything the extra-keys hook didn't consume is reported skipped.
			'keys_skipped' => is_array( $extra ) ? count( $extra ) : 0,
			'errors'       => array(),
		);
	}

	// -- internals ---------------------------------------------------------

	/**
	 * @return array{id:string,type:string,title:?string,metadata:array<string,mixed>}
	 */
	private function resource_summary( \WP_Post $post ): array {
		return array(
			'id'       => (string) $post->ID,
			'type'     => $post->post_type,
			'title'    => '' !== $post->post_title ? $post->post_title : null,
			'metadata' => $this->post_metadata( $post ),
		);
	}

	/**
	 * Provider-native version primitives for change detection. Clients can
	 * store these to detect when a resource changed since the last pull;
	 * capturing them at read time is lossy if skipped, so we always populate them.
	 *
	 * @return array<string, mixed>
	 */
	private function post_metadata( \WP_Post $post ): array {
		return array(
			'modified_gmt' => $post->post_modified_gmt,
			'status'       => $post->post_status,
			'slug'         => $post->post_name,
			'link'         => get_permalink( $post ),
		);
	}

	/**
	 * Flatten a post's translatable fields into canonical TranslationKey rows.
	 *
	 * @return array<int, array{key:string,source_value:string,source_locale:string,source_digest:string}>
	 */
	private function flatten_post( \WP_Post $post, string $locale ): array {
		$keys = array();
		foreach ( self::TRANSLATABLE_FIELDS as $property => $canonical_key ) {
			$value = (string) $post->$property;
			if ( '' === $value ) {
				continue;
			}
			$keys[] = array(
				'key'           => $canonical_key,
				'source_value'  => $value,
				'source_locale' => $locale,
				// sha256 hex of the source value — clients use it to detect changes.
				'source_digest' => hash( 'sha256', $value ),
			);
		}

		/**
		 * Extend translatable keys with custom fields, ACF, SEO meta, or
		 * page-builder content. Return additional TranslationKey rows.
		 *
		 * @param array  $extra  Additional keys to append.
		 * @param WP_Post $post   The post being flattened.
		 * @param string  $locale The locale of this post.
		 */
		$extra = apply_filters( 'translation_api_extra_translation_keys', array(), $post, $locale );
		if ( is_array( $extra ) ) {
			$keys = array_merge( $keys, $extra );
		}

		return $keys;
	}
}
