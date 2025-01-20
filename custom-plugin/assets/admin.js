//Changes pending for pitney-bowes to shippo change.
(function ($) {
    'use strict';
    $('#wdm_order_status_select').select2();
    $('#wdm_eligible_shipping_class_select').select2();
    $('.wdm_reset_order').on('click', function (event) {
        event.preventDefault();
        let order_id = $(this).data('id');
        $(this).text('Processing....');
        Swal.fire({
            title: 'Resetting...',
            html: 'Please wait...',
            allowEscapeKey: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading()
            }
        });
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_reset_order',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                location.reload();
                Swal.close();
            },
            error: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: response.responseJSON.data.errorCode,
                    html: response.responseJSON.data.message,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                });
                let selector = '.wdm_reset_order[data-id=' + order_id + ']';
                $(selector).text('Reset Order');

                Swal.hideLoading();

            }
        })

    });
    $('.wdm_gen_label').on('click', function (event) {
        event.preventDefault();
        let order_id = $(this).data('id');
        $(this).text('Processing....');
        Swal.fire({
            title: 'Processing...',
            html: 'Please wait...',
            allowEscapeKey: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading()
            }
        });
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_gen_label',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                location.reload();
                // let selector = '.wdm_gen_label[data-id=' + order_id + ']';
                // let new_html = '<a href= "data:image/png;base64,' + response.data + '" download="shipping_label_' + order_id + '.png">Download Shipping Label</a>';
                // $(selector).replaceWith( new_html );
                Swal.close();
            },
            error: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: response.responseJSON.data.errorCode,
                    html: response.responseJSON.data.message,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                });
                let selector = '.wdm_gen_label[data-id=' + order_id + ']';
                $(selector).text('Generate Label');

                Swal.hideLoading();

            }

        });
    });

    $('#wdm_submit_button').on('click', function () {
        let wdm_selected_order_status = $('#wdm_order_status_select').val();
        let wdm_eligible_shipping_classes = $('#wdm_eligible_shipping_class_select').val();
        let wdm_shipping_partner_select = $('#wdm_shipping_partner_select').val();
        let wdm_easypost_pdf_size = $('#wdm_easypost_pdf_size').val();
        let wdm_default_printing_line = $('#wdm_default_printing_line').val();
        let wdm_usps_test_mode = $('#wdm_usps_test_mode').val();
        let wdm_shippo_test_mode = $('#wdm_shippo_test_mode').val();
        let wdm_easypost_test_mode = $('#wdm_easypost_test_mode').val();
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_save_eligible_order_status',
                wdm_selected_order_status: wdm_selected_order_status,
                wdm_eligible_shipping_classes: wdm_eligible_shipping_classes,
                wdm_shipping_partner_select: wdm_shipping_partner_select,
                wdm_easypost_pdf_size: wdm_easypost_pdf_size,
                wdm_default_printing_line: wdm_default_printing_line,
                wdm_usps_test_mode: wdm_usps_test_mode,
                wdm_shippo_test_mode: wdm_shippo_test_mode,
                wdm_easypost_test_mode: wdm_easypost_test_mode,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                console.log(response);
            },
            error: function (response) {
                console.log(response);
            }

        });
    });

    $('#wdm-regenerate-order').on('click', function (event) {
        event.preventDefault();
        let order_id = $(this).data('order-id');
        let options = $(this).data('products');
        let machine_type = $(this).data('machine-types');
        let prev_mt = $(this).data('prev-mt');
        if (prev_mt !== '') {
            prev_mt = '<p>Previous Machine Type:' + prev_mt + '</p>';
        }
        Swal.fire({
            title: 'Cloning',
            html: 'Select Replacement Product:<select id="cloning-select" class="form-control">' + options + '</select><br><br>Select Machine Type<br><select id="machine-type-select" class="form-control">' + machine_type + '</select>' + prev_mt,
            showCloseButton: true,
            showCancelButton: true,
            cancelButtonText: 'Cancel',
            confirmButtonText: 'Clone',
            showLoaderOnConfirm: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            preConfirm: function () {
                let cloning_select = $('#cloning-select').val();
                if (!cloning_select) {
                    Swal.showValidationMessage('Please select a cloning option.');
                }
                let machine_type_val = $('#machine-type-select').val();
                if (!machine_type_val) {
                    Swal.showValidationMessage('Please select a machine type.');
                }
                return {
                    prd: cloning_select,
                    mt: machine_type_val
                }
            }
        }).then(function (result) {
            if (result.value) {
                $.ajax({
                    type: "post",
                    url: ajaxobj.url,
                    data: {
                        action: 'wdm_clone_order',
                        order_id: order_id,
                        product_id: result.value.prd,
                        machine_type: result.value.mt,
                        nonce: ajaxobj.nonce,
                    },
                    success: function (response) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Zero-dollar order generated.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                        });
                    },
                    error: function (response) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            html: response.responseJSON.data,
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            allowEnterKey: false,
                        });
                    }
                });
            }
        });
    });
    $('#wdm-refund-order').on('click', function (event) {
        Swal.fire({
            title: 'Creating Refund...',
            html: 'Please wait...',
            allowEscapeKey: false,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading()
            }
        });
        let order_id = $(this).data('order_id');
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_refund_order',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                $('#wdm_cstm_row').remove();
                $('.wc-order-totals-items').hide();
                $('.wc-order-bulk-actions').hide();
                $('.wc-order-refund-items').show();
                $('.refund').show();
                $('.refund_line_total').show();
                $('.do-manual-refund').hide();
                Swal.close();
            },
            error: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: response.responseJSON.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                });
            }
        });

    });
    $('#wdm-extend-return-period').on('click', function (event) {
        event.preventDefault();
        let order_id = $(this).data('order-id');
        Swal.fire({
            title: 'Extending Return Period...',
            html: '<p>Please wait...</p>',
            allowEscapeKey: false,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading()
            }
        })
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_extend_return_period',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'Return period extended.',
                    html: response.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            },
            error: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: response.responseJSON.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            }
        });
    });
    $('#wdm-charge-partial-return-fee').on('click', function (event) {
        event.preventDefault();
        let order_id = $(this).data('order-id');
        Swal.fire({
            title: 'Charging Partial Return Fee...',
            html: '<p>Please wait...</p>',
            allowEscapeKey: false,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading()
            }
        })
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_charge_partial_return_fee',
                order_id: order_id,
                sub_action: 'charge',
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                console.log(response);
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'Partial return fee charged.',
                    html: response.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            },
            error: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: response.responseJSON.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                });
            }
        })

    });

    $('#wdm-convert-to-no-exchange').on('click', function (event) {
        event.preventDefault();
        let order_id = $(this).data('order-id');
        Swal.fire({
            title: 'Converting to no-exchange...',
            html: '<p>Please wait...</p>',
            allowEscapeKey: false,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading()
            }
        })
        $.ajax({
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_charge_partial_return_fee',
                sub_action: 'convert',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                console.log(response);
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'Converted',
                    html: response.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            },
            error: function (response) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: response.responseJSON.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                });
            }
        })

    })

    $('.refund-items').on('click', function () {
        if ( $('.wc-order-refund-items .wc-order-totals').length > 0 && ajaxobj.is_exchange_order_page === 'true' ) {
            $('.wc-order-refund-items .wc-order-totals tr:first').before('<tr id="wdm_cstm_row"><td><label for="include_non_return_fee">Include Non-Return Fee</label></td><td><input type="checkbox" id="include_non_return_fee" checked></td></tr>');
            include_non_return_fee();
            $('#include_non_return_fee').on('change', include_non_return_fee);
        }
        $('.refund_order_item_qty').each(function () {
            let max = $(this).attr('max');
            $(this).val(max);
            $(this).trigger('change');
        })
        let refund_cost_elements = $('[name^="refund_line_total[');

            for (let i = 0; i < refund_cost_elements.length; i++) {
                let name = $(refund_cost_elements[i]).attr('name').replace('refund_line_total[','shipping_cost[');
                let element = $(`[name="${name}"]`);
                if(element.length === 1) {
                    let val = $(element[0]).val();
                    $(refund_cost_elements[i]).val(val);
                    $(refund_cost_elements[i]).trigger('change');
                }
                if (element.length === 0) {
                    $(refund_cost_elements[i]).hide();
                }
            }
        

    });
    $('.refund-items').on('click', function (event) {
        let order_id = $('#post_ID').val();
        $.ajax({
            async: false,
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_check_session',
                order_id: order_id,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                $('.do-manual-refund').show();
            },
            error: function (response) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: response.responseJSON.data,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                });
            }
        })
    })
    function include_non_return_fee() {
        Swal.fire({
            title: 'Updating...',
            html: 'Please wait...',
            allowEscapeKey: false,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading()
            }
        })
        let order_id = $('#post_ID').val();
        let checked = $('#include_non_return_fee').is(':checked');
        $.ajax({
            async: false,
            type: "post",
            url: ajaxobj.url,
            data: {
                action: 'wdm_include_non_return_fee',
                order_id: order_id,
                wdm_checked: checked,
                nonce: ajaxobj.nonce,
            },
            success: function (response) {
                Swal.close();
            }
        });
    }
})(jQuery);

