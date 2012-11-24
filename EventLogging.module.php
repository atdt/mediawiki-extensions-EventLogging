<?php
/**
 * ResourceLoaderModule subclass for making event data models
 * available as JavaScript submodules to client-side code.
 *
 * @file
 * @author Ori Livneh <ori@wikimedia.org>
 */

/**
 * Packages a data model as a JavaScript ResourceLoader module.
 * The models are canonically stored as JSON Schema in the
 * Schema: namespace on Meta. This module attempts to retrieve
 * the required model from memcached and default to an HTTP
 * request if the key is missing.
 *
 * To prevent a cache stampede, only one thread of execution is
 * permitted to attempt an HTTP request for a given data
 * model. Other threads simply generate an empty object.
 */
class DataModelModule extends ResourceLoaderModule {

	const LOCK_TIMEOUT = 30;
	protected $model;


	/**
	 * Constructor; invoked by ResourceLoader. Ensures that the
	 * 'model' key has been set on the $wgResourceModules member array
	 * representing this module.
	 *
	 * @example
	 *
	 *	$wgResourceModules[ 'dataModel.person' ] = array(
	 *		'class' => 'DataModelModule',
	 *		'model' => 'Person.json'
	 *	);
	 *
	 * @throws MWException if the model key is missing.
	 * @param $options array
	 */
	public function __construct( $options ) {
		if ( !array_key_exists( 'model', $options ) ) {
			throw new MWException( 'DataModelModule options must set a "model" key.' );
		}
		$this->model = $options[ 'model' ];
	}

	/**
	 * Part of the ResourceLoader module interface. Declares the core
	 * ext.eventLogging module as a dependency.
	 *
	 * @return array Module names.
	 */
	public function getDependencies() {
		return array( 'ext.eventLogging' );
	}


	/**
	 * Attempt to retrieve a model via HTTP.
	 *
	 * @return array|null Decoded JSON object or null on failure.
	 */
	private function httpGetModel() {
		global $wgEventLoggingModelsUriFormat;

		$uri = sprintf( $wgEventLoggingModelsUriFormat, $this->model );

		// The HTTP request timeout is set to a fraction of the lock timeout to
		// prevent a pile-up of multiple lingering connections.
		$res = Http::get( $uri, self::LOCK_TIMEOUT * 0.8 );
		if ( $res === false ) {
			wfDebugLog( 'EventLogging', "Failed to retrieve data model '{$this->model}' via HTTP" );
			return;
		}

		$model = FormatJson::decode( $res, true );
		if ( !is_array( $model ) ) {
			wfDebugLog( 'EventLogging', "Failed to schema of '{$this->model}' mode." );
			return;
		}

		return $model;
	}


	/**
	 * Get the last modified timestamp of this module.
	 *
	 * The last modified timestamp is be updated automatically by an
	 * PageContentSaveComplete hook handler in JsonSchemaHooks
	 * whenever a model's page is saved. If the key is missing, we
	 * default to setting the last modified time to now.
	 *
	 * @param $context ResourceLoaderContext
	 * @return integer Unix timestamp
	 */
	public function getModifiedTime( ResourceLoaderContext $context ) {
		global $wgMemc;

		$key = wfModelKey( $this->model, 'mTime' );
		$mTime = $wgMemc->get( $key );

		if ( !$mTime ) {
			$mTime = wfTimestampNow();
			$wgMemc->add( $key, $mTime );
		}

		return $mTime;
	}


	/**
	 * Retrieves a data model from cache or HTTP and generates a
	 * JavaScript expression which adds it to
	 * mediaWiki.eventLogging.dataModels. If unable to retrieve data
	 * model, sets the value to an empty object instead.
	 *
	 * @param $context ResourceLoaderContext
	 * @return string
	 */
	public function getScript( ResourceLoaderContext $context ) {
		global $wgMemc;

		$model = $wgMemc->get( wfModelKey( $this->model ) );

		if ( $model === false ) {
			// Attempt to acquire exclusive update lock. If successful,
			// grab model via HTTP and update the cache.
			if ( $wgMemc->add( wfModelKey( $this->model,  'lock' ), 1, self::LOCK_TIMEOUT ) ) {
				$model = self::httpGetModel();
				if ( $model ) {
					$wgMemc->add( wfModelKey( $this->model ), $model );
				}
			}
		}

		if ( !$model ) {
			$model = new stdClass();  // Will be encoded to empty JS object.
		}

		return Xml::encodeJsCall( 'mediaWiki.eventLog.setModels', array( $model ) );
	}
}
