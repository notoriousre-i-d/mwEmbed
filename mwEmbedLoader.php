<?php
// Include configuration 
require_once( realpath( dirname( __FILE__ ) ) . '/includes/DefaultSettings.php' );

// only include the iframe if we need to: 
// Include MwEmbedWebStartSetup.php for all of mediawiki support
if( isset( $_GET['autoembed'] ) ){
	require ( dirname( __FILE__ ) . '/includes/MwEmbedWebStartSetup.php' );
	require_once( realpath( dirname( __FILE__ ) ) . '/modules/KalturaSupport/kalturaIframeClass.php' );
}

// Check for custom resource ps config file:
if( isset( $wgKalturaPSHtml5SettingsPath ) && is_file( $wgKalturaPSHtml5SettingsPath ) ){
	require_once( $wgKalturaPSHtml5SettingsPath );
}


$mwEmbedLoader = new mwEmbedLoader();
$mwEmbedLoader->output();

class mwEmbedLoader {
	var $resultObject = null; // lazy init
	var $error = false;
	
	var $loaderFileList = array(
		// Get main kWidget resource:
		'kWidget/kWidget.js', 
		// Include json2 for old browsers that don't have JSON.stringify
		'resources/json/json2.js', 
		// By default include deprecated globals ( will be deprecated in 1.8 )
		'kWidget/kWidget.deprecatedGlobals.js', 
		// Get resource ( domReady.js )
		'kWidget/kWidget.domReady.js', 
		// Get resource (  mwEmbedLoader.js )
		'kWidget/mwEmbedLoader.js', 
		// Include checkUserAgentPlayer code
		'kWidget/kWidget.checkUserAgentPlayerRules.js',
		// Get kWidget utilties:
		'kWidget/kWidget.util.js',	
		// kWidget basic api wraper
		'resources/crypto/MD5.js',
		'kWidget/kWidget.api.js',
	);
		
	function output(){
		// Get the comment never minfied
		$o = $this->getLoaderHeader();
		
		// Check for special incloader flag to ~not~ include the loader. 
		if( ! isset( $_GET['incloader'] ) 
				|| 
			$_GET['incloader'] != 'false'
		){
			$o.= $this->getLoaderPayload();
		}
		// Once setup is complete run any embed param calls is set
		if( isset( $_GET['autoembed'] ) ){
			$o.= $this->getAutoEmbedCode();
		}
		
		// send cache headers
		$this->sendHeaders();
		
		// start gzip handler if possible:
		if(!ob_start("ob_gzhandler")) ob_start();
		
		// check for non-fatal errors: 
		if( $this->getError() ){
			echo "if( console ){ console.log('" . $this->getError() . "'); }";
		}
		// output the script output
		echo $o;
	}
	private function getAutoEmbedCode(){
		$o='';
		
		// Get the kWidget call ( pass along iframe payload path )
		$p = $this->getResultObject()->urlParameters;
		// Check required params: 
		if( !isset( $p['wid'] ) ){
			$this->setError( "missing wid param");
			return '';
		}
		$wid = htmlspecialchars( $p['wid'] );

		if( !isset( $p['uiconf_id'] ) ){
			$this->setError( "missing uiconf_id param");
			return '';
		}
		
		$uiconf_id = htmlspecialchars( $p['uiconf_id'] );
		if( !isset( $p['playerId'] ) ){
			$this->setError( "missing playerId param");
			return '';
		}
		$playerId = $p['playerId'];
		
		// Check optional params
		$width = ( isset( $p['width'] ) )? htmlspecialchars( $p['width'] ): 400;
		$height = ( isset( $p['height'] ) )? htmlspecialchars( $p['height'] ): 330;

		// Get the iframe payload
		$kIframe = new kalturaIframeClass();
		
		// get the kIframe 
		$json = array(
			'content' => $kIframe->getIFramePageOutput() 
		);
		$o.="kWidget.iframeAutoEmbedCache[ '{$playerId}' ] = " . json_encode( $json ) . ";\n";
		
		
		$o.="document.write( '<div id=\"{$playerId}\" style=\"width:{$width}px;height:{$height}px\"></div>' );\n";
		$o.="kWidget.embed( '{$playerId}', { \n" .
			"\t'wid': '{$wid}', \n" .
			"\t'uiconf_id' : '{$uiconf_id}'";
		// conditionally add in the entry id: ( no entry id in playlists )
		if( isset( $p['entry_id'] ) ){
			$o.=",\n\t'entry_id': '" . htmlspecialchars( $p['entry_id'] ) . "'";
		}
		// conditionally output flashvars:
		if( isset( $p['flashvars'] ) ){
			$o.= ",\n\t'flashvars': {";
			$coma = '';
			foreach( $p['flashvars'] as $fvKey => $fvValue) {
				$o.= $coma;
				$coma = ',';
				// check for json flavar and set acordingly
				if( is_object( json_decode( html_entity_decode( $fvValue ) ) ) ){
					$o.= "\n\t\t'{$fvKey}':";
					$fvSet = json_decode( html_entity_decode( $fvValue ) );
					$o.= json_encode( $fvSet );
				} else {
					$o.= "\"{$fvKey}\"" . ':' . json_encode( KalturaResultObject::formatString( $fvValue ) );
				}
			}
			$o.='}';
		}
		$o.="\n});";

		return $o;
	} 
			
