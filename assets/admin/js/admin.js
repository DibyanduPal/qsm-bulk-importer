/**
 * QSM Bulk Importer — admin fallbacks (added)
 * - Adds a JS confirmation fallback for rollback forms that post to admin-post.php
 * - Prevents double-submission for those forms
 */
(function($){
    'use strict';
    $(function(){
        // Fallback confirmation + double-submit prevention for rollback forms
        $(document).on('submit', 'form.qsm-rollback-form', function(e){
            var $form = $(this);

            // Basic confirm fallback if onclick didn't run for some reason
            if (typeof window.confirm === 'function') {
                var ok = confirm('This will delete the imported questions and cannot be undone. Are you sure?');
                if (!ok) {
                    e.preventDefault();
                    return false;
                }
            }

            // Prevent double submit
            if ($form.data('submitting')) {
                e.preventDefault();
                return false;
            }
            $form.data('submitting', true);

            // Disable submit buttons to show progress
            $form.find('input[type="submit"], button[type="submit"]').prop('disabled', true);
        });
    });
})(jQuery);
