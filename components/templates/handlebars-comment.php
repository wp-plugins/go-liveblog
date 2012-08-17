<script id="liveblog-template" type="text/x-handlebars-template">
	{{#if updates.length}}
		{{#each updates}}
			<li class="comment update-time-{{date_gmt}}" id="comment-{{comment_id}}" data-comment="{{comment_id}}">
				<div class="go-liveblog-meta">
					<span id="go-liveblog-author-{{author_id}}" class="go-liveblog-meta-author">{{comment_author}}</span>
					at <time>{{formatted_time}}</time>
				</div>
				<div class="go-liveblog-body">
					{{{comment_content}}}
				</div>
			</li>
		{{/each}}
	{{/if}}
</script>
