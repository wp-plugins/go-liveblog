(function( $ ) {
	var defaults = {
		comment_id_prefix : 'comment-',
		comment_selector  : '.go-liveblog .comment',
		container_selector: '.go-liveblog',
		end               : null,
		start             : null,
		form_selector     : '#go_liveblog',
		post_id           : null,
		post_id_prefix    : 'post-',
		refresh           : 15,
		url               : null,
		gmt_offset        : null
	};

	var options = {};
	var poll_state = 'pending';
	var previous_state = null;
	var date_end = null;
	var date_start = null;
	var has_updates = false;

	var methods = {
		cache_add_updates: function( $cached, html ) {
			$cached.find( '.updates' ).append( html );
			var num_cached = $cached.find( '.updates > li' ).length;

			$cached.find( '.number' ).html( num_cached );

			if ( 1 === num_cached ) {
				$cached.find( '.plural:visible' ).hide();
			} else if ( 1 < num_cached ) {
				$cached.find( '.plural:hidden' ).show();
			} else {
				if ( $cached.is( ':visible' ) ) {
					$cached.fadeOut('fast');
				}//edn if
			}//end else
		},
		cache_clear_updates: function( $el ) {
			var $cached = $el.find( '.cached' );

			var updates = $el.find( '.updates > li' );
			$cached.after( updates );

			if ( $cached.is( ':visible' ) ) {
				$cached.fadeOut('fast');
			}//end if

			$cached.find( '.updates' ).find( '.number' ).html( 0 );
			$cached.find( '.plural:hidden' ).show();
		},
		/**
		 * retrieves live update payload from endpoint
		 */
		get: function() {
			var paused  = 'paused'  === poll_state;
			var polling = 'polling' === poll_state;
			var expired = 'polling' === poll_state;

			var now = methods.now();
			var updates_expired = date_end ? now >= date_end : false;
			var updates_pending = now < date_start;

			if ( polling || date_end ) {
				if( ( ! updates_pending && ! updates_expired ) ) {
					// Polling!
					poll_state = 'polling';
				} else if( updates_expired ) {
					// Polling has expired. Don't poll again.
					poll_state = 'expired';
					return;
				} else {
					// Polling is still pending the start time. Check back later.
					methods.poll();
					return;
				}//end else
			}//end if

			// make the request and set the request promise object
			var request = $.getJSON( options.url + methods.recent_comment() );

			$.when( request ).done( function( data ) {
				// when a request is complete, parse the data
				$( options.container_selector ).goLiveblog('parse_response', data );
				
				// prepare for another polling!
				methods.poll();
			});
		},
		/**
		 * initializes the goLiveblog plugin
		 */
		init: function( params ) {
			options = $.extend( defaults, params );

			// set plugin-options
			options.container_selector = this.selector;
			options.post_id = this.closest('.post').attr('id').split( options.post_id_prefix )[1];
			options.url = go_liveblog.ep_url;
			options.ajax_url = go_liveblog.ajax_url;

			var now = new Date();

			date_end   = options.end   ? new Date( options.end )   : null;
			date_start = options.start ? new Date( options.start ) : methods.now();

			Handlebars.registerHelper('formatDate', function( date ) {
				date = parseInt( date ) * 1000 ;
				date = date + ( parseInt( options.gmt_offset ) * 3600000 );
				date = new Date( date );

				var ampm = 'am';
				var hours = parseInt( date.getHours() );
				if ( hours > 12 ) {
					hours -= 12;
					ampm = 'pm';
				}//end if

				return hours + ':' + date.getMinutes() + ampm;
			});

			if ( $( '.go-liveblog' ).find( '.comment' ).length > 0 ) {
				has_updates = true;
			}//end if

			methods.response_cleanup();
			methods.poll();
		},
		/**
		 * retrieves the current UTC date
		 */
		now: function() {
			var now = new Date();
			now = new Date(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(),  now.getUTCHours(), now.getUTCMinutes(), now.getUTCSeconds());
			return now;
		},
		/**
		 *
		 */ 
		parse_response: function( data ) {
			// if there aren't any updates, get out of here
			if( ! ( data && data.updates ) ) {
				return;
			}//end if

			if ( ! has_updates ) {
				$( '.go-liveblog .not-started' ).hide();
				has_updates = true;
			}//end if

			var source = $('#liveblog-template').html();
			var template = Handlebars.compile( source );
			var html = template( data );

			if ( data.incomplete ) {
				var more_source = $('#liveblog-more').html();
				var more_template = Handlebars.compile( more_source );
				var more_html = template( data );
				this.prepend( more_html );
			}//end if

			var $cached = this.find( '.cached' );

			if ( 'paused' === poll_state ) {
				methods.cache_add_updates( $cached, html );
				$cached.fadeIn('fast');
			} else {
				$cached.after( html );
			}//end else

			methods.response_cleanup();
		},
		pause: function() {
			$( this )
				.addClass( 'paused' )
				.find( '.liveblog-controls' )
				.find( '.text' )
				.html( 'Play Stream' )
				.closest( '.liveblog-controls' )
				.find( '.toggle' )
				.addClass( 'play' );

			previous_state = poll_state;
			poll_state = 'paused';
		},
		/**
		 * sets up the poll timer
		 */ 
		poll: function() {
			$.doTimeout( 'go-liveblog-poll', options.refresh * 1000, methods.get );
		},
		post: function() {
			var $form = $('#commentform');
			var $submit = $form.find('input[type=submit]');
			var $throbber = $form.find( options.form_selector + '-throbber' ).hide();

			$submit.attr('disabled', 'disabled');
			$form.find( options.form_selector + '-error:visible').hide();
			$throbber.show();

			$form.find('#comment_id').val( methods.recent_comment() );

			var params = {
				action: 'go_liveblog_insert',
				comment_id: methods.recent_comment(),
				post_id: options.post_id,
				comment: $form.find( options.form_selector + '_comment' ).val(),
				_wp_http_referer: $form.find( 'input[name=_wp_http_referer]' ).val(),
				go_liveblog_nonce: $form.find('#go_liveblog_nonce').val()
			};

			var jqxhr = $.post(
				options.ajax_url,
				params
			);

			$.when( jqxhr )
				.fail( function( data ) {
					$submit.removeAttr('disabled');
					$submit.find( options.form_selector + '-error').fadeIn('fast');
					$throbber.hide();
					$submit.find('textarea').html('').focus();
				})
				.done( function( data ) {
					data = $.parseJSON( data );

					$submit.removeAttr('disabled');
					$throbber.hide();
					$form.find('textarea').val('').focus();

					$( options.container_selector ).goLiveblog( 'parse_response', data );
				});
		},
		recent_comment: function() {
			var $comment = $( options.comment_selector + ':first');
			var comment_id = 0;

			if ( $comment.length ) {
				comment_id = $comment.attr('id').split( options.comment_id_prefix )[1];
			}//end if

			return comment_id;
		},
		response_cleanup: function() { 
			$( options.container_selector + ' .linkedin-script' ).each( function() {
				var $el = $(this);
				$el.after('<script type="' + $el.data('type') + '" data-url="' + $el.data('url') + '"></script>');
				$el.remove();
			});

			//go_refresh_comment_stream( $ );
		},
		unpause: function() {
			$( this )
				.removeClass( 'paused' )
				.find( '.liveblog-controls' )
				.find( '.text' )
				.html( 'Pause Stream' )
				.closest( '.liveblog-controls' )
				.find( '.toggle' )
				.removeClass( 'play' );

			poll_state = previous_state;
			methods.cache_clear_updates( this.find( '.go-liveblog' ) ); 
		}
	};

	$.fn.goLiveblog = function( method ) {
		if ( methods[ method ] ) {
			return methods[ method ].apply( this, Array.prototype.slice.call( arguments, 1 ) );
		} else if ( typeof method === 'object' || ! method ) {
			return methods.init.apply( this, arguments );
		} else {
			$.error( 'Method ' +  method + ' does not exist on jQuery.goLiveblog' );
		}//end if
	};
})( jQuery );
