/* Smart Image Optimizer – Admin JS */
jQuery(function ($) {
    'use strict';

    /* ── Format cards sync with select ───────────────────────────────── */
    $(document).on('change', '#sio-format-select', function () {
        var v = $(this).val();
        $('.sio-format-card').removeClass('selected');
        $('.sio-format-card input[value="' + v + '"]').closest('.sio-format-card').addClass('selected');
    });

    $(document).on('click', '.sio-format-card', function () {
        var v = $(this).find('input').val();
        $('#sio-format-select').val(v);
    });

    /* ── Quality slider ──────────────────────────────────────────────── */
    var $range = $('#sio-quality-range'), $num = $('#sio-quality-num');

    $range.on('input', function () {
        $num.val($(this).val());
        updateSliderBg($(this).val());
    });
    $num.on('input', function () {
        $range.val($(this).val());
        updateSliderBg($(this).val());
    });

    function updateSliderBg(v) {
        $range.css('background',
            'linear-gradient(to right, #6c63ff ' + v + '%, #2a2d3e ' + v + '%)');
    }

    $(document).on('click', '.sio-preset', function () {
        var q = $(this).data('q');
        $range.val(q); $num.val(q);
        updateSliderBg(q);
        $('.sio-preset').removeClass('sio-preset-active');
        $(this).addClass('sio-preset-active');
    });

    /* ── Dimension presets ───────────────────────────────────────────── */
    $(document).on('click', '.sio-dim-preset', function () {
        $('input[name="max_width"]').val($(this).data('w'));
        $('input[name="max_height"]').val($(this).data('h'));
    });

    /* ── Dashboard stats ─────────────────────────────────────────────── */
    if ($('#sio-stats').length) {
        $.post(SIO.ajax_url, { action: 'sio_get_stats', nonce: SIO.nonce }, function (r) {
            if (!r.success) return;
            var d = r.data;
            $('#stat-total .stat-num').text(d.total);
            $('#stat-saved .stat-num').text(formatBytes(d.original_bytes - d.optimized_bytes));
            $('#stat-pct .stat-num').text(d.savings_pct + '%');
        });
    }

    /* ── Reset logs ──────────────────────────────────────────────────── */
    $(document).on('click', '#sio-reset-btn', function () {
        if (!confirm('Reset all optimization logs? Images will not be re-processed unless you run Bulk Optimize.')) return;
        $.post(SIO.ajax_url, { action: 'sio_bulk_reset', nonce: SIO.nonce }, function () {
            location.reload();
        });
    });

    /* ── Media library: single optimize ─────────────────────────────── */
    $(document).on('click', '.sio-optimize-btn', function () {
        var $btn = $(this), id = $btn.data('id');
        $btn.text('…').prop('disabled', true);
        $.post(SIO.ajax_url, { action: 'sio_optimize_single', nonce: SIO.nonce, attachment_id: id }, function (r) {
            if (r.success && r.data.success) {
                $btn.closest('td').html('<span class="sio-badge sio-badge-done">✓ ' + r.data.savings + '% saved</span>');
            } else {
                $btn.text('Retry').prop('disabled', false);
            }
        });
    });

    /* ── Bulk optimizer ──────────────────────────────────────────────── */
    var bulkRunning = false, bulkDone = 0, bulkSaved = 0, bulkTotal = 0;

    // Load count on page load
    if ($('#bulk-total').length) {
        $.post(SIO.ajax_url, { action: 'sio_bulk_count', nonce: SIO.nonce }, function (r) {
            if (r.success) {
                bulkTotal = r.data.count;
                $('#bulk-total').text(r.data.count);
            }
        });
    }

    $('#sio-bulk-start').on('click', function () {
        if (bulkRunning) return;
        bulkRunning = true;
        bulkDone = 0; bulkSaved = 0;
        $(this).hide();
        $('#sio-bulk-stop').show();
        $('#sio-progress-wrap').show();
        $('#sio-bulk-log').empty();
        processNext();
    });

    $('#sio-bulk-stop').on('click', function () {
        bulkRunning = false;
        $(this).hide();
        $('#sio-bulk-start').show().text('▶ Resume');
        logLine('⏹ Stopped.', '');
    });

    $('#sio-bulk-reset').on('click', function () {
        if (!confirm('Reset logs and allow all images to be reprocessed?')) return;
        $.post(SIO.ajax_url, { action: 'sio_bulk_reset', nonce: SIO.nonce }, function () {
            bulkDone = 0; bulkSaved = 0;
            $('#bulk-done').text(0);
            $('#bulk-saved-kb').text('0 KB');
            $('#sio-fill').css('width', '0%');
            $('#sio-progress-label').text('Ready');
            $('#sio-bulk-log').empty();
            $('#sio-bulk-start').show().text('▶ Start Bulk Optimization');
            $('#sio-bulk-stop').hide();
            logLine('🔄 Logs reset. Ready to reprocess all images.', '');
            // Refresh count
            $.post(SIO.ajax_url, { action: 'sio_bulk_count', nonce: SIO.nonce }, function (r) {
                if (r.success) { bulkTotal = r.data.count; $('#bulk-total').text(r.data.count); }
            });
        });
    });

    function processNext() {
        if (!bulkRunning) return;
        $.post(SIO.ajax_url, { action: 'sio_bulk_next', nonce: SIO.nonce }, function (r) {
            if (!r.success) { finishBulk('Error'); return; }
            var d = r.data;
            if (d.done) { finishBulk('All done!'); return; }

            bulkDone++;
            if (d.result && d.result.success) {
                var saved = d.result.original_size - d.result.optimized_size;
                bulkSaved += saved;
                logLine('✓ #' + d.attachment_id + '  ' + d.result.format_from + '→' + d.result.format_to
                    + '  saved ' + d.result.savings + '%  (' + formatBytes(saved) + ')', 'ok');
            } else {
                logLine('✗ #' + d.attachment_id + '  skipped / unsupported', 'fail');
            }

            var processed = bulkTotal - d.remaining;
            var pct = bulkTotal > 0 ? Math.round((processed / bulkTotal) * 100) : 0;
            $('#sio-fill').css('width', pct + '%');
            $('#sio-progress-label').text(processed + ' / ' + bulkTotal + ' processed  (' + pct + '%)');
            $('#bulk-done').text(bulkDone);
            $('#bulk-saved-kb').text(formatBytes(bulkSaved));

            setTimeout(processNext, 80);
        }).fail(function () {
            logLine('⚠ Ajax error – retrying…', 'fail');
            setTimeout(processNext, 1500);
        });
    }

    function finishBulk(msg) {
        bulkRunning = false;
        $('#sio-bulk-stop').hide();
        $('#sio-bulk-start').show().text('▶ Run Again');
        $('#sio-fill').css('width', '100%');
        $('#sio-progress-label').text(msg);
        logLine('🎉 ' + msg + ' Total saved: ' + formatBytes(bulkSaved), 'ok');
    }

    function logLine(text, cls) {
        var $log = $('#sio-bulk-log');
        $log.append('<div class="sio-log-line ' + cls + '">' + text + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */
    function formatBytes(b) {
        if (!b || b <= 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(b) / Math.log(k));
        return (b / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
    }
});
