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


/* ============================================================
   BULK AI IMAGE RENAMER (ADMIN PAGE) — PAGINATED VERSION
   ============================================================ */

jQuery(document).ready(function ($) {

    if (!$('#aiir-bulk-scan').length) return;

    let currentOffset = 0;   // start from the first 20 items
    let nextOffset = null;   // updated by AJAX response
    let totalRemaining = 0;  // total un-renamed images
    let firstLoad = true;

    // Click: Scan Images
    $('#aiir-bulk-scan').on('click', function () {

        currentOffset = 0;   // reset pagination on new scan
        firstLoad = true;

        scanBatch();
    });


    /* ---------------------------------------------------------
       Scan a batch (20 items) using offset
    --------------------------------------------------------- */
    function scanBatch() {

        $('#aiir-bulk-scan')
            .prop('disabled', true)
            .text(firstLoad ? 'Scanning...' : 'Loading more...');

        $('#aiir-bulk-results').html(
            '<div class="aiir-bulk-loading">🔄 Fetching images…</div>'
        );

        $.ajax({
            url: AIIR_Ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'aiir_bulk_scan',
                nonce: AIIR_Ajax.nonce,
                offset: currentOffset
            },
            success: function (res) {

                $('#aiir-bulk-scan')
                    .prop('disabled', false)
                    .text('Scan Images');

                if (!res.success) {
                    $('#aiir-bulk-results').html(
                        '<div class="notice notice-error"><p>Error during scan.</p></div>'
                    );
                    return;
                }

                const images = res.data.images;
                totalRemaining = res.data.total_remaining;
                nextOffset = res.data.next_offset;

                if (images.length === 0) {
                    $('#aiir-bulk-results').html(
                        '<div class="notice notice-info"><p>No un-renamed images found.</p></div>'
                    );
                    $('#aiir-bulk-submit').hide();
                    return;
                }

                renderBulkList(images, firstLoad);
                firstLoad = false;
            },

            error: function () {
                $('#aiir-bulk-scan').prop('disabled', false).text('Scan Images');

                $('#aiir-bulk-results').html(
                    '<div class="notice notice-error"><p>AJAX error while scanning.</p></div>'
                );
            }
        });
    }



    /* ---------------------------------------------------------
       Render Images in Clean Admin Style + Pagination Message
    --------------------------------------------------------- */
    function renderBulkList(images, isFirstLoad) {

        let tableHTML = '';

        if (isFirstLoad) {
            tableHTML += `
                <p class="description" style="margin-bottom:10px;">
                    Showing <strong>10 images per batch</strong>.  
                    Total remaining: <strong>${totalRemaining}</strong>.
                </p>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="80">Preview</th>
                            <th>Filename Suggestions</th>
                            <th width="260">Manual Override</th>
                        </tr>
                    </thead>
                    <tbody id="aiir-bulk-tbody">
            `;
        }

        images.forEach(img => {

            const ext = img.ext || 'jpg';

            let suggestions = '';

            if (img.suggestions.length > 0) {
                img.suggestions.forEach((name, i) => {
                    suggestions += `
                        <label style="display:block;margin-bottom:4px;">
                            <input type="radio" name="bulk_${img.id}" value="${name}" ${i === 0 ? 'checked' : ''}>
                            ${name}
                        </label>
                    `;
                });
            } else {
                suggestions = `<em>No suggestions found.</em>`;
            }

            tableHTML += `
                <tr class="aiir-bulk-item" data-img-id="${img.id}">
                    <td>
                        <img src="${img.url}" style="width:70px;height:auto;border-radius:4px;">
                    </td>

                    <td>${suggestions}</td>

                    <td>
                        <input type="text" class="aiir-bulk-manual" placeholder="my-filename" 
                               style="width:150px;" data-ext="${ext}">
                        <span>.${ext}</span>
                        <br>
                        <small class="aiir-bulk-error" style="color:#c62828;display:none;">
                            Only letters, numbers, and hyphens allowed.
                        </small>
                    </td>
                </tr>
            `;
        });

        if (isFirstLoad) {
            tableHTML += `
                </tbody></table>
                <div id="aiir-pagination"></div>
            `;

            $('#aiir-bulk-results').html(tableHTML);
        } else {
            $('#aiir-bulk-tbody').append(tableHTML);
        }


        /* Load More Button */
        let paginationHTML = '';

        if (nextOffset !== null) {
            // paginationHTML = `
            //     <button id="aiir-load-more" class="button button-secondary" style="margin-top:15px;">
            //         Load Next 10 Images
            //     </button>
            // `;
        } else {
            paginationHTML = `
                <p style="margin-top:15px;">✔ All un-renamed images have been loaded.</p>
            `;
        }

        $('#aiir-pagination').html(paginationHTML);


        /* Show rename button */
        $('#aiir-bulk-submit').show();
    }



    /* ---------------------------------------------------------
       Load Next 20 Images
    --------------------------------------------------------- */
    $(document).on('click', '#aiir-load-more', function () {
        currentOffset = nextOffset;
        scanBatch();
    });



    /* ---------------------------------------------------------
       Manual Field Validation
    --------------------------------------------------------- */
    $(document).on('input', '.aiir-bulk-manual', function () {

        let v = $(this).val();
        const clean = v.replace(/[^a-zA-Z0-9-]/g, '');

        if (clean !== v) {
            $(this).siblings('.aiir-bulk-error').show();
        } else {
            $(this).siblings('.aiir-bulk-error').hide();
        }

        $(this).val(clean);
    });



    /* ---------------------------------------------------------
       Apply Selected Names (Bulk Rename)
    --------------------------------------------------------- */
    $('#aiir-bulk-submit').on('click', function () {

        const btn = $(this);
        btn.prop('disabled', true).text('Renaming…');

        const selections = [];

        $('.aiir-bulk-item').each(function () {

            const imgId = $(this).data('img-id');
            const manual = $(this).find('.aiir-bulk-manual').val();
            const ext    = $(this).find('.aiir-bulk-manual').data('ext');

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
            alert('No images selected.');
            btn.prop('disabled', false).text('Apply Selected Names');
            return;
        }

        $.ajax({
            url: AIIR_Ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'aiir_rename_images',
                nonce: AIIR_Ajax.nonce,
                images: selections
            },
            success: function (res) {

                btn.prop('disabled', false).text('Apply Selected Names');

                // Remove top notices
                $('#aiir-bulk-results .notice').remove();

                // If server-side error
                if (!res.success || !res.data || !Array.isArray(res.data.results)) {
                    $('.aiir-upload-list').append(`
                        <div class="aiir-status aiir-status-error">
                            ❌ Rename failed.
                        </div>
                    `);
                    return;
                }

                const results = res.data.results;

                // Clear previous status messages
                $('.aiir-status').remove();

                // Loop through each renamed image
                results.forEach(r => {
                    const item = $(`.aiir-bulk-item[data-img-id="${r.id}"]`);

                    if (r.status === 'success') {
                        item.append(`
                            <div class="aiir-status aiir-status-success">
                                ✅ Renamed to <strong>${r.new_name}</strong>
                            </div>
                        `);
                    } else {
                        item.append(`
                            <div class="aiir-status aiir-status-error">
                                ❌ ${r.message || 'Rename failed'}
                            </div>
                        `);
                    }
                });

                // Show final success message only if ALL succeeded
                if (results.every(r => r.status === 'success')) {
                    $('#aiir-bulk-results').append(`
                        <div class="aiir-status aiir-status-overall">
                            🎉 All images renamed successfully! Click the Scan again to load new un-renamed images.
                        </div>
                    `);
                }
            },

            error: function () {
                btn.prop('disabled', false).text('Apply Selected Names');

                $('#aiir-bulk-results').prepend(
                    '<div class="notice notice-error"><p>AJAX error during rename.</p></div>'
                );
            }
        });

    });

});
