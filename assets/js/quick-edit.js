/*
 * Quick Edit in the documents list: selects the row's current "Display mode".
 * It is the only place where that mode can be changed.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof inlineEditPost ) {
		return;
	}

	var originalEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		originalEdit.apply( this, arguments );

		var postId = id;
		if ( 'object' === typeof id ) {
			postId = this.getId( id );
		}

		var row = document.getElementById( 'post-' + postId );
		var editRow = document.getElementById( 'edit-' + postId );
		if ( ! row || ! editRow ) {
			return;
		}

		var current = row.querySelector( '[data-jimca-mode]' );
		var select = editRow.querySelector( 'select[name="jimca_display_mode"]' );

		if ( current && select ) {
			select.value = current.getAttribute( 'data-jimca-mode' );
		}
	};
}() );
