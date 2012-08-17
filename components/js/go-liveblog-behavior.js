(function( $ ) {
	$(function( $ ) {
		$('.go-liveblog').goLiveblog('init', {
			gmt_offset: go_liveblog.gmt_offset,
			refresh: go_liveblog.refresh
		});
	});

	$(document).on('submit', '#liveblog-respond form', function( e ) {
		e.preventDefault();

		$(this).goLiveblog('post');
	});

	$(document).on('click', '.liveblog-controls .pause-play a, .go-liveblog .cached .message', function( e ) {
		e.preventDefault();

		var $post = $(this).closest('.post');

		if ( $post.hasClass( 'paused' ) ) {
			$post.goLiveblog('unpause');
		} else {
			$post.goLiveblog('pause');
		}//end else
	});
})(jQuery);
