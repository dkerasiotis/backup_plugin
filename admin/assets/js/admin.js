/* global jQuery, wpsb_ajax */
(function ($) {
    'use strict';

    var backupId     = null;
    var pollInterval = null;
    var lastLogCount = 0;

    $(document).ready(function () {
        $('#wpsb-start-backup').on('click', startBackup);
    });

    /**
     * Collect selected components and kick off the backup.
     */
    function startBackup() {
        var components = [];
        $('input[name="wpsb_components"]:checked').each(function () {
            components.push($(this).val());
        });

        if (components.length === 0) {
            alert(wpsb_ajax.strings.no_component);
            return;
        }

        // Disable form controls
        setFormEnabled(false);

        // Show progress card
        $('#wpsb-progress').show();
        $('#wpsb-download').hide();
        resetProgress();

        updateMessage(wpsb_ajax.strings.starting);

        $.post(wpsb_ajax.ajaxurl, {
            action:     'wpsb_start_backup',
            nonce:      wpsb_ajax.nonce,
            components: components
        })
        .done(function (response) {
            if (response.success) {
                backupId = response.data.backup_id;
                startPolling();
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : wpsb_ajax.strings.error;
                showError(msg);
            }
        })
        .fail(function () {
            showError(wpsb_ajax.strings.error);
        });
    }

    /**
     * Poll the progress endpoint every 2 seconds.
     */
    function startPolling() {
        pollInterval = setInterval(function () {
            $.post(wpsb_ajax.ajaxurl, {
                action:    'wpsb_check_progress',
                nonce:     wpsb_ajax.nonce,
                backup_id: backupId
            })
            .done(function (response) {
                if (response.success) {
                    handleProgress(response.data);
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : wpsb_ajax.strings.error;
                    showError(msg);
                }
            })
            .fail(function () {
                // Network glitch — keep polling
            });
        }, 2000);
    }

    /**
     * Process a progress data object from the server.
     *
     * @param {Object} data
     */
    function handleProgress(data) {
        var pct = 0;
        if (data.total_steps && data.total_steps > 0) {
            pct = Math.round((data.step / data.total_steps) * 100);
        }
        setProgressBar(pct);
        updateMessage(data.message || '');

        // Append new log entries
        if (data.log && data.log.length > lastLogCount) {
            var newEntries = data.log.slice(lastLogCount);
            newEntries.forEach(function (entry) {
                var isError = (entry.message.indexOf('ERROR') === 0);
                var $li = $('<li>');
                $li.append($('<span class="wpsb-log-time">').text('[' + entry.time + ']'));
                var $msg = $('<span>').text(entry.message);
                if (isError) {
                    $msg.addClass('wpsb-log-error');
                }
                $li.append($msg);
                $('#wpsb-progress-log').append($li);
            });
            lastLogCount = data.log.length;

            // Auto-scroll log to bottom
            var $log = $('#wpsb-progress-log');
            $log.scrollTop($log[0].scrollHeight);
        }

        if (data.status === 'done') {
            stopPolling();
            setProgressBar(100);
            $('#wpsb-progress-bar').addClass('wpsb-done');
            showDownload(data.download_url);
        } else if (data.status === 'error') {
            stopPolling();
            $('#wpsb-progress-bar').addClass('wpsb-error');
            showError(data.message || wpsb_ajax.strings.error);
        }
    }

    /**
     * Stop the polling interval.
     */
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    /**
     * Display the download section with the given URL.
     *
     * @param {string} url
     */
    function showDownload(url) {
        $('#wpsb-progress-notice').hide();
        updateMessage(wpsb_ajax.strings.done);
        $('#wpsb-download-link').attr('href', url);
        $('#wpsb-download').show();
        setFormEnabled(true);
    }

    /**
     * Display an error state.
     *
     * @param {string} message
     */
    function showError(message) {
        stopPolling();
        $('#wpsb-progress').addClass('wpsb-has-error');
        updateMessage(message);
        setProgressBar(100);
        setFormEnabled(true);
    }

    /**
     * Set the progress bar width.
     *
     * @param {number} pct 0–100
     */
    function setProgressBar(pct) {
        $('#wpsb-progress-bar').css('width', pct + '%');
    }

    /**
     * Update the status message text.
     *
     * @param {string} message
     */
    function updateMessage(message) {
        $('#wpsb-progress-message').text(message);
    }

    /**
     * Reset progress UI to initial state.
     */
    function resetProgress() {
        lastLogCount = 0;
        setProgressBar(0);
        $('#wpsb-progress-bar').removeClass('wpsb-done wpsb-error');
        $('#wpsb-progress').removeClass('wpsb-has-error');
        $('#wpsb-progress-log').empty();
        $('#wpsb-progress-notice').show();
        updateMessage('');
    }

    /**
     * Enable or disable the start button and checkboxes.
     *
     * @param {boolean} enabled
     */
    function setFormEnabled(enabled) {
        $('#wpsb-start-backup').prop('disabled', !enabled);
        $('input[name="wpsb_components"]').prop('disabled', !enabled);
    }

}(jQuery));
