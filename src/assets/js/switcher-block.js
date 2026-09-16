/**
 * AumLang Language Switcher block (dynamic, server-rendered).
 *
 * @package AumLang
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;

	blocks.registerBlockType( 'aumlang/language-switcher', {
		title: __( 'Language Switcher', 'aumlang' ),
		description: __( 'Links to the current page in each language.', 'aumlang' ),
		icon: 'translation',
		category: 'widgets',
		attributes: {
			show: { type: 'string', default: 'name' },
			hideCurrent: { type: 'boolean', default: false }
		},
		edit: function ( props ) {
			var a = props.attributes;

			return el(
				element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Settings', 'aumlang' ) },
						el( SelectControl, {
							label: __( 'Display', 'aumlang' ),
							value: a.show,
							options: [
								{ label: __( 'Name', 'aumlang' ), value: 'name' },
								{ label: __( 'Code', 'aumlang' ), value: 'code' },
								{ label: __( 'Name + code', 'aumlang' ), value: 'both' }
							],
							onChange: function ( v ) { props.setAttributes( { show: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Hide current language', 'aumlang' ),
							checked: a.hideCurrent,
							onChange: function ( v ) { props.setAttributes( { hideCurrent: v } ); }
						} )
					)
				),
				el( serverSideRender, {
					block: 'aumlang/language-switcher',
					attributes: a
				} )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n );
