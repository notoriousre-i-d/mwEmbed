/**
 * Base ad plugin allows plugins to inheret some the base ad plugin functionality.
 */
mw.BaseAdPlugin = function( embedPlayer, callback){
	return this.init( embedPlayer, callback );
};

mw.BaseAdPlugin.prototype = {
		
	init: function( embedPlayer, callback ){
		var _this = this;
		_this.embedPlayer = embedPlayer;
		// Should extend "BasePlugin" ( but we have to write that first ) 
		
		// Remove all ad bindings on change media:
		$( _this.embedPlayer ).bind( 'onChangeMedia' + _this.bindPostfix, function( event ) {
			debugger;
			_this.destroy();
		}); 
	},
	/**
	 * gets the target index for a given sequence item
	 */
	getSequenceIndex: function( slotType ){
		var _this = this;
		if( slotType == 'preroll' && _this.getConfig('preSequence')) {
			return _this.getConfig('preSequence');
		}
		if( slotType == 'postroll' && _this.getConfig('postSequence')) {
			return _this.getConfig('postSequence');
		}
		// TODO WHAT DOES FLASH DO? If a plugin has preroll cuepoint what place in the sequence proxy does it take?  
		// for now we just add it to slot 1.
		// What about multiple cuepoints? 
		return 1;
	},
	destroy: function(){
		// Remove player bindings: 
		$( this.embedPlayer ).unbind( this.bindPostfix );
	}
};
