<?php
/**
 * Uninstall handler.
 *
 * Removes plugin data only when the user opted in via settings. The default is
 * to preserve data so an accidental delete/reinstall keeps translations intact.
 *
 * @package AumLang
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$aumlang_settings = get_option( 'aumlang_settings', array() );

if ( empty( $aumlang_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$aumlang_tables = array(
	$wpdb->prefix . 'aumlang_languages',
	$wpdb->prefix . 'aumlang_translations',
	$wpdb->prefix . 'aumlang_term_translations',
	$wpdb->prefix . 'aumlang_node_translations',
	$wpdb->prefix . 'aumlang_glossary',
	$wpdb->prefix . 'aumlang_strings',
);

foreach ( $aumlang_tables as $aumlang_table ) {
	// Table names cannot be parameterized; they are built from the trusted prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$aumlang_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Per-object link metadata left on posts and terms.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_aumlang_source_id', '_aumlang_language' )" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ( '_aumlang_source_term', '_aumlang_language' )" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

delete_option( 'aumlang_settings' );
delete_option( 'aumlang_db_version' );
delete_option( 'aumlang_flush_rewrite' );
delete_option( 'aumlang_string_discovery' );