	private function getLoaderPayload(){
		$o = '';
		// get the main payload minfied if possible
		if( $this->isDebugMode() ){
			$o = $this->getCombinedLoaderJs();
			$o.= $this->getExportedConfig();
			// get any per uiConf js:
			$o.= $this->getPerUiConfJS();
		} else {
			$o.= $this->getMinCombinedLoaderJs();
			// don't compress config
			$o.= $this->getExportedConfig();
			// get any per uiConf js:
			$o.= $this->getMinPerUiConfJS();
		}
		
		// After we load everything ( issue the kWidget.Setup call as the last line in the loader )
		$o.="\nkWidget.setup();\n";
		
		return $o;
	}
	private function setError( $errorMsg ){
		$this->error = $errorMsg;
	}
	private function getError(){
		return $this->error;
	}
	private function isDebugMode(){
		global $wgEnableScriptDebug;
		return  isset( $_GET['debug'] ) || $wgEnableScriptDebug;
	}
	private function getCacheFileContents( $key ){
		global $wgScriptCacheDirectory;
		if( is_file( $this->getCacheFilePath( $key ) ) ){
			return @file_get_contents(  $this->getCacheFilePath( $key ) );
		}
		return false;
	}
	private function getCacheFilePath( $key ){
		global $wgScriptCacheDirectory;
		return $wgScriptCacheDirectory . '/' . substr( $key, 0, 2) . '/'.  substr( $key, 2 );
	}
	
	private function getMinPerUiConfJS(){
		global $wgResourceLoaderMinifierStatementsOnOwnLine;
		// mwEmbedLoader based uiConf values can be hashed by the uiconf
		$uiConfJs = $this->getPerUiConfJS();
		if( $uiConfJs == '' ){
			return '';
		}
		// Hash the javascript string to see if we we can use a cached version and avoid JavaScriptMinifier call
		$key = md5( $uiConfJs );
		$cacheJS = $this->getCacheFileContents( $key );
		if( $cacheJS !== false ){
			return $cacheJS;
		}
		//minfy js 
		require_once( realpath( dirname( __FILE__ ) ) . '/includes/libs/JavaScriptMinifier.php' );
		$minjs = JavaScriptMinifier::minify( $uiConfJs, $wgResourceLoaderMinifierStatementsOnOwnLine );
		// output minified cache: 
		$this->outputFileCache( $key, $minjs);
		return $minjs;
	}
	
