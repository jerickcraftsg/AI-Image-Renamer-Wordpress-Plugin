jQuery(document).ready(function ($) {

    wp.Uploader.queue.on('reset', function () {
        setTimeout(fetchUploadedImages, 1500);
    });

    $(document).ajaxSuccess(function (event, xhr, settings) {
        if (settings.url && settings.url.indexOf('async-upload.php') !== -1) {
            setTimeout(fetchUploadedImages, 1500);
        }
    });

    function fetchUploadedImages() {
        // Show modal immediately (empty + loading)
        $('#aiir-modal').fadeIn(150);

        // Show loading overlay
        $('.aiir-loading').show();

        // Hide modal content while loading
        $('.aiir-modal-content').hide();

        $.ajax({
            url: AIIR_Ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'aiir_get_uploaded_images',
                nonce: AIIR_Ajax.nonce
            },
            success: function (res) {
                if (res.success && res.data.images.length > 0) {

                    populateModal(res.data.images);

                    // Hide loader
                    $('.aiir-loading').fadeOut(150);

                    // Show the modal content
                    $('.aiir-modal-content').fadeIn(150);

                } else {

                    // No images found — hide loading, show message
                    $('.aiir-loading').hide();
                    $('.aiir-modal-content').show();

                    $('.aiir-upload-list').html(
                        '<p style="padding:10px 0;">No valid images uploaded.</p>'
                    );
                }
            },
            error: function () {
                $('.aiir-loading').hide();
                $('.aiir-modal-content').show();

                $('.aiir-upload-list').html(
                    '<p style="color:red;">Error retrieving AI suggestions.</p>'
                );
            }
        });
    }

    function populateModal(images) {
        const container = $('.aiir-upload-list');
        container.empty();

        images.forEach(img => {
            const ext = img.url.split('.').pop().split(/#|\?/)[0]; // get extension

            let html = `
                <div class="aiir-image-item" data-img-id="${img.id}">
                    <img src="${img.url}" alt="">
                    <div class="aiir-options">`;

            // AI suggestions
            if (img.suggestions.length > 0) {
                img.suggestions.forEach((name, i) => {
                    html += `<label><input type="radio" name="img_${img.id}" value="${name}" ${i === 0 ? 'checked' : ''}> ${name}</label>`;
                });
            } else {
                html += `<p><em>No AI suggestions found.</em></p>`;
            }

            // Manual field
            html += `
                    <div class="aiir-manual">
                        <label>Manual filename:</label>
                        <div class="aiir-manual-row">
                            <input type="text" 
                                   class="aiir-manual-input" 
                                   placeholder="Enter filename (no spaces)" 
                                   data-ext="${ext}">
                            <span class="aiir-ext">.${ext}</span>
                        </div>
                        <small class="aiir-error" style="color:#c62828;display:none;">
                            Only letters, numbers, and hyphens allowed.
                        </small>
                    </div>
                    </div>
                </div>`;

            container.append(html);
        });
    }

    $(document).on('input', '.aiir-manual-input', function () {
        let v = $(this).val();

        // Allow: a–z, A–Z, 0–9, hyphens
        const clean = v.replace(/[^a-zA-Z0-9-]/g, '');

        if (v !== clean) {
            $(this).siblings('.aiir-error').fadeIn(150);
        } else {
            $(this).siblings('.aiir-error').fadeOut(150);
        }

        $(this).val(clean);
    });

    // Close modal
    $(document).on('click', '.aiir-close', function () {
        $('#aiir-modal').fadeOut(200);
    });

    // Submit handler
    $('#aiir-submit').on('click', function () {
        const selections = [];

        $('.aiir-image-item').each(function () {
            const imgId = $(this).data('img-id');
            const manual = $(this).find('.aiir-manual-input').val();
            const ext = $(this).find('.aiir-manual-input').data('ext');

            let finalName = '';

            if (manual && manual.length > 0) {
                finalName = manual + '.' + ext;
            } else {
                const picked = $(this).find('input[type="radio"]:checked').val();
                if (picked) finalName = picked;
            }

            if (imgId && finalName) {
                selections.push({ id: imgId, new_name: finalName });
            }
        });

        if (selections.length === 0) {
            alert('No filenames selected.');
            return;
        }

        $('#aiir-submit').prop('disabled', true).text('Renaming...');

        $('.aiir-status').remove();

        $.ajax({
            url: AIIR_Ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'aiir_rename_images',
                nonce: AIIR_Ajax.nonce,
                images: selections
            },
            success: function (res) {
                $('#aiir-submit').prop('disabled', false).text('Apply Selected Names');

                if (!res.success) {
                    $('<div class="aiir-status aiir-status-error">❌ Rename failed.</div>').insertAfter('.aiir-upload-list');
                    return;
                }

                const results = res.data.results || [];

                results.forEach(r => {
                    const item = $(`.aiir-image-item[data-img-id="${r.id}"]`);

                    if (r.status === 'success') {
                        item.append(`<div class="aiir-status aiir-status-success">✅ Renamed to <strong>${r.new_name}</strong></div>`);
                    } else {
                        item.append(`<div class="aiir-status aiir-status-error">❌ ${r.message || 'Rename failed'}</div>`);
                    }
                });

                if (results.every(r => r.status === 'success')) {
                    $('.aiir-upload-list').append(`<div class="aiir-status aiir-status-overall">🎉 All images renamed successfully!</div>`);
                }
            },
            error: function () {
                $('#aiir-submit').prop('disabled', false).text('Apply Selected Names');
                $('<div class="aiir-status aiir-status-error">❌ AJAX error while renaming.</div>').insertAfter('.aiir-upload-list');
            }
        });
    });
});
