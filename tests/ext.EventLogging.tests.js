( function ( mw, $ ) {
	'use strict';

	var earthquakeModel = {
		epicenter: {
			type: 'string',
			'enum': [ 'Valdivia', 'Sumatra', 'Kamchatka' ]
		},
		magnitude: {
			type: 'number'
		},
		article: {
			type: 'string',
			optional: true
		}
	};


	QUnit.module( 'ext.EventLogging', QUnit.newMwEnvironment( {
		setup: function () {
			mw.eventLog.declareModel( 'earthquake', earthquakeModel, true );
		}
	} ) );


	QUnit.test( 'Configuration', function ( assert ) {
		assert.ok( mw.config.exists( 'wgEventLoggingBaseUri' ), 'Global config var "wgEventLoggingBaseUri" exists' );
	} );


	QUnit.test( 'getModel', function ( assert ) {
		assert.equal( mw.eventLog.getModel( 'earthquake' ), earthquakeModel, 'Retrieves model if exists' );
		assert.equal( mw.eventLog.getModel( 'foo' ), null, 'Returns null for missing models' );
	} );


	QUnit.test( 'declareModel', function ( assert ) {
		var newModel = {
			richter: { type: 'number' }
		};
		assert.throws( function () {
			mw.eventLog.declareModel( 'earthquake', newModel );
		}, /overwrite/, 'Does not clobber existing models' );
		assert.equal( mw.eventLog.declareModel( 'earthquake', newModel, true ), newModel, 'Clobbers when explicitly asked' );
	} );


	QUnit.test( 'isInstance', function ( assert ) {

		$.each( {
			boolean: {
				valid: [ true, false ],
				invalid: [ undefined, null, 0, -1, 1, 'false' ]
			},
			integer: {
				valid: [ -12, 42, 0, 4294967296 ],
				invalid: [ 42.1, NaN, Infinity, '42', [ 42 ] ]
			},
			number: {
				valid: [ 12, 42.1, 0, Math.PI ],
				invalid: [ '42.1', NaN, [ 42 ], undefined ]
			},
			string: {
				valid: [ 'Hello', '', '-1' ],
				invalid: [ [], 0, true ]
			},
			timestamp: {
				valid: [ new Date().getTime(), new Date() ],
				invalid: [ -1, 'yesterday', NaN ]
			}
		}, function ( type, cases ) {
			$.each( cases.valid, function () {
				assert.ok(
					mw.eventLog.isInstance( this, type ),
					[ $.toJSON( this ), type ].join( ' is a ' )
				);
			} );
			$.each( cases.invalid, function () {
				assert.ok(
					!mw.eventLog.isInstance( this, type ),
					[ $.toJSON( this ), type ].join( ' is not a ' )
				);
			} );
		} );

	} );


	QUnit.test( 'assertValid', function ( assert ) {
		assert.ok( mw.eventLog.assertValid( {
			epicenter: 'Valdivia',
			magnitude: 9.5
		}, 'earthquake' ), 'Optional fields may be omitted' );

		assert.throws( function () {
			mw.eventLog.assertValid( {
				epicenter: 'Valdivia',
				article: '[[1960 Valdivia earthquake]]'
			}, 'earthquake' );
		}, /Missing/, 'Required fields must be present.' );

		assert.throws( function () {
			mw.eventLog.assertValid( {
				epicenter: 'Valdivia',
				magnitude: '9.5'
			}, 'earthquake' );
		}, /Wrong/, 'Values must be instances of declared type' );

		assert.throws( function () {
			mw.eventLog.assertValid( {
				epicenter: 'Valdivia',
				magnitude: 9.5,
				depth: 33
			}, 'earthquake' );
		}, /Unrecognized/, 'Unrecognized fields fail validation' );

		assert.throws( function () {
			mw.eventLog.assertValid( {
				epicenter: 'Tōhoku',
				magnitude: 9.0
			}, 'earthquake' );
		}, /enum/, 'Enum fields constrain possible values' );
	} );


	QUnit.test( 'logEvent', function ( assert ) {
		QUnit.expect( 2 );

		assert.throws( function () {
			mw.eventLog.logEvent( 'earthquake', {
				epicenter: 'Sumatra',
				magnitude: 9.5,
				article: new Array( 256 ).join('*')
			} );
		}, /Request URI/, 'URIs over 255 bytes are rejected' );

		var e = {
			epicenter: 'Valdivia',
			magnitude: 9.5
		};

		mw.eventLog.logEvent( 'earthquake', e ).always( function () {
			assert.deepEqual( this, e, 'logEvent promise resolves with event' );
		} );
	} );

	QUnit.test( 'setDefaults', function ( assert ) {
		QUnit.expect( 3 );

		assert.deepEqual( mw.eventLog.setDefaults( 'earthquake', {
			epicenter: 'Valdivia'
		} ), { epicenter: 'Valdivia' }, 'setDefaults returns defaults' );

		mw.eventLog.logEvent( 'earthquake', {
			magnitude: 9.5
		} ).always( function () {
			assert.deepEqual( this, {
				epicenter: 'Valdivia',
				magnitude: 9.5
			}, 'Logged event is annotated with defaults' );
		} );

		assert.deepEqual(
			mw.eventLog.setDefaults( 'earthquake', null ), {},
			'Passing null to setDefaults clears any defaults'
		);
	} );

} ( mediaWiki, jQuery ) );