	/** gets any defiend on-page uiConf js */
	private function getPerUiConfJS(){
		if( !$this->getResultObject() 
				|| 
			!isset( $this->getResultObject()->urlParameters [ 'uiconf_id' ] )
				||
			( !isset( $this->getResultObject()->urlParameters [ 'wid' ] ) 
				&&
			  !isset( $this->getResultObject()->urlParameters [ 'p' ] ) 	
			)
		){
			// directly issue the UiConfJs callback
			return 'kWidget.inLoaderUiConfJsCallback();';
		}
		// load the onPage js services
		$mweUiConfJs = new mweApiUiConfJs();
		// output is set to empty string:
		$o='';
		// always include UserAgentPlayerRules:
		$o.= $mweUiConfJs->getUserAgentPlayerRules();

		// support including special player rewrite flags if set in uiConf:
		if( $this->getResultObject()->getPlayerConfig( null, 'Kaltura.LeadWithHTML5' ) === true
			||
			$this->getResultObject()->getPlayerConfig( null, 'KalturaSupport.LeadWithHTML5' ) === true
		){
			$o.="\n".'mw.setConfig(\'Kaltura.LeadWithHTML5\', true );';
		}
		if( $this->getResultObject()->getPlayerConfig( null, 'Kaltura.ForceFlashOnIE10' ) === true ){
			$o.="\n".'mw.setConfig(\'Kaltura.ForceFlashOnIE10\', true );' . "\n";
		} 
		
		// Only include on page plugins if not in iframe Server
		if( !isset( $_REQUEST['iframeServer'] ) ){
			$o.= $mweUiConfJs->getPluginPageJs( 'kWidget.inLoaderUiConfJsCallback' );
		} else{
			$o.='kWidget.inLoaderUiConfJsCallback();';
		}
		// set the flag so that we don't have to request the services.php
		$o.= "\n" . 'kWidget.uiConfScriptLoadList[\'' . 
			$mweUiConfJs->getResultObject()->urlParameters ['uiconf_id' ] .
			'\'] = 1; ' ;
		return $o;
	}
	/**
	* The result object grabber, caches a local result object for easy access
	* to result object properties.
	*/
	function getResultObject(){
		global $wgMwEmbedVersion;
		if( ! $this->resultObject ){
			require_once( dirname( __FILE__ ) .  '/modules/KalturaSupport/KalturaUiConfResult.php' );
			try {
				// Init a new result object with the client tag:
				$this->resultObject = new KalturaUiConfResult( 'html5iframe:' . $wgMwEmbedVersion );
			} catch ( Exception $e ){
				// don't throw any exception just return false;
				// any uiConf level exception should not block normal loader response
				// the error details will be displayed in the player
				return false;
			}
		}
		return $this->resultObject;
	}
	
