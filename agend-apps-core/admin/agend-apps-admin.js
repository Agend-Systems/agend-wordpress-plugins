/* global agendAppsAdminI18n */
( function() {
	var btn = document.getElementById( 'agend-apps-verify-key-btn' );
	if ( ! btn ) {
		return;
	}

	btn.addEventListener( 'click', function() {
		var result  = document.getElementById( 'agend-apps-verify-result' );
		var ajaxUrl = btn.dataset.ajaxUrl;
		var nonce   = btn.dataset.nonce;
		var i18n    = agendAppsAdminI18n;

		btn.disabled         = true;
		result.style.display = 'none';
		result.className     = 'agend-apps-verify-result';
		result.innerHTML     = '';

		var body = new URLSearchParams( {
			action:      'agend_apps_verify_api_key',
			_ajax_nonce: nonce,
		} );

		fetch( ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} )
		.then( function( r ) { return r.json(); } )
		.then( function( response ) {
			if ( ! response.success ) {
				result.classList.add( 'is-error' );
				result.textContent   = response.data && response.data.message
					? response.data.message
					: i18n.verificationFailed;
				result.style.display = 'block';
				return;
			}

			var data    = response.data.data || {};
			var scopes  = Array.isArray( data.scopes ) ? data.scopes : [];
			var apps    = Array.isArray( data.app_ids ) ? data.app_ids : [];
			var keyType = data.key_type || '';

			var html = '<strong>' + i18n.keyVerified + '</strong>';

			if ( keyType ) {
				html += '<br>' + i18n.type + ' ' + keyType;
			}

			if ( scopes.length ) {
				html += '<p><strong>' + i18n.scopes + '</strong><ul>';
				scopes.forEach( function( scope ) {
					html += '<li><code>' + scope + '</code></li>';
				} );
				html += '</ul></p>';
			}

			if ( apps.length ) {
				html += '<p><strong>' + i18n.apps + '</strong><ul>';
				apps.forEach( function( app ) {
					html += '<li><code>' + app + '</code></li>';
				} );
				html += '</ul></p>';
			}

			result.classList.add( 'is-success' );
			result.innerHTML     = html;
			result.style.display = 'block';
		} )
		.catch( function() {
			result.classList.add( 'is-error' );
			result.textContent   = i18n.unexpectedError;
			result.style.display = 'block';
		} )
		.finally( function() {
			btn.disabled = false;
		} );
	} );
} )();
