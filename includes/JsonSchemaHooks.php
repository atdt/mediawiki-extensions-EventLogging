<?php
/**
 * Hooks for managing JSON Schema namespace and content model.
 *
 * @file
 * @ingroup Extensions
 * @ingroup EventLogging
 *
 * @author Ori Livneh <ori@wikimedia.org>
 */

class JsonSchemaHooks {

	/**
	 * Registers hook and content handlers if the JSON Schema
	 * namespace is enabled for this site.
	 * @return bool: Whether hooks and handler were registered.
	 */
	static function registerHandlers() {
		global $wgHooks, $wgContentHandlers, $wgEventLoggingDBname, $wgDBname;

		if ( $wgEventLoggingDBname === $wgDBname ) {
			$wgContentHandlers[ 'JsonSchema' ] = 'JsonSchemaContentHandler';

			$wgHooks[ 'BeforePageDisplay' ][] = 'JsonSchemaHooks::onBeforePageDisplay';
			$wgHooks[ 'CanonicalNamespaces' ][] = 'JsonSchemaHooks::onCanonicalNamespaces';
			$wgHooks[ 'EditFilterMerged' ][] = 'JsonSchemaHooks::onEditFilterMerged';
			$wgHooks[ 'CodeEditorGetPageLanguage' ][] = 'JsonSchemaHooks::onCodeEditorGetPageLanguage';

			return true;
		}

		return false;
	}


	/**
	 * Declares JSON as the code editor language for Schema: pages.
	 * This hook only runs if the CodeEditor extension is enabled.
	 * @param Title $title
	 * @param string &$lang: Page language.
	 * @return bool
	 */
	static function onCodeEditorGetPageLanguage( $title, &$lang ) {
		if ( $title->getNamespace() === NS_SCHEMA ) {
			$lang = 'json';
		}
		return true;
	}


	/**
	 * Registers Schema namespaces and assign edit rights.
	 * @param array &$namespaces: Mapping of numbers to namespace names.
	 * @return bool
	 */
	static function onCanonicalNamespaces( array &$namespaces ) {
		global $wgGroupPermissions, $wgNamespaceContentModels, $wgNamespaceProtection;

		$namespaces[ NS_SCHEMA ] = 'Schema';
		$namespaces[ NS_SCHEMA_TALK ] = 'Schema_talk';

		$wgNamespaceProtection[ NS_SCHEMA ] = array( 'editinterface' );
		$wgNamespaceContentModels[ NS_SCHEMA ] = 'JsonSchema';

		return true;
	}


	/**
	 * Validates that the revised contents are valid JSON.
	 * If not valid, rejects edit with error message.
	 * @param EditPage $editor
	 * @param string $text: Content of the revised article.
	 * @param string &$error: Error message to return.
	 * @param string $summary: Edit summary provided for edit.
	 */
	static function onEditFilterMerged( $editor, $text, &$error, $summary ) {
		if ( $editor->getTitle()->getNamespace() !== NS_SCHEMA ) {
			return true;
		}

		$content = new JsonSchemaContent( $text );
		if ( !$content->isValid() ) {
			$error = wfMessage( 'eventlogging-invalid-json' )->parse();
			return true;
		}

		return true;
	}


	/**
	 * Adds CSS for pretty-printing schema on NS_SCHEMA pages.
	 * @param &$out OutputPage
	 * @param &$skin Skin
	 * @return bool
	 */
	static function onBeforePageDisplay( &$out, &$skin ) {
		if ( $out->getTitle()->getNamespace() === NS_SCHEMA ) {
			$out->addModuleStyles( 'ext.eventLogging.jsonSchema' );
		}
		return true;
	}
}
