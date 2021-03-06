/**
 * Logs arbitrary events from client-side code to server. Each event
 * must validate against a predeclared data model, specified as JSON
 * Schema (version 3 of the draft).
 *
 * @author Ori Livneh <ori@wikimedia.org>
 */

( function ( mw, $, console ) {
	'use strict';

	if ( !mw.config.get( 'wgEventLoggingBaseUri' ) ) {
		mw.log( 'wgEventLoggingBaseUri is not set.' );
	}

	var self = mw.eventLog = {

		schemas: {},

		warn: console && $.isFunction( console.warn ) ?
			$.proxy( console.warn, console ) : mw.log,

		/**
		 * @param string schemaName
		 * @return {Object|null}
		 */
		getSchema: function ( schemaName ) {
			return self.schemas[ schemaName ] || null;
		},


		/**
		 * Declares event schema.
		 * @param {Object} schemas Schema specified as JSON Schema
		 * @param integer revision
		 * @return {Object}
		 */
		setSchema: function ( schemaName, meta ) {
			if ( self.schemas.hasOwnProperty( schemaName ) ) {
				self.warn( 'Clobbering existing "' + schemaName + '" schema' );
			}
			self.schemas[ schemaName ] = $.extend( true, {
				revision : 'UNKNOWN',
				schema   : {},
				defaults : {},
				logged   : []
			}, self.schemas[ schemaName ], meta );
			return self.schemas[ schemaName ];
		},


		/**
		 * @param {Object} instance Object to test.
		 * @param {string} type JSON Schema type specifier.
		 * @return {boolean}
		 */
		isInstance: function ( instance, type ) {
			// undefined and null are invalid values for any type.
			if ( instance === undefined || instance === null ) {
				return false;
			}
			switch ( type ) {
			case 'string':
				return typeof instance === 'string';
			case 'timestamp':
				return instance instanceof Date || (
						typeof instance === 'number' &&
						instance >= 0 &&
						instance % 1 === 0 );
			case 'boolean':
				return typeof instance === 'boolean';
			case 'integer':
				return typeof instance === 'number' && instance % 1 === 0;
			case 'number':
				return typeof instance === 'number' && isFinite( instance );
			default:
				return false;
			}
		},


		/**
		 * @param {Object} event Event to validate.
		 * @param {Object} schemaName Name of schema.
		 * @throws {Error} If event fails to validate.
		 */
		validate: function ( event, schemaName ) {
			var field, schema = self.getSchema( schemaName );

			if ( schema === null ) {
				self.warn( 'Unknown schema "' + schemaName + '"' );
				return false;
			}

			for ( field in event ) {
				if ( schema.schema[ field ] === undefined ) {
					self.warn( 'Unrecognized field "' + field + '"' );
					return false;
				}
			}

			$.each( schema.schema, function ( field, desc ) {
				var val = event[ field ];

				if ( val === undefined ) {
					if ( desc.required ) {
						self.warn( 'Missing "' + field + '" field' );
						return false;
					}
					return true;
				}
				if ( !( self.isInstance( val, desc.type ) ) ) {
					self.warn( [ 'Wrong type for field:', field, val ].join(' ') );
					return false;
				}
				// 'enum' is reserved for possible future use by the ECMAScript
				// specification, but it's legal to use it as an attribute name
				// (and it's part of the JSON Schema draft spec). Still, JSHint
				// complains unless the name is quoted. Currently (24-Oct-2012)
				// the only way to turn off the warning is to use "es5:true",
				// which would be too broad.
				if ( desc[ 'enum' ] && desc[ 'enum' ].indexOf( val ) === -1 ) {
					self.warn( [ 'Value not in enum:', val, ',', $.toJSON( desc[ 'enum' ] ) ].join(' ') );
					return false;
				}
			} );
			return true;
		},


		/**
		 * Sets default values to be applied to all subsequent events
		 * belonging to a schema. Note that no validation is performed
		 * on setDefaults, but the complete event instance (including
		 * defaults) are validated prior to dispatch.
		 *
		 * @param {string} schemaName Canonical schema name.
		 * @param {Object|null} schemaDefaults Defaults, or null to clear.
		 * @returns {Object} Updated defaults for schema.
		 */
		setDefaults: function ( schemaName, schemaDefaults ) {
			var schema = self.getSchema( schemaName );
			if ( schema === null ) {
				self.warn( 'Setting defaults on unknown schema "' + schemaName + '"' );
				schema = self.setSchema( schemaName );
			}
			return $.extend( true, schema.defaults, schemaDefaults );
		},


		/**
		 * @param {string} schemaName Canonical schema name.
		 * @param {Object} eventInstance Event instance.
		 * @returns {jQuery.Deferred} Promise object.
		 */
		logEvent: function ( schemaName, eventInstance ) {
			var baseUri, dfd, queryString, beacon, schema = self.getSchema( schemaName );

			if ( schema === null ) {
				self.warn( 'Logging event with unknown schema "' + schemaName + '"' );
				schema = self.setSchema( schemaName );
			}

			eventInstance = $.extend( true, {}, eventInstance, schema.defaults );

			baseUri = mw.config.get( 'wgEventLoggingBaseUri' );
			dfd = jQuery.Deferred();

			// Event instances are automatically annotated with '_db' and '_id'
			// to identify their origin and declared schema.
			queryString = [ $.param( {
				/*jshint nomen: false*/
				_db: mw.config.get( 'wgDBname' ),
				_id: schemaName,
				_rv: schema.revision,
				_ok: self.validate( eventInstance, schemaName )
				/*jshint nomen: true*/
			} ), $.param( eventInstance ) ].join( '&' );

			if ( !baseUri ) {
				// We already logged the fact of wgEventLoggingBaseUri being
				// empty, so respect the caller's expectation and return a
				// rejected promise.
				dfd.rejectWith( eventInstance, [ schemaName, eventInstance, queryString ] );
				return dfd.promise();
			}

			beacon = document.createElement( 'img' );

			// Browsers uniformly fire the onerror event upon receiving HTTP
			// 204 ("No Content") responses to image requests. Thus, although
			// counterintuitive, resolving the promise on error is appropriate.
			$( beacon ).on( 'error', function () {
				schema.logged.push( eventInstance );
				dfd.resolveWith( eventInstance, [ schemaName, eventInstance, queryString ] );
			} );
			beacon.src = baseUri + '?' + queryString + ';';
			return dfd.promise();
		}
	};

} ( mediaWiki, jQuery, window.console ) );