	private function getMinCombinedLoaderJs(){
		global $wgHTTPProtocol, $wgMwEmbedVersion, $wgResourceLoaderMinifierStatementsOnOwnLine;
		$key = '/loader_' . $wgHTTPProtocol . '.min.' . $wgMwEmbedVersion . '.js' ;
		$cacheJS = $this->getCacheFileContents( $key );
		if( $cacheJS !== false ){
			return $cacheJS;
		}
		// Else get from files: 
		$rawScript = $this->getCombinedLoaderJs();
		// Get the JSmin class:
		require_once( realpath( dirname( __FILE__ ) ) . '/includes/libs/JavaScriptMinifier.php' );
		$minjs = JavaScriptMinifier::minify( $rawScript, $wgResourceLoaderMinifierStatementsOnOwnLine );
		// output the file to the cache:
		$this->outputFileCache( $key, $minjs);
		// return the minified js:
		return $minjs;
	}
	function outputFileCache( $key, $js ){
		$path = $this->getCacheFilePath( $key );
		$pathAry = explode( "/", $path );
		$pathDir = implode( '/', array_slice( $pathAry, 0, count( $pathAry ) - 1 ) );
		// Create cache directory if not exists
		if( ! is_dir( $pathDir ) ) {
			$created = @mkdir( $pathDir, 0777, true );
			if( ! $created ) {
				$this->setError( 'Error in creating cache directory' );
				return ;
			}
		}
		// make sure the path exists
		if( !@file_put_contents( $path, $js ) ){
			$this->setError( 'Error outputting file to cache');
		}
	}
	private function getCombinedLoaderJs(){
		global $wgResourceLoaderUrl, $wgMwEmbedVersion;
		$loaderJs = '';
		
		// Output all the files
		foreach( $this->loaderFileList as $file ){
			$loaderJs .= file_get_contents( $file );
		}
		
		return $loaderJs;
	}
	private function getExportedConfig(){
		global $wgEnableScriptDebug, $wgResourceLoaderUrl, $wgMwEmbedVersion, $wgMwEmbedProxyUrl, $wgKalturaUseManifestUrls,
			$wgKalturaUseManifestUrls, $wgHTTPProtocol, $wgKalturaServiceUrl, $wgKalturaServiceBase,
			$wgKalturaCDNUrl, $wgKalturaStatsServiceUrl, $wgKalturaIframeRewrite, $wgEnableIpadHTMLControls,
			$wgKalturaAllowIframeRemoteService, $wgKalturaUseAppleAdaptive, $wgKalturaEnableEmbedUiConfJs,
			$wgKalturaGoogleAnalyticsUA;
		$exportedJS ='';
		// Set up globals to be exported as mwEmbed config:
		$exportedJsConfig= array(
			'debug' => $wgEnableScriptDebug,
			'Mw.XmlProxyUrl' => $wgMwEmbedProxyUrl,
			'Kaltura.UseManifestUrls' => $wgKalturaUseManifestUrls,
			'Kaltura.Protocol'	=>	$wgHTTPProtocol,
			'Kaltura.ServiceUrl' => $wgKalturaServiceUrl,
			'Kaltura.ServiceBase' => $wgKalturaServiceBase,
			'Kaltura.CdnUrl' => $wgKalturaCDNUrl,
			'Kaltura.StatsServiceUrl' => $wgKalturaStatsServiceUrl,
			'Kaltura.IframeRewrite' => $wgKalturaIframeRewrite,
			'EmbedPlayer.EnableIpadHTMLControls' => $wgEnableIpadHTMLControls,
			'EmbedPlayer.UseFlashOnAndroid' => true,
			'Kaltura.LoadScriptForVideoTags' => true,
			'Kaltura.AllowIframeRemoteService' => $wgKalturaAllowIframeRemoteService,
			'Kaltura.UseAppleAdaptive' => $wgKalturaUseAppleAdaptive,
			'Kaltura.EnableEmbedUiConfJs' => $wgKalturaEnableEmbedUiConfJs,
			'Kaltura.PageGoogleAalytics' => $wgKalturaGoogleAnalyticsUA,
		);
		if( isset( $_GET['pskwidgetpath'] ) ){
			$exportedJsConfig[ 'Kaltura.KWidgetPsPath' ] = htmlspecialchars( $_GET['pskwidgetpath'] );
		}
		
		// Append Custom config:
		foreach( $exportedJsConfig as $key => $val ){
			// @@TODO use a library Boolean conversion routine:
			$val = ( $val === true )? $val = 'true' : $val;
			$val = ( $val === false )? $val = 'false' : $val;
			$val = ( $val != 'true' && $val != 'false' )? "'" . addslashes( $val ) . "'": $val;
			$exportedJS .= "mw.setConfig('". addslashes( $key ). "', $val );\n";
		}
		return $exportedJS;
	}
	// Kaltura Comment
	private function getLoaderHeader(){
		global $wgMwEmbedVersion, $wgResourceLoaderUrl, $wgMwEmbedVersion;
		$o = "/**
* Kaltura HTML5 Library v$wgMwEmbedVersion  
* http://html5video.org/kaltura-player/docs/
* 
* This is free software released under the GPL2 see README more info 
* http://html5video.org/kaltura-player/docs/readme
* 
* Copyright " . date("Y") . " Kaltura Inc.
*/\n";
		// Add the library version:
		$o .= "window['MWEMBED_VERSION'] = '$wgMwEmbedVersion';\n";

		// Append ResourceLoder path to loader.js
		$o.= "window['SCRIPT_LOADER_URL'] = '". addslashes( $wgResourceLoaderUrl ) . "';\n";

		return $o;
	}
	/** send the cdn headers */
	private function sendHeaders(){
		global $wgEnableScriptDebug;
		header("Content-Type: text/javascript");
		if( isset( $_GET['debug'] ) || $wgEnableScriptDebug ){
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Pragma: no-cache");
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
		} else {
			// Default expire time for the loader to 3 hours ( kaltura version always have diffrent version tags; for new versions )
			$max_age = 60*60*3;
			// if the loader request includes uiConf set age to 10 min ( uiConf updates should propgate in ~10 min )
			if( isset( $this->getResultObject()->urlParameters [ 'uiconf_id' ] ) ){
				$max_age = 60*10;
			}
			// Check for an error ( only cache for 60 seconds )
			if( $this->getError() ){
				$max_age = 60; 
			}
			header("Cache-Control: public, max-age=$max_age max-stale=0");
			header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $max_age) . 'GMT');
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . 'GMT');
		}
	}
}
