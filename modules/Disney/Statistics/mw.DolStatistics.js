/*
* DolStatistics plugin
*/

mw.DolStatistics = function( embedPlayer, callback ){
	this.init( embedPlayer, callback );
};

mw.DolStatistics.prototype = {

	pluginVersion: "1.0",
	bindPostFix: '.DolStatistics',
	appName: 'KDP',

	// Increased on change media
	playbackCounter: 1,

	// Number of seconds between playhead event dispatches
	playheadFrequency: 5,
	playheadInterval: 0,

	// Entry duration
	duration: 0,

	// hold list of cue points per 10% of video duration
	percentCuePoints: {},

	init: function( embedPlayer, callback ){
		this.embedPlayer = embedPlayer;

		// List of all attributes we need from plugin configuration (flashVars/uiConf)
		var attributes = [
			'listenTo',
			'playheadFrequency',
			'jsFunctionName',
			'protocol',
			'host',
			'ASSETNAME',
			'GENURL',
			'GENTITLE',
			'DEVID',
			'USRAGNT',
			'ASSETID'
		];
		
		this.pluginConfig = this.embedPlayer.getKalturaConfig( 'dolStatistics', attributes );

		this.playheadFrequency = this.pluginConfig.playheadFrequency || 5;

		// List of events we need to track
		this.eventsList = this.pluginConfig.listenTo.split(",");

		mw.log('DolStatistics:: Init plugin :: Plugin config: ', this.pluginConfig);

		// Add player binding
		this.addPlayerBindings( callback );
	},

	addPlayerBindings: function( callback ) {
		var _this = this;
		var embedPlayer = this.embedPlayer;
		var $embedPlayer = $( embedPlayer );
		
		// On change media remove any existing ads:
		$embedPlayer.bind( 'onChangeMedia' + _this.bindPostFix, function(){
			_this.playbackCounter++;
			_this.destroy();
		});

		// Register to our events
		$.each(this.eventsList, function(k, eventName) {

			switch( eventName ) {

				// Special event
				case 'percentReached':
					_this.calcCuePoints();
					$embedPlayer.bind( 'monitorEvent' + _this.bindPostFix, function() {
						_this.monitorPercentage();
					});
				break;

				// Change playerUpdatePlayhead event to send events on playheadFrequency
				case 'playerUpdatePlayhead':
					_this.addMonitorBindings();
				break;

				// Use addJsListener for all other events
				default:
					embedPlayer.addJsListener(eventName, function() {
						var eventData = '';
						for( var x = 0; x < arguments.length; x++ ) {
							eventData += arguments[x] + ",";
						}
						eventData = eventData.substr(0, eventData.length-1);
						_this.sendStatsData(eventName, eventData);
					});
				break;
			}
			
		});

		mw.log('DolStatistics:: addPlayerBindings:: Events list: ', this.eventsList);

		// release the player
		callback();
	},

	/* Create Index of Cue Points per 10% of video duration */
	calcCuePoints: function() {
		var _this = this;
		var duration = this.getDuration();

		for( var i=0; i<=100; i=i+10 ) {
			var cuePoint = Math.round( duration / 100 * i);
			if( cuePoint === 0 ) continue;
			_this.percentCuePoints[ cuePoint ] = false;
		}

		mw.log('DolStatistics:: calcCuePoints:: ', _this.percentCuePoints);
	},

	/* Custom percentReached event */
	monitorPercentage: function() {
		var _this = this;
		var duration = this.getDuration();
		var percentCuePoints = this.percentCuePoints;
		var currentTime = Math.round(this.embedPlayer.currentTime);

		if( percentCuePoints[ currentTime ] === false ) {
			percentCuePoints[ currentTime ] = true;
			var percent = Math.round(currentTime * 100 / duration);
			_this.sendStatsData('percentReached', percent);
		}
	},

	/* Custom playerUpdatePlayhead event */
	addMonitorBindings: function() {
		var _this = this;
		var embedPlayer = this.embedPlayer;
		var $embedPlayer = $( embedPlayer );
		var intervalTime = this.playheadFrequency * 1000;
		
		// Start monitor
		$embedPlayer.bind('onplay' + _this.bindPostFix, function() {
			if( ! this.playheadInterval ) {
				this.playheadInterval = setInterval( function(){
					console.log('playheadUpdated:: current time: ' + embedPlayer.currentTime);
					_this.sendStatsData( 'playerUpdatePlayhead' , embedPlayer.currentTime);
				}, intervalTime );
			}
		});

		// Stop monitor
		$embedPlayer.bind('doStop' + _this.bindPostFix + ' onpause' + _this.bindPostFix, function() {
			clearInterval( this.playheadInterval );
			this.playheadInterval = 0;
		});
	},

	/* Retrive video duration */
	getDuration: function() {
		if( ! this.duration )
			this.duration = this.embedPlayer.evaluate('{duration}');

		return this.duration;
	},

	/* Send stats data using Beacon or jsCallback */
	sendStatsData: function( eventName, eventData ) {
		var _this = this;
		// If event name not in our event list, exit
		if( this.eventsList.indexOf(eventName) === -1 ) {
			return ;
		}
		
		// Setup event params
		var params = {};
		// App name
		params['app'] = this.appName;
		// Grab from plugin config
		var configAttrs = [ 'GENURL', 'GENTITLE', 'DEVID', 'USRAGNT', 'ASSETNAME', 'ASSETID' ];
		for(var x=0; x<configAttrs.length; x++) {
			params[ configAttrs[x] ] = _this.pluginConfig[ configAttrs[x] ] || '';
		}
		// Current Timestamp
		params['GENTIME'] = new Date().getTime();
		// Widget ID
		params['WIGID'] = this.embedPlayer.kwidgetid;
		// Flavor Bitrate
		params['BITRATE'] = this.embedPlayer.mediaElement.selectedSource.getBitrate();
		// Video length
		params['VIDLEN'] = this.getDuration();
		// Player protocol
		params['KDPPROTO'] = this.pluginConfig.protocol;
		// Kaltura Player ID
		params['KDPID'] = this.embedPlayer.kuiconfid;
		// Kaltura Seesion ID
		params['KSESSIONID'] = this.embedPlayer.evaluate('{configProxy.sessionId}');
		// Kaltura Playback ID ( kSessionId + playbackCounter )
		params['KPLAYBACKID'] = this.embedPlayer.evaluate('{configProxy.sessionId}') + this.playbackCounter;
		// Kaltura Event name
		params['KDPEVNT'] = eventName;
		// KDP Event Data
		if( eventData )
			params['KDPDAT_VALUE'] = eventData.toString();


		if( window.parent && this.pluginConfig.jsFunctionName ) {
			// If we have acess to parent, call the jsFunction provided
			var callbackName = this.pluginConfig.jsFunctionName;
			this._executeFunctionByName( callbackName, window.parent, params);
		} else {
			// Use beacon to send event data
			var statsUrl = this.pluginConfig.protocol + '://' + this.pluginConfig.host + '/cp?' + $.param(params);
			mw.log('DolStatistics:: Send Stats Data ' + statsUrl, params);
		}
	},

	destroy: function() {
		$( this.embedPlayer ).unbind( this.bindPostFix );
		this.percentCuePoints = {};
		this.duration = 0;
	},

	/* Execure function like: "cto.trackVideo" */
	_executeFunctionByName: function(functionName, context /*, args */) {
		var args = Array.prototype.slice.call(arguments).splice(2);
		var namespaces = functionName.split(".");
		var func = namespaces.pop();
		for(var i = 0; i < namespaces.length; i++) {
			context = context[namespaces[i]];
		}
		return context[func].apply(this, args);
	}
};