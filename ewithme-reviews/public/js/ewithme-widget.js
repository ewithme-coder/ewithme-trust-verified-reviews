/**
 * Ewithme Trust Reviews — Frontend Widget JavaScript
 */

(function ($) {
  'use strict';

  $(document).ready(function () {
    // 1. Write Review Modal Trigger (Open)
    $(document).on('click', '.ew-open-modal-btn', function (e) {
      e.preventDefault();
      var $container = $(this).closest('.ew-reviews-container');
      $container.find('.ew-write-review-modal').fadeIn(200);
      $container.find('.ew-form-status').hide();
    });

    // 1b. Self-Service Author Erasure Modal Trigger (Open)
    $(document).on('click', '.ew-open-erase-modal-btn', function (e) {
      e.preventDefault();
      var $container = $(this).closest('.ew-reviews-container');
      $container.find('.ew-erase-review-modal').fadeIn(200);
      $container.find('.ew-erase-status').hide();
    });

    // 2. Modal Close
    $(document).on('click', '.ew-modal-close', function (e) {
      e.preventDefault();
      $(this).closest('.ew-modal-overlay').fadeOut(150);
    });

    // Close on background click
    $(document).on('click', '.ew-modal-overlay', function (e) {
      if ($(e.target).hasClass('ew-modal-overlay')) {
        $(this).fadeOut(150);
      }
    });

    // 3. Interactive Star Rating Picker
    $(document).on('click', '.ew-star-opt', function () {
      var val = $(this).data('val');
      var $group = $(this).closest('.ew-form-group');
      $group.find('input[name="rating"]').val(val);

      $group.find('.ew-star-opt').removeClass('active');
      $(this).addClass('active');
    });

    // Set initial 5-star state
    $('.ew-star-opt[data-val="5"]').addClass('active');

    // 4. Review Submission (Saves locally & issues capability token, then dispatches verification)
    $(document).on('submit', '.ew-review-form', function (e) {
      e.preventDefault();

      var $form = $(this);
      var $btn = $form.find('.ew-submit-btn');
      var $status = $form.find('.ew-form-status');
      var originalBtnText = $btn.text();

      $btn.prop('disabled', true).text('Submitting review...');
      $status.hide().removeClass('success error');

      var formData = $form.serializeArray();
      var postData = {};
      $.each(formData, function (i, field) {
        postData[field.name] = field.value;
      });

      var isIncentivized = $form.find('input[name="is_incentivized"]').is(':checked');
      postData.is_incentivized = isIncentivized ? 1 : 0;
      postData.action = 'ewithme_submit_review';
      postData.nonce = typeof ewithmeReviewsData !== 'undefined' ? ewithmeReviewsData.nonce : '';

      $.ajax({
        url: typeof ewithmeReviewsData !== 'undefined' ? ewithmeReviewsData.ajax_url : '/wp-admin/admin-ajax.php',
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function (res) {
          $btn.prop('disabled', false).text(originalBtnText);
          if (res && res.success) {
            var successMsg = (res.data && res.data.message)
              ? res.data.message
              : 'Thank you! A confirmation link has been sent to your email to verify your review.';
            $status.addClass('success').text(successMsg).fadeIn();
            $form[0].reset();
            $('.ew-star-opt[data-val="5"]').click();
          } else {
            var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Failed to submit review. Please try again.';
            $status.addClass('error').text(errMsg).fadeIn();
          }
        },
        error: function (xhr) {
          $btn.prop('disabled', false).text(originalBtnText);
          var errMsg = 'An unexpected network error occurred. Please check your connection.';
          if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errMsg = xhr.responseJSON.data.message;
          }
          $status.addClass('error').text(errMsg).fadeIn();
        }
      });
    });

    // 5. Author Self-Service Cryptographic Erasure via Capability Token (WS7 / GDPR)
    $(document).on('submit', '.ew-erase-form', function (e) {
      e.preventDefault();

      var $form = $(this);
      var $btn = $form.find('.ew-erase-submit-btn');
      var $status = $form.find('.ew-erase-status');
      var originalBtnText = $btn.text();

      $btn.prop('disabled', true).text('Erasing review...');
      $status.hide().removeClass('success error');

      var eraseData = {
        action: 'ewithme_author_erase_review',
        nonce: typeof ewithmeReviewsData !== 'undefined' ? ewithmeReviewsData.nonce : '',
        erasure_token: $form.find('input[name="erasure_token"]').val(),
        certificate_id: $form.find('input[name="certificate_id"]').val()
      };

      $.ajax({
        url: typeof ewithmeReviewsData !== 'undefined' ? ewithmeReviewsData.ajax_url : '/wp-admin/admin-ajax.php',
        type: 'POST',
        data: eraseData,
        dataType: 'json',
        success: function (res) {
          $btn.prop('disabled', false).text(originalBtnText);
          if (res && res.success) {
            var msg = (res.data && res.data.message)
              ? res.data.message
              : 'Your review plaintext and personal data have been cryptographically erased.';
            $status.addClass('success').text(msg).fadeIn();
            $form[0].reset();
          } else {
            var errMsg = (res && res.data && res.data.message)
              ? res.data.message
              : 'Invalid or expired erasure capability token.';
            $status.addClass('error').text(errMsg).fadeIn();
          }
        },
        error: function (xhr) {
          $btn.prop('disabled', false).text(originalBtnText);
          var errMsg = 'Failed to process erasure request. Please try again.';
          if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errMsg = xhr.responseJSON.data.message;
          }
          $status.addClass('error').text(errMsg).fadeIn();
        }
      });
    });
  });
})(jQuery);
