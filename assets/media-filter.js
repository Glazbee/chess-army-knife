/**
 * A "Members" filter for the Media Library's grid view (and the media dialog),
 * beside the built-in type and date filters. It sends the chosen member with the
 * attachment query; Chess_Army_Knife_Member_Photos::filter_ajax_query() applies it.
 */
( function ( $, _, wp ) {
	'use strict';

	var settings = window.chessArmyKnifeMedia;

	if ( ! wp || ! wp.media || ! wp.media.view || ! settings || ! settings.members ) {
		return;
	}

	var MemberFilter = wp.media.view.AttachmentFilters.extend( {
		id: 'chess-army-member-filter',

		createFilters: function () {
			var filters = {
				all: {
					text: settings.allLabel,
					props: { chess_army_member: 0 },
					priority: 10,
				},
			};

			_.each( settings.members, function ( member, index ) {
				filters[ 'member-' + member.id ] = {
					text: member.name,
					props: { chess_army_member: member.id },
					priority: 20 + index,
				};
			} );

			this.filters = filters;
		},
	} );

	var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		createToolbar: function () {
			AttachmentsBrowser.prototype.createToolbar.call( this );

			this.toolbar.set(
				'chessArmyMember',
				new MemberFilter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -75,
				} ).render()
			);
		},
	} );
} )( window.jQuery, window._, window.wp );
