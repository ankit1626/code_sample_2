jQuery(document).ready(function ($) {
    $('#send_email_button').on('click', function () {
        var order_id = $(this).data('order-id');
        $.ajax({
            url: ajaxobj.url, // Use the ajaxurl variable provided by WordPress
            type: 'POST',
            data: {
                action: 'send_email_with_pdf',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                let msg = response.data;
                $('#send_email_button').after('<p class="success-message">' + msg + '</p>');
            },
            error: function (error) {
                let msg = error.responseJSON.data;
                $('#send_email_button').after('<p class="error-message">' + msg + '</p>');
            }
        });
    });
});
