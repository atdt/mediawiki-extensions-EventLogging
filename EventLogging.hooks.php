<?php
/**
 * Hooks for EventLogging extension
 *
 * @file
 * @ingroup Extensions
 */

class EventLoggingHooks {

	// Query strings are terminated with a semicolon to help identify
	// URIs that were truncated in transmit.
	const QS_TERMINATOR = ';';


	/**
	 * Emit a debug log message for each invalid or unset
	 * configuration variable (if any).
	 */
	public static function onSetup() {
		foreach( array(
			'wgEventLoggingBaseUri',
			'wgEventLoggingFile',
			'wgEventLoggingDBname',
			'wgEventLoggingModelsUri'
		) as $configVar ) {
			if ( empty( $GLOBALS[ $configVar ] ) ) {
				wfDebugLog( 'EventLogging', "$configVar is invalid or unset." );
			}
		}
	}


	/**
	 * Write an event to a file descriptor or socket.
	 *
	 * Takes an event ID and an event, encodes it as query string,
	 * and writes it to the UDP / TCP address or file specified by
	 * $wgEventLoggingFile. If $wgEventLoggingFile is not set, returns
	 * false without logging anything.
	 *
	 * @see wfErrorLog()
	 *
	 * @param $eventId string Event schema ID.
	 * @param $event array Map of event keys/vals.
	 * @return bool Whether the event was logged.
	 */
	private static function writeEvent( $eventId, $event ) {
		global $wgEventLoggingFile, $wgDBname;

		if ( !$wgEventLoggingFile ) {
			return false;
		}

		$queryString = http_build_query( array(
			'_db' => $wgDBname,
			'_id' => $eventId
		) + $event ) . self::QS_TERMINATOR;

		wfErrorLog( '?' . $queryString . "\n", $wgEventLoggingFile );
		return true;
	}


	/**
	 * Generate and log an edit event on ArticleSaveComplete.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleSaveComplete
	 * @return bool
	 */
	public static function onArticleSaveComplete( &$article, &$user, $text, $summary,
		$minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId ) {

		if ( $revision === NULL ) {
			// When an editor saves an article without having made any
			// changes, no revision is created, but ArticleSaveComplete
			// still gets called.
			return true;
		}

		$title = $article->getTitle();

		$event = array(
			'articleId' => $title->mArticleID,
			'api'       => defined( 'MW_API' ),
			'title'     => $title->mTextform,
			'namespace' => $title->getNamespace(),
			'created'   => is_null( $revision->getParentId() ),
			'summary'   => $summary,
			'timestamp' => $revision->getTimestamp(),
			'minor'     => $minoredit,
			'loggedIn'  => $user->isLoggedIn()
		);

		if ( $user->isLoggedIn() ) {
			$event += array(
				'userId'     => $user->getId(),
				'editCount'  => $user->getEditCount(),
				'registered' => wfTimestamp( TS_UNIX, $user->getRegistration() )
			);
		}

		self::writeEvent( 'edit', $event );
		return true;
	}


	/**
	 * @param array &$vars
	 * @return bool
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgEventLoggingBaseUri;

		$vars[ 'wgEventLoggingBaseUri' ] = $wgEventLoggingBaseUri;
		return true;
	}

	/**
	 * @param array &$testModules
	 * @param ResourceLoader $resourceLoader
	 * @return bool
	 */
	public static function onResourceLoaderTestModules( &$testModules, &$resourceLoader ) {
		$testModules[ 'qunit' ][ 'ext.EventLogging.tests' ] = array(
			'scripts'       => array( 'tests/ext.EventLogging.tests.js' ),
			'dependencies'  => array( 'ext.EventLogging' ),
			'localBasePath' => __DIR__,
			'remoteExtPath' => 'EventLogging',
		);
		return true;
	}
}
