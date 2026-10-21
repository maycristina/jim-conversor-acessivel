<?php
/**
 * CI check: every document made by the audit is listed as used by the page
 * that shows it (JIMCA_Usage), and the author given on the command line
 * reaches the scorecard.
 *
 * Run inside WordPress: wp eval-file bin/check-usage.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$jimca_documents = get_posts(
	array(
		'post_type'      => JIMCA_Post_Type::POST_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => -1,
	)
);

$jimca_problems = 0;

foreach ( $jimca_documents as $jimca_document ) {
	$jimca_hosts = JIMCA_Usage::hosts( $jimca_document->ID );

	WP_CLI::line( sprintf( '#%d "%s" is used in %d page(s)', $jimca_document->ID, $jimca_document->post_title, count( $jimca_hosts ) ) );

	if ( ! $jimca_hosts ) {
		WP_CLI::warning( 'not found on any page' );
		++$jimca_problems;
	}
}

// The author passed to `wp jimca convert --author=` must count in the scorecard.
$jimca_with_author = 0;

foreach ( $jimca_documents as $jimca_document ) {
	$jimca_facts = JIMCA_Scorecard::facts( $jimca_document->ID );

	if ( '' !== $jimca_facts['author'] ) {
		++$jimca_with_author;
	}
}

if ( $jimca_with_author < 1 ) {
	WP_CLI::warning( 'no document has an author' );
	++$jimca_problems;
}

if ( $jimca_problems ) {
	WP_CLI::error( $jimca_problems . ' problem(s).' );
}

WP_CLI::success( count( $jimca_documents ) . ' documents checked.' );
