<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Optionally keeps the booking page reachable only via its link:
 * hidden from automatic page menus, site search, the sitemap, the public
 * REST page listing, and marked noindex for search engines.
 */
class Unlisted {

	public static function init() {
		if ( ! self::page_id() ) {
			return;
		}
		add_filter( 'get_pages', array( self::class, 'filter_pages' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_search' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'filter_sitemap' ), 10, 2 );
		add_filter( 'rest_page_query', array( self::class, 'filter_rest' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/** The page to hide, or 0 when the option is off or no booking page is set. */
	public static function page_id(): int {
		return Settings::get( 'unlisted' ) ? (int) Settings::get( 'page_id' ) : 0;
	}

	/** Page lists (Page List block, wp_list_pages, wp_page_menu) all go through get_pages(). */
	public static function filter_pages( $pages ) {
		if ( is_admin() || ! is_array( $pages ) ) {
			return $pages;
		}
		$id = self::page_id();
		return array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $id ) {
					return (int) $page->ID !== $id;
				}
			)
		);
	}

	public static function filter_search( $query ) {
		if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
			self::exclude( $query );
		}
	}

	public static function filter_sitemap( $args, $post_type ) {
		if ( 'page' === $post_type ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), array( self::page_id() ) );
		}
		return $args;
	}

	/** Keep it out of the public /wp/v2/pages listing (editors still see it). */
	public static function filter_rest( $args ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), array( self::page_id() ) );
		}
		return $args;
	}

	public static function robots( $robots ) {
		if ( is_page( self::page_id() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	private static function exclude( $query ) {
		$ids = (array) $query->get( 'post__not_in' );
		$ids[] = self::page_id();
		$query->set( 'post__not_in', $ids );
	}
}
