;(function($, window, document){
var $;($=jQuery)(function(){var t=$("#post-views-input-container"),s=$("#post-views-display b"),e=$("#post-views-input"),i=$("#post-views .edit-post-views");i.on("click",function(s){return t.is(":hidden")&&(t.slideDown("fast"),$(s.currentTarget).hide()),!1}),$("#post-views .save-post-views").on("click",function(){var n=s.text().trim();return t.slideUp("fast"),i.show(),n=parseInt(e.val(),10),e.val(n),s.text(n),!1}),$("#post-views .cancel-post-views").on("click",function(){var n=s.text().trim();return t.slideUp("fast"),i.show(),n=parseInt($("#post-views-current").val(),10),s.text(n),e.val(n),!1})});

})(jQuery, window, document);
