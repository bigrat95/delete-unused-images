(function($) {
    'use strict';

    var DUI = {
        currentTab: 'unused',
        currentPage: 1,
        scanning: false,
        searchTerm: '',
        sortBy: 'date',
        sortOrder: 'desc',
        filterType: '',
        perPage: 20,

        init: function() {
            // Detect current tab from URL
            var params = new URLSearchParams(window.location.search);
            this.currentTab = params.get('tab') || 'unused';

            this.bindEvents();
            this.loadResults();
        },

        bindEvents: function() {
            var self = this;

            // Scan button
            $('#dui-scan-btn').on('click', function() {
                self.startScan();
            });

            // Tab clicks - use AJAX instead of page reload
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).attr('href').match(/tab=(\w+)/);
                if (tab) {
                    self.currentTab = tab[1];
                    self.currentPage = 1;
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    var url = new URL(window.location);
                    url.searchParams.set('tab', self.currentTab);
                    window.history.replaceState({}, '', url);
                    self.searchTerm = '';
                    self.filterType = '';
                    self.sortBy = 'date';
                    self.sortOrder = 'desc';
                    $('#dui-search').val('');
                    $('#dui-filter-type').val('');
                    self.loadResults();
                    self.updateBulkBar();
                }
            });

            // Select all checkbox
            $(document).on('change', '#dui-select-all, .dui-select-all-header', function() {
                var checked = $(this).prop('checked');
                $('.dui-item-cb').prop('checked', checked);
                $('#dui-select-all, .dui-select-all-header').prop('checked', checked);
                self.updateSelectedInfo();
            });

            // Individual checkbox
            $(document).on('change', '.dui-item-cb', function() {
                self.updateSelectedInfo();
            });

            // Single actions
            $(document).on('click', '.dui-trash-btn', function() {
                var id = $(this).data('id');
                if (confirm(duiObj.strings.confirm_trash)) {
                    self.trashSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.dui-delete-btn', function() {
                var id = $(this).data('id');
                if (confirm(duiObj.strings.confirm_delete)) {
                    self.deleteSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.dui-whitelist-btn', function() {
                var id = $(this).data('id');
                self.whitelistSingle(id, $(this).closest('tr'));
            });

            $(document).on('click', '.dui-remove-whitelist-btn', function() {
                var id = $(this).data('id');
                self.removeWhitelist(id, $(this).closest('tr'));
            });

            $(document).on('click', '.dui-restore-btn', function() {
                var id = $(this).data('id');
                self.restoreSingle(id, $(this).closest('tr'));
            });

            // Bulk actions
            $('#dui-bulk-trash-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(duiObj.strings.no_selection, 'info'); return; }
                if (confirm(duiObj.strings.confirm_bulk_trash)) {
                    self.trashBulk(ids);
                }
            });

            $('#dui-bulk-delete-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(duiObj.strings.no_selection, 'info'); return; }
                if (confirm(duiObj.strings.confirm_bulk_delete)) {
                    self.deleteBulk(ids);
                }
            });

            $('#dui-bulk-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(duiObj.strings.no_selection, 'info'); return; }
                self.whitelistBulk(ids);
            });

            $('#dui-bulk-remove-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(duiObj.strings.no_selection, 'info'); return; }
                if (confirm('Remove selected items from whitelist?')) {
                    self.removeWhitelistBulk(ids);
                }
            });

            $('#dui-bulk-restore-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(duiObj.strings.no_selection, 'info'); return; }
                self.restoreBulk(ids);
            });

            // Trash All
            $('#dui-trash-all-btn').on('click', function() {
                if (!confirm(duiObj.strings.confirm_trash_all)) return;
                self.trashAll();
            });

            // Cron settings
            $('#dui-save-cron-btn').on('click', function() {
                self.saveCronSettings();
            });

            // Sort by column header
            $(document).on('click', '.dui-sortable', function() {
                var col = $(this).data('sort');
                if (self.sortBy === col) {
                    self.sortOrder = self.sortOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    self.sortBy = col;
                    self.sortOrder = col === 'date' ? 'desc' : 'asc';
                }
                self.currentPage = 1;
                self.loadResults();
            });

            // Per page
            $('#dui-per-page').on('change', function() {
                self.perPage = parseInt($(this).val());
                self.currentPage = 1;
                self.loadResults();
            });

            // Filter by type
            $('#dui-filter-type').on('change', function() {
                self.filterType = $(this).val();
                self.currentPage = 1;
                self.loadResults();
            });

            // Search
            $('#dui-search-btn').on('click', function() {
                self.searchTerm = $('#dui-search').val().trim();
                self.currentPage = 1;
                self.loadResults();
            });
            $('#dui-search').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.searchTerm = $(this).val().trim();
                    self.currentPage = 1;
                    self.loadResults();
                }
            }).on('search', function() {
                if ($(this).val() === '') {
                    self.searchTerm = '';
                    self.currentPage = 1;
                    self.loadResults();
                }
            });

            // Pagination
            $(document).on('click', '.dui-page-btn', function() {
                var page = $(this).data('page');
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadResults();
                    $('html, body').animate({ scrollTop: $('#dui-results').offset().top - 50 }, 300);
                }
            });
        },

        // ─── Scan ───────────────────────────────────────────────────

        startScan: function() {
            if (this.scanning) return;
            this.scanning = true;

            var self = this;
            var $btn = $('#dui-scan-btn');
            var $progress = $('#dui-progress-wrap');
            var $fill = $('#dui-progress-fill');
            var $text = $('#dui-progress-text');

            $btn.prop('disabled', true).text(duiObj.strings.scanning);
            $progress.show();
            $fill.css('width', '0%');
            $text.text('0%');

            // Step 1: Start scan (collect used IDs)
            $.post(duiObj.ajaxurl, {
                action: 'dui_start_scan',
                nonce: duiObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast(res.data || 'Scan failed', 'error');
                    self.resetScanUI();
                    return;
                }

                $fill.css('width', '5%');
                $text.text('5%');

                // Step 2: Process batches
                self.scanBatch(0, res.data.total);
            }).fail(function() {
                self.toast('Network error', 'error');
                self.resetScanUI();
            });
        },

        scanBatch: function(offset, total) {
            var self = this;
            var $fill = $('#dui-progress-fill');
            var $text = $('#dui-progress-text');

            $.post(duiObj.ajaxurl, {
                action: 'dui_scan_batch',
                nonce: duiObj.nonce,
                offset: offset
            }, function(res) {
                if (!res.success) {
                    self.toast('Batch scan error', 'error');
                    self.resetScanUI();
                    return;
                }

                var pct = Math.min(100, Math.round((res.data.processed / total) * 100));
                $fill.css('width', pct + '%');
                $text.text(pct + '% — ' + res.data.unused_found + ' unused found');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text(duiObj.strings.scan_complete + ' ' + res.data.unused_found + ' unused files.');
                    self.toast(duiObj.strings.scan_complete + ' Found ' + res.data.unused_found + ' unused files.', 'success');
                    self.scanning = false;
                    self.currentPage = 1;
                    self.loadResults();

                    setTimeout(function() {
                        $('#dui-progress-wrap').fadeOut();
                        $('#dui-scan-btn').prop('disabled', false).text('Re-Scan');
                    }, 3000);
                } else {
                    // Next batch
                    self.scanBatch(res.data.processed, total);
                }
            }).fail(function() {
                self.toast('Network error during scan', 'error');
                self.resetScanUI();
            });
        },

        resetScanUI: function() {
            this.scanning = false;
            $('#dui-scan-btn').prop('disabled', false).text('Scan for Unused Media');
            $('#dui-progress-wrap').hide();
        },

        // ─── Load results via AJAX ──────────────────────────────────

        loadResults: function() {
            var self = this;
            var $results = $('#dui-results');

            $results.css('opacity', '0.5');

            $.post(duiObj.ajaxurl, {
                action: 'dui_get_results',
                nonce: duiObj.nonce,
                tab: this.currentTab,
                page: this.currentPage,
                search: this.searchTerm,
                orderby: this.sortBy,
                order: this.sortOrder,
                filter_type: this.filterType,
                per_page: this.perPage
            }, function(res) {
                if (res.success) {
                    $results.html(res.data.html).css('opacity', '1');
                    $('#dui-stats').html(res.data.stats);
                    self.buildPagination();
                    self.updateTabCounts();
                    // Uncheck select all
                    $('#dui-select-all, .dui-select-all-header').prop('checked', false);
                    self.updateSelectedInfo();
                }
            });
        },

        buildPagination: function() {
            var $pag = $('#dui-pagination');
            var el = $pag[0];
            var totalPages = parseInt(el && el.dataset.totalPages) || 0;
            var currentPage = this.currentPage;
            var totalItems = parseInt(el && el.dataset.totalItems) || 0;

            $pag.empty();

            if (totalPages <= 1) {
                if (totalItems > 0) $pag.append('<span class="dui-page-info">' + totalItems + ' items</span>');
                return;
            }

            $pag.append('<button class="button dui-page-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage <= 1 ? 'disabled' : '') + '>&laquo;</button>');

            var start = Math.max(1, currentPage - 2);
            var end = Math.min(totalPages, currentPage + 2);

            if (start > 1) {
                $pag.append('<button class="button dui-page-btn" data-page="1">1</button>');
                if (start > 2) $pag.append('<span class="dui-page-info">…</span>');
            }

            for (var i = start; i <= end; i++) {
                $pag.append('<button class="button dui-page-btn ' + (i === currentPage ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>');
            }

            if (end < totalPages) {
                if (end < totalPages - 1) $pag.append('<span class="dui-page-info">…</span>');
                $pag.append('<button class="button dui-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>');
            }

            $pag.append('<button class="button dui-page-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage >= totalPages ? 'disabled' : '') + '>&raquo;</button>');
            $pag.append('<span class="dui-page-info">' + totalItems + ' items</span>');
        },

        updateTabCounts: function() {
            var $cells = $('#dui-stats .form-table td strong');
            var unused = $cells.eq(2).text().replace(/,/g, '') || '0';
            var whitelist = $cells.eq(4).text().replace(/,/g, '') || '0';
            $('#dui-unused-count').text('(' + unused + ')');
            $('#dui-whitelist-count').text('(' + whitelist + ')');
        },

        updateBulkBar: function() {
            // Show/hide relevant bulk buttons based on tab
            var tab = this.currentTab;
            $('#dui-bulk-trash-btn, #dui-bulk-whitelist-btn').toggle(tab === 'unused');
            $('#dui-bulk-remove-whitelist-btn').toggle(tab === 'whitelist');
            $('#dui-bulk-delete-btn, #dui-bulk-restore-btn').toggle(tab === 'trash');
        },

        // ─── Actions ────────────────────────────────────────────────

        trashSingle: function(id, $row) {
            var self = this;
            $row.addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_trash_single',
                nonce: duiObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        deleteSingle: function(id, $row) {
            var self = this;
            $row.addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_delete_single',
                nonce: duiObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        whitelistSingle: function(id, $row) {
            var self = this;
            $row.addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_whitelist_single',
                nonce: duiObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelist: function(id, $row) {
            var self = this;
            $row.addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_remove_whitelist',
                nonce: duiObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        restoreSingle: function(id, $row) {
            var self = this;
            $row.addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_restore_single',
                nonce: duiObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        trashBulk: function(ids) {
            var self = this;
            $('.dui-item-cb:checked').closest('tr').addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_trash_bulk',
                nonce: duiObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.dui-loading').removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        deleteBulk: function(ids) {
            var self = this;
            $('.dui-item-cb:checked').closest('tr').addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_delete_bulk',
                nonce: duiObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.dui-loading').removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        whitelistBulk: function(ids) {
            var self = this;
            $('.dui-item-cb:checked').closest('tr').addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_whitelist_bulk',
                nonce: duiObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.dui-loading').removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelistBulk: function(ids) {
            var self = this;
            $('.dui-item-cb:checked').closest('tr').addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_remove_whitelist_bulk',
                nonce: duiObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.dui-loading').removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        restoreBulk: function(ids) {
            var self = this;
            $('.dui-item-cb:checked').closest('tr').addClass('dui-loading');

            $.post(duiObj.ajaxurl, {
                action: 'dui_restore_bulk',
                nonce: duiObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.dui-loading').removeClass('dui-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        trashAll: function() {
            var self = this;
            var $btn = $('#dui-trash-all-btn');
            var $progress = $('#dui-progress-wrap');
            var $fill = $('#dui-progress-fill');
            var $text = $('#dui-progress-text');
            var totalStart = 0;

            $btn.prop('disabled', true).text('Trashing...');
            $progress.show();
            $fill.css('width', '0%');
            $text.text('Starting...');

            self.trashAllBatch(totalStart);
        },

        trashAllBatch: function(totalStart) {
            var self = this;
            var $fill = $('#dui-progress-fill');
            var $text = $('#dui-progress-text');

            $.post(duiObj.ajaxurl, {
                action: 'dui_trash_all_batch',
                nonce: duiObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast('Error trashing files', 'error');
                    self.resetTrashAllUI();
                    return;
                }

                if (totalStart === 0) totalStart = res.data.total || 1;
                var trashed = totalStart - res.data.remaining;
                var pct = Math.min(100, Math.round((trashed / totalStart) * 100));
                $fill.css('width', pct + '%');
                $text.text(pct + '% — ' + trashed + ' / ' + totalStart + ' trashed');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text('Done! ' + totalStart + ' files moved to trash.');
                    self.toast(totalStart + ' files moved to trash.', 'success');
                    self.currentPage = 1;
                    self.loadResults();
                    setTimeout(function() {
                        $('#dui-progress-wrap').fadeOut();
                        self.resetTrashAllUI();
                    }, 3000);
                } else {
                    self.trashAllBatch(totalStart);
                }
            }).fail(function() {
                self.toast('Network error', 'error');
                self.resetTrashAllUI();
            });
        },

        resetTrashAllUI: function() {
            $('#dui-trash-all-btn').prop('disabled', false).text('Trash All Unused');
        },

        saveCronSettings: function() {
            var self = this;
            var enabled = $('#dui-cron-enabled').is(':checked');
            var frequency = $('#dui-cron-frequency').val();

            $.post(duiObj.ajaxurl, {
                action: 'dui_save_cron_settings',
                nonce: duiObj.nonce,
                enabled: enabled ? 1 : 0,
                frequency: frequency
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    $('#dui-next-run').text(res.data.next_run);
                } else {
                    self.toast(res.data || 'Error saving settings', 'error');
                }
            });
        },

        // ─── Helpers ────────────────────────────────────────────────

        getSelectedIds: function() {
            var ids = [];
            $('.dui-item-cb:checked').each(function() {
                ids.push(parseInt($(this).val()));
            });
            return ids;
        },

        updateSelectedInfo: function() {
            var count = $('.dui-item-cb:checked').length;
            var totalSize = 0;
            $('.dui-item-cb:checked').each(function() {
                totalSize += parseInt($(this).data('size')) || 0;
            });
            if (count > 0) {
                $('#dui-selected-info').text(count + ' selected (' + this.formatSize(totalSize) + ')');
            } else {
                $('#dui-selected-info').text('');
            }
        },

        refreshAfterAction: function() {
            var self = this;
            // Quick stats refresh
            setTimeout(function() {
                self.loadResults();
            }, 500);
        },

        formatSize: function(bytes) {
            if (bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        toast: function(message, type) {
            type = type || 'info';
            var $toast = $('<div class="dui-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);
            setTimeout(function() { $toast.addClass('show'); }, 10);
            setTimeout(function() {
                $toast.removeClass('show');
                setTimeout(function() { $toast.remove(); }, 300);
            }, 4000);
        }
    };

    $(document).ready(function() {
        if ($('#dui-scan-btn').length) {
            DUI.init();
        }
    });

})(jQuery);
