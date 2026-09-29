/**
 * Allship UPS Quote Admin JavaScript
 *
 * @package Allship_UPS_Quote
 */

(function(window, document, $) {
  'use strict';

  const config = window.allshipUpsAdminConfig || {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: '',
    apiBase: ''
  };

  const AllshipAdmin = {
    init: function() {
      // Escape key to close modals
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          AllshipAdmin.closeModals();
        }
      });
    },

    showNotice: function(msg, isError) {
      const el = document.getElementById('allshipGlobalNotice');
      if (!el) return;
      el.className = 'notice is-dismissible ' + (isError ? 'notice-error' : 'notice-success');
      el.querySelector('p').textContent = msg;
      el.style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    closeModals: function() {
      document.querySelectorAll('.allship-modal-backdrop').forEach(function(m) {
        m.style.display = 'none';
      });
    },

    // --- Modal: Rename Card ---
    openRenameModal: function(id, name) {
      const modal = document.getElementById('modalRenameRateCard');
      if (modal) {
        modal.style.display = 'flex';
        document.getElementById('renameCardId').value = id;
        const input = document.getElementById('renameCardName');
        if (input) {
          input.value = name;
          input.focus();
        }
      }
    },

    handleRenameSubmit: function(e) {
      e.preventDefault();
      const btn = document.getElementById('btnSubmitRenameCard');
      if (btn) btn.disabled = true;

      var idEl = document.getElementById('renameCardId'); var id = idEl ? idEl.value : '';
      var nameEl = document.getElementById('renameCardName'); var name = nameEl ? nameEl.value : '';

      $.post(config.ajaxUrl, {
        action: 'ups_rename_rate_card',
        nonce: config.nonce,
        id: id,
        name: name
      }, function(res) {
        if (btn) btn.disabled = false;
        if (res.success) {
          AllshipAdmin.closeModals();
          location.reload();
        } else {
          alert((res.data && res.data.message) || '');
        }
      }).fail(function() {
        if (btn) btn.disabled = false;
        alert('Lỗi kết nối máy chủ.');
      });
    },

    pendingActivateCardId: null,

    // --- Actions: Activate, Archive, Delete ---
    activateCard: function(id) {
      AllshipAdmin.pendingActivateCardId = id;
      $.post(config.ajaxUrl, {
        action: 'ups_activate_rate_card',
        nonce: config.nonce,
        id: id,
        confirmed: 0
      }, function(res) {
        if (!res.success) {
          alert((res.data && res.data.message) || 'Không thể kiểm tra kích hoạt.');
          return;
        }

        if (res.data && res.data.has_conflict) {
          // Render conflict cards in modal and open
          var cards = res.data.conflicting_cards || [];
          var listHtml = '';
          cards.forEach(function(c) {
            var groupStr = (c.rate_groups && c.rate_groups.length) ? ' (' + c.rate_groups.join(', ') + ')' : '';
            listHtml += '<div class="as-conflict-item">' +
                        '<strong>#' + c.id + ' — ' + (c.name || 'Untitled') + '</strong>' +
                        '<span class="as-conflict-item-group">' + groupStr + '</span>' +
                        '</div>';
          });
          var listEl = document.getElementById('conflictCardsList');
          if (listEl) {
            listEl.innerHTML = listHtml;
          }
          var modal = document.getElementById('modalConflictRateCard');
          if (modal) {
            modal.style.display = 'flex';
          }
        } else {
          // No conflict -> activated directly
          location.reload();
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    confirmActivateConflict: function() {
      if (!AllshipAdmin.pendingActivateCardId) return;
      var btn = document.getElementById('btnConfirmActivateConflict');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Đang xử lý...';
      }

      $.post(config.ajaxUrl, {
        action: 'ups_activate_rate_card',
        nonce: config.nonce,
        id: AllshipAdmin.pendingActivateCardId,
        confirmed: 1
      }, function(res) {
        if (res.success) {
          location.reload();
        } else {
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Tiếp tục kích hoạt & Lưu trữ bảng giá cũ';
          }
          alert((res.data && res.data.message) || 'Lỗi khi kích hoạt bảng giá.');
        }
      }).fail(function() {
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Tiếp tục kích hoạt & Lưu trữ bảng giá cũ';
        }
        alert('Lỗi kết nối máy chủ.');
      });
    },

    archiveCard: function(id) {
      if (!confirm('Bạn có chắc chắn muốn chuyển bảng giá này sang lưu trữ (Archived)?')) {
        return;
      }
      $.post(config.ajaxUrl, {
        action: 'ups_archive_rate_card',
        nonce: config.nonce,
        id: id
      }, function(res) {
        if (res.success) {
          location.reload();
        } else {
          alert((res.data && res.data.message) || '');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    deleteCard: function(id) {
      if (!confirm('CẢNH BÁO: Thao tác này sẽ xóa vĩnh viễn bảng giá cùng toàn bộ mức cước và zone map liên quan. Bạn có chắc chắn không?')) {
        return;
      }
      $.post(config.ajaxUrl, {
        action: 'ups_delete_rate_card',
        nonce: config.nonce,
        id: id
      }, function(res) {
        if (res.success) {
          location.reload();
        } else {
          alert((res.data && res.data.message) || '');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    // =======================================================================
    // IMPORT WIZARD (Step 5.3)
    // =======================================================================
    currentImportToken: null,

    updateFileNameDisplay: function(inputId, displayId) {
      const input = document.getElementById(inputId);
      const display = document.getElementById(displayId);
      if (!input || !display) return;
      if (input.files && input.files.length > 0) {
        display.textContent = input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
        display.style.color = 'var(--as-wp-blue)';
      }
    },

    showImportNotice: function(msg, isError) {
      const el = document.getElementById('allshipImportNotice');
      if (!el) return;
      el.className = 'notice is-dismissible ' + (isError ? 'notice-error' : 'notice-success');
      el.querySelector('p').textContent = msg;
      el.style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    switchImportType: function(type) {
      const isZones = (type === 'zones');
      const tabRates = document.getElementById('tabSwitchRates');
      const tabZones = document.getElementById('tabSwitchZones');
      const formRates = document.getElementById('formImportUploadRates');
      const formZones = document.getElementById('formImportUploadZones');

      if (tabRates && tabZones) {
        if (isZones) {
          tabRates.classList.remove('as-import-tab--active');
          tabZones.classList.add('as-import-tab--active');
          if (formRates) formRates.style.display = 'none';
          if (formZones) formZones.style.display = 'block';
        } else {
          tabZones.classList.remove('as-import-tab--active');
          tabRates.classList.add('as-import-tab--active');
          if (formZones) formZones.style.display = 'none';
          if (formRates) formRates.style.display = 'block';
        }
      }
    },

    toggleZoneMode: function(mode) {
      const isNew = (mode === 'new');
      const existContainer = document.getElementById('zoneModeExistingContainer');
      const newContainer = document.getElementById('zoneModeNewContainer');
      const inputNewZoneFile = document.getElementById('inputRateZoneFile');
      const inputNewZoneName = document.getElementById('importNewZoneSetName');

      if (existContainer) existContainer.style.display = isNew ? 'none' : 'block';
      if (newContainer) newContainer.style.display = isNew ? 'block' : 'none';
      if (inputNewZoneFile) inputNewZoneFile.required = isNew;
      if (inputNewZoneName) inputNewZoneName.required = isNew;
    },

    handleImportPreview: function(e, type) {
      e.preventDefault();
      const formId = (type === 'zones') ? 'formImportUploadZones' : 'formImportUploadRates';
      const form = document.getElementById(formId) || document.getElementById('formImportUpload');
      if (!form) return;

      const btnId = (type === 'zones') ? 'btnStartImportPreviewZones' : 'btnStartImportPreviewRates';
      const btn = document.getElementById(btnId) || document.getElementById('btnStartImportPreview');
      const originalText = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s infinite linear;"></span> Đang phân tích file...';
      }

      const formData = new FormData(form);
      formData.append('action', 'ups_import_preview');
      formData.append('nonce', config.nonce);
      if (!formData.has('import_type')) {
        formData.append('import_type', type || 'rates');
      }

      $.ajax({
        url: config.ajaxUrl,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false
      }).done(function(res) {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }

        if (!res.success) {
          AllshipAdmin.showImportNotice((res.data && res.data.message) || '', true);
          return;
        }

        const data = res.data;
        AllshipAdmin.currentImportToken = data.token;
        AllshipAdmin.currentImportType  = data.import_type || (type || 'rates');

        // Update Stepper
        var tab1 = document.getElementById('stepTab1'); if (tab1) { tab1.classList.remove('as-wizard-step--active'); tab1.classList.add('as-wizard-step--completed'); }
        var tab2 = document.getElementById('stepTab2'); if (tab2) tab2.classList.add('as-wizard-step--active');

        // Switch visible view
        document.getElementById('importStep1').style.display = 'none';
        document.getElementById('importStep2').style.display = 'block';

        const isZoneMode = (AllshipAdmin.currentImportType === 'zones');

        // Toggle sections for Rates vs Zones
        const rateStatsBox = document.getElementById('previewRateStatsBox');
        const zoneStatsBox = document.getElementById('previewZoneStatsBox');
        const rateGroupsSection = document.getElementById('previewRateGroupsSection');
        const zoneServicesSection = document.getElementById('previewZoneServicesSection');
        const cardMetaSection = document.getElementById('previewCardMetaSection');

        if (rateStatsBox) rateStatsBox.style.display = isZoneMode ? 'none' : 'grid';
        if (zoneStatsBox) zoneStatsBox.style.display = isZoneMode ? 'grid' : 'none';
        if (rateGroupsSection) rateGroupsSection.style.display = isZoneMode ? 'none' : 'block';
        if (zoneServicesSection) zoneServicesSection.style.display = isZoneMode ? 'block' : 'none';
        if (cardMetaSection) cardMetaSection.style.display = isZoneMode ? 'none' : 'block';

        // Render Rates Overview Stats
        const groupsCountEl = document.getElementById('previewGroupsCount');
        if (groupsCountEl) groupsCountEl.textContent = (data.rate_groups || []).length;
        const us5FlagEl = document.getElementById('previewUs5Flag');
        if (us5FlagEl) us5FlagEl.textContent = data.has_us5 ? 'Có (Tự động map)' : 'Không';
        const countriesCountEl = document.getElementById('previewCountriesCount');
        if (countriesCountEl) countriesCountEl.textContent = data.countries_count || 0;
        const zoneMapsCountEl = document.getElementById('previewZoneMapsCount');
        if (zoneMapsCountEl) zoneMapsCountEl.textContent = data.zone_maps_count || 0;

        // Render Zone Overview Stats
        const zoneCountriesValEl = document.getElementById('previewZoneCountriesVal');
        if (zoneCountriesValEl) zoneCountriesValEl.textContent = data.countries_count || 0;
        const zoneMapsValEl = document.getElementById('previewZoneMapsVal');
        if (zoneMapsValEl) zoneMapsValEl.textContent = data.zone_maps_count || 0;

        // Render Zone Services Badges
        const zoneServicesList = document.getElementById('previewZoneServicesList');
        if (zoneServicesList) {
          if (data.services_summary && typeof data.services_summary === 'object') {
            let html = '';
            ['export', 'import'].forEach(function(dir) {
              const services = data.services_summary[dir];
              if (services && typeof services === 'object' && Object.keys(services).length > 0) {
                const dirLabel = (dir === 'export') ? 'Chiều Xuất (Export)' : 'Chiều Nhập (Import)';
                const chipClass = (dir === 'export') ? 'as-chip--blue' : 'as-chip--green';
                html += '<div style="margin-bottom: 12px;">';
                html += '<div style="font-weight: 700; font-size: 13px; color: var(--as-text-dark); margin-bottom: 6px;">' + dirLabel + ':</div>';
                html += '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
                Object.keys(services).forEach(function(svc) {
                  const count = services[svc] || 0;
                  html += '<span class="as-chip ' + chipClass + '" style="font-size: 12px; padding: 4px 10px;"><strong>' + svc + '</strong>: ' + Number(count).toLocaleString() + ' quốc gia</span>';
                });
                html += '</div></div>';
              }
            });
            zoneServicesList.innerHTML = html || '<span style="color:var(--as-text-muted);">Đã ánh xạ đầy đủ cho 6 dịch vụ chuẩn (EXW, WFM, WXP, WXS, XPD, XPR).</span>';
          } else {
            zoneServicesList.innerHTML = '<span style="color:var(--as-text-muted);">Đã ánh xạ đầy đủ cho 6 dịch vụ chuẩn (EXW, WFM, WXP, WXS, XPD, XPR).</span>';
          }
        }

        // Render Status Badge
        const badgeEl = document.getElementById('previewStatusBadge');
        if (badgeEl) {
          if (data.errors && data.errors.length > 0) {
            badgeEl.innerHTML = '<span class="as-badge as-badge--draft" style="background:#fee2e2;color:#b91c1c;border-color:#fecaca;">Có lỗi cấu trúc</span>';
          } else if (data.warnings && data.warnings.length > 0) {
            badgeEl.innerHTML = '<span class="as-badge as-badge--draft" style="background:#fffbeb;color:#92400e;border-color:#fde68a;">Có cảnh báo</span>';
          } else {
            badgeEl.innerHTML = '<span class="as-badge as-badge--active">Hợp lệ 100%</span>';
          }
        }

        // Render Blocking Errors
        const errContainer = document.getElementById('previewErrorsContainer');
        const errList = document.getElementById('previewErrorsList');
        if (errContainer && errList) {
          if (data.errors && data.errors.length > 0) {
            errList.innerHTML = data.errors.map(function(err) {
              return '<li>' + $('<div>').text(err).html() + '</li>';
            }).join('');
            errContainer.style.display = 'block';
            const execBtn = document.getElementById('btnExecuteImport');
            if (execBtn) execBtn.disabled = true;
          } else {
            errContainer.style.display = 'none';
            const execBtn = document.getElementById('btnExecuteImport');
            if (execBtn) execBtn.disabled = false;
          }
        }

        // Render Warnings
        const warnContainer = document.getElementById('previewWarningsContainer');
        const warnList = document.getElementById('previewWarningsList');
        if (warnContainer && warnList) {
          if (data.warnings && data.warnings.length > 0) {
            warnList.innerHTML = data.warnings.map(function(w) {
              return '<li>' + $('<div>').text(w).html() + '</li>';
            }).join('');
            warnContainer.style.display = 'block';
          } else {
            warnContainer.style.display = 'none';
          }
        }

        // Render Rate Groups Table
        const tbody = document.getElementById('previewRateGroupsBody');
        if (tbody) {
          if (!data.rate_groups || data.rate_groups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#64748b;">Không tìm thấy nhóm cước hợp lệ.</td></tr>';
          } else {
            tbody.innerHTML = data.rate_groups.map(function(rg) {
              return '<tr>' +
                '<td><span class="as-tag as-tag--on">' + $('<div>').text(rg.service_code || '-').html() + '</span></td>' +
                '<td><strong>' + $('<div>').text(rg.group_name).html() + '</strong></td>' +
                '<td><code>' + $('<div>').text(rg.group_key).html() + '</code></td>' +
                '<td style="text-align:right;"><span class="as-chip as-chip--blue">' + Number(rg.row_count).toLocaleString() + ' dòng</span></td>' +
              '</tr>';
            }).join('');
          }
        }

      }).fail(function() {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
        AllshipAdmin.showImportNotice('Lỗi kết nối máy chủ khi phân tích file.', true);
      });
    },

    backToStep1: function() {
      var tab2 = document.getElementById('stepTab2'); if (tab2) tab2.classList.remove('as-wizard-step--active');
      var tab1 = document.getElementById('stepTab1'); if (tab1) tab1.classList.add('as-wizard-step--active');
      document.getElementById('importStep2').style.display = 'none';
      document.getElementById('importStep1').style.display = 'block';
    },

    handleImportExecute: function() {
      if (!AllshipAdmin.currentImportToken) {
        alert('Thiếu token phiên import. Vui lòng thử lại.');
        return;
      }

      const btn = document.getElementById('btnExecuteImport');
      const originalText = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s infinite linear;"></span> Đang nạp dữ liệu vào Database...';
      }

      const isZoneMode = (AllshipAdmin.currentImportType === 'zones');

      $.post(config.ajaxUrl, {
        action: 'ups_import_execute',
        nonce: config.nonce,
        token: AllshipAdmin.currentImportToken,
        rate_card_name: (document.getElementById('importCardName') ? document.getElementById('importCardName').value : '') || '',
        valid_from: (document.getElementById('importValidFrom') ? document.getElementById('importValidFrom').value : '') || ''
      }, function(res) {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }

        if (!res.success) {
          alert((res.data && res.data.message) || '');
          return;
        }

        const data = res.data;

        // Update Stepper
        var sTab2 = document.getElementById('stepTab2'); if (sTab2) { sTab2.classList.remove('as-wizard-step--active'); sTab2.classList.add('as-wizard-step--completed'); }
        var sTab3 = document.getElementById('stepTab3'); if (sTab3) sTab3.classList.add('as-wizard-step--active');

        // Switch to Step 3
        document.getElementById('importStep2').style.display = 'none';
        document.getElementById('importStep3').style.display = 'block';

        // Populate summary
        if (isZoneMode) {
          document.getElementById('resCardIdRow').style.display = 'none';
          document.getElementById('resCardNameRow').style.display = 'none';
          document.getElementById('resRatesCountRow').style.display = 'none';
          document.getElementById('resZonesCountRow').style.display = 'table-row';
          var zImp = (data.zone_summary && data.zone_summary.total_zones_imported) || 0; document.getElementById('resZonesCount').textContent = zImp + ' dòng mapping';
          document.getElementById('resCompleteMsg').textContent = 'Đã cập nhật thành công danh mục phân vùng quốc gia!';
        } else {
          document.getElementById('resCardIdRow').style.display = 'table-row';
          document.getElementById('resCardNameRow').style.display = 'table-row';
          document.getElementById('resRatesCountRow').style.display = 'table-row';
          var zImpRow = (data.zone_summary && data.zone_summary.total_zones_imported) || 0; document.getElementById('resZonesCountRow').style.display = (zImpRow ? 'table-row' : 'none');
          document.getElementById('resCardId').textContent = '#' + (data.rate_card_id || '-');
          document.getElementById('resCardName').textContent = data.card_name || 'Bảng giá UPS';
          var rImp = (data.rate_summary && data.rate_summary.total_rates_imported) || 0; document.getElementById('resRatesCount').textContent = rImp + ' dòng';
          var zImp2 = (data.zone_summary && data.zone_summary.total_zones_imported) || 0; document.getElementById('resZonesCount').textContent = zImp2 + ' dòng';
          document.getElementById('resCompleteMsg').textContent = 'Đã import thành công bảng giá (Trạng thái: Draft)!';
        }

      }).fail(function() {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
        alert('Lỗi kết nối máy chủ khi thực hiện import.');
      });
    },

    handleImportCancel: function() {
      if (!confirm('Bạn có chắc chắn muốn hủy phiên import này? File đã tải lên sẽ bị xóa và không có dữ liệu nào được ghi.')) {
        return;
      }

      if (AllshipAdmin.currentImportToken) {
        $.post(config.ajaxUrl, {
          action: 'ups_import_cancel',
          nonce: config.nonce,
          token: AllshipAdmin.currentImportToken
        });
      }

      AllshipAdmin.resetImportWizard();
    },

    resetImportWizard: function() {
      AllshipAdmin.currentImportToken = null;
      AllshipAdmin.currentImportType  = 'rates';
      ['formImportUploadRates', 'formImportUploadZones', 'formImportUpload'].forEach(function(fid) { var f = document.getElementById(fid); if (f) f.reset(); });

      const rateDisplay = document.getElementById('rateFileNameDisplay');
      if (rateDisplay) {
        rateDisplay.textContent = 'Kéo thả file biểu phí hoặc bấm để chọn';
        rateDisplay.style.color = '';
      }

      const zoneDisplay = document.getElementById('zoneFileNameDisplayOnly') || document.getElementById('zoneFileNameDisplay');
      if (zoneDisplay) {
        zoneDisplay.textContent = 'Kéo thả file phân vùng (IATA) hoặc bấm để chọn';
        zoneDisplay.style.color = '';
      }

      var rTab2 = document.getElementById('stepTab2'); if (rTab2) rTab2.classList.remove('as-wizard-step--active', 'as-wizard-step--completed');
      var rTab3 = document.getElementById('stepTab3'); if (rTab3) rTab3.classList.remove('as-wizard-step--active', 'as-wizard-step--completed');
      var rTab1 = document.getElementById('stepTab1'); if (rTab1) rTab1.classList.add('as-wizard-step--active');

      document.getElementById('importStep2').style.display = 'none';
      document.getElementById('importStep3').style.display = 'none';
      document.getElementById('importStep1').style.display = 'block';

      AllshipAdmin.switchImportType('rates');
    },

    // =======================================================================
    // RATES EDIT PAGE (Step 5.4)
    // =======================================================================
    ratesCurrentPage: 1,
    ratesData: null,

    initRatesPage: function() {
      const cardSelect = document.getElementById('ratesFilterCard');
      const btnFilter = document.getElementById('btnRatesFilter');
      const btnReset = document.getElementById('btnRatesReset');
      const btnAdd = document.getElementById('btnAddRate');
      const btnDeleteSelected = document.getElementById('btnDeleteSelected');
      const checkAll = document.getElementById('ratesCheckAll');
      const formAdd = document.getElementById('formAddRate');
      const searchInput = document.getElementById('ratesFilterSearch');

      if (!cardSelect) return; // Not on rates page

      // Enable filter button when card selected
      cardSelect.addEventListener('change', function() {
        btnFilter.disabled = !this.value;
        if (this.value) {
          AllshipAdmin.loadRatesFilters(this.value);
          // Auto load rates when card changes
          AllshipAdmin.ratesCurrentPage = 1;
          AllshipAdmin.loadRates();
        } else {
          var container = document.getElementById('ratesTableContainer');
          var toolbar = document.getElementById('ratesToolbar');
          var empty = document.getElementById('ratesEmptyState');
          if (container) container.style.display = 'none';
          if (toolbar) toolbar.style.display = 'none';
          if (empty) empty.style.display = 'block';
        }
      });

      // Auto-load filters and table if card pre-selected
      if (cardSelect.value) {
        btnFilter.disabled = false;
        AllshipAdmin.loadRatesFilters(cardSelect.value);
        AllshipAdmin.ratesCurrentPage = 1;
        AllshipAdmin.loadRates();
      }

      btnFilter.addEventListener('click', function() {
        AllshipAdmin.ratesCurrentPage = 1;
        AllshipAdmin.loadRates();
      });

      btnReset.addEventListener('click', function() {
        document.getElementById('ratesFilterGroup').value = '';
        document.getElementById('ratesFilterZone').value = '';
        document.getElementById('ratesFilterBilling').value = '';
        searchInput.value = '';
        AllshipAdmin.ratesCurrentPage = 1;
        if (cardSelect.value) {
          AllshipAdmin.loadRates();
        }
      });

      // Auto filter when selects change
      const autoFilterSelects = ['ratesFilterGroup', 'ratesFilterZone', 'ratesFilterBilling'];
      autoFilterSelects.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
          el.addEventListener('change', function() {
            if (cardSelect.value) {
              AllshipAdmin.ratesCurrentPage = 1;
              AllshipAdmin.loadRates();
            }
          });
        }
      });

      // Auto filter on search input (with debounce)
      let searchTimeout;
      searchInput.addEventListener('input', function(e) {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
          if (cardSelect.value) {
            AllshipAdmin.ratesCurrentPage = 1;
            AllshipAdmin.loadRates();
          }
        }, 400); // 400ms debounce
      });

      // Enter key on search
      searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(searchTimeout);
          if (cardSelect.value) {
            AllshipAdmin.ratesCurrentPage = 1;
            AllshipAdmin.loadRates();
          }
        }
      });

      // Add row button
      btnAdd.addEventListener('click', function() {
        const modal = document.getElementById('modalAddRate');
        if (modal) {
          document.getElementById('editRateId').value = '';
          document.getElementById('modalRateTitle').textContent = 'Thêm dòng cước mới';
          var btnSubmit = document.getElementById('btnSubmitAddRate');
          if(btnSubmit) btnSubmit.innerHTML = '<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Thêm dòng cước';
          document.getElementById('formAddRate').reset();

          modal.style.display = 'flex';
          // Pre-fill rate_group and zone from current filter
          const grpFilter = document.getElementById('ratesFilterGroup');
          const zoneFilter = document.getElementById('ratesFilterZone');
          if (grpFilter && grpFilter.value) {
            document.getElementById('addRateGroup').value = grpFilter.value;
          }
          if (zoneFilter && zoneFilter.value) {
            document.getElementById('addRateZone').value = zoneFilter.value;
          }
          var rl = document.getElementById('addRateLabel'); if (rl) rl.focus();
        }
      });

      // Edit selected button
      const btnEditSelected = document.getElementById('btnEditSelected');
      if (btnEditSelected) {
        btnEditSelected.addEventListener('click', function() {
          AllshipAdmin.handleEditSelected();
        });
      }

      // Submit add row
      formAdd.addEventListener('submit', function(e) {
        e.preventDefault();
        AllshipAdmin.handleAddRate();
      });

      // Delete selected
      btnDeleteSelected.addEventListener('click', function() {
        AllshipAdmin.handleDeleteSelected();
      });

      // Check all
      checkAll.addEventListener('change', function() {
        document.querySelectorAll('.as-rates-row-check').forEach(function(cb) {
          cb.checked = checkAll.checked;
        });
        AllshipAdmin.updateDeleteButton();
      });
    },

    loadRatesFilters: function(rateCardId) {
      $.ajax({
        url: config.ajaxUrl,
        type: 'GET',
        data: {
          action: 'ups_rates_get_filters',
          nonce: config.nonce,
          rate_card_id: rateCardId
        }
      }).done(function(res) {
        if (!res.success) return;
        var d = res.data;

        var grpSel = document.getElementById('ratesFilterGroup');
        var zoneSel = document.getElementById('ratesFilterZone');
        var billSel = document.getElementById('ratesFilterBilling');

        // Populate rate_groups
        var oldGrp = grpSel.value;
        grpSel.innerHTML = '<option value="">Tất cả</option>';
        (d.rate_groups || []).forEach(function(g) {
          grpSel.innerHTML += '<option value="' + AllshipAdmin.escHtml(g) + '">' + AllshipAdmin.escHtml(g) + '</option>';
        });
        if (oldGrp) grpSel.value = oldGrp;

        // Populate zones
        var oldZone = zoneSel.value;
        zoneSel.innerHTML = '<option value="">Tất cả</option>';
        (d.zones || []).forEach(function(z) {
          zoneSel.innerHTML += '<option value="' + AllshipAdmin.escHtml(z) + '">' + AllshipAdmin.escHtml(z) + '</option>';
        });
        if (oldZone) zoneSel.value = oldZone;

        // Populate billing_units
        var oldBill = billSel.value;
        billSel.innerHTML = '<option value="">Tất cả</option>';
        (d.billing_units || []).forEach(function(b) {
          billSel.innerHTML += '<option value="' + AllshipAdmin.escHtml(b) + '">' + AllshipAdmin.escHtml(b) + '</option>';
        });
        if (oldBill) billSel.value = oldBill;

        // Populate selects for Add Rate modal
        var addGrp = document.getElementById('addRateGroup');
        if (addGrp) {
          var oldAddGrp = addGrp.value;
          addGrp.innerHTML = '<option value="">— Chọn Rate Group —</option>';
          (d.rate_groups || []).forEach(function(g) {
            addGrp.innerHTML += '<option value="' + AllshipAdmin.escHtml(g) + '">' + AllshipAdmin.escHtml(g) + '</option>';
          });
          if (oldAddGrp) addGrp.value = oldAddGrp;
        }

        var addZone = document.getElementById('addRateZone');
        if (addZone) {
          var oldAddZone = addZone.value;
          addZone.innerHTML = '<option value="">— Chọn Zone —</option>';
          (d.zones || []).forEach(function(z) {
            addZone.innerHTML += '<option value="' + AllshipAdmin.escHtml(z) + '">' + AllshipAdmin.escHtml(z) + '</option>';
          });
          if (oldAddZone) addZone.value = oldAddZone;
        }
      });
    },

    loadRates: function() {
      var cardId = document.getElementById('ratesFilterCard').value;
      if (!cardId) return;

      var tbody = document.getElementById('ratesTableBody');
      tbody.innerHTML = '<tr><td colspan="11" class="as-rates-loading"><span class="dashicons dashicons-update"></span> Đang tải...</td></tr>';

      // Show table, hide empty state
      document.getElementById('ratesTableContainer').style.display = 'block';
      document.getElementById('ratesEmptyState').style.display = 'none';
      document.getElementById('ratesToolbar').style.display = 'flex';

      $.ajax({
        url: config.ajaxUrl,
        type: 'GET',
        data: {
          action: 'ups_rates_list',
          nonce: config.nonce,
          rate_card_id: cardId,
          rate_group: document.getElementById('ratesFilterGroup').value,
          zone: document.getElementById('ratesFilterZone').value,
          billing_unit: document.getElementById('ratesFilterBilling').value,
          search: document.getElementById('ratesFilterSearch').value,
          paged: AllshipAdmin.ratesCurrentPage
        }
      }).done(function(res) {
        if (!res.success) {
          tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;color:var(--as-danger);padding:20px;">' + AllshipAdmin.escHtml((res.data && res.data.message) || 'Lỗi.') + '</td></tr>';
          return;
        }

        AllshipAdmin.ratesData = res.data;
        AllshipAdmin.renderRatesTable(res.data);
        AllshipAdmin.renderRatesPagination(res.data);

        // Update count label
        var label = document.getElementById('ratesCountLabel');
        if (label) {
          label.textContent = res.data.total.toLocaleString() + ' dòng cước';
        }

        // Reset check-all
        var checkAll = document.getElementById('ratesCheckAll');
        if (checkAll) checkAll.checked = false;
        AllshipAdmin.updateDeleteButton();

      }).fail(function() {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;color:var(--as-danger);padding:20px;">Lỗi kết nối máy chủ.</td></tr>';
      });
    },

    renderRatesTable: function(data) {
      var tbody = document.getElementById('ratesTableBody');
      var items = data.items || [];

      if (items.length === 0) {
        tbody.innerHTML = '<tr class="as-rates-empty-row"><td colspan="11" style="text-align:center;color:var(--as-text-muted);padding:40px 20px;">Không tìm thấy dòng cước nào phù hợp.</td></tr>';
        return;
      }

      var html = '';
      items.forEach(function(item) {
        html += '<tr data-id="' + item.id + '">';
        html += '<td class="as-rates-col-check"><input type="checkbox" class="as-rates-row-check" value="' + item.id + '" onchange="AllshipAdmin.updateDeleteButton()"></td>';
        html += '<td style="color:var(--as-text-light);font-size:12px;">#' + item.id + '</td>';
        html += '<td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;">' + AllshipAdmin.escHtml(item.rate_group) + '</code></td>';
        html += '<td>' + AllshipAdmin.escHtml(item.zone) + '</td>';

        // Editable: weight_label
        html += AllshipAdmin.ratesEditableCell(item.id, 'weight_label', item.weight_label, 'text');

        // Editable: weight_from
        html += AllshipAdmin.ratesEditableCell(item.id, 'weight_from', item.weight_from !== null ? item.weight_from : '', 'number');

        // Editable: weight_to
        html += AllshipAdmin.ratesEditableCell(item.id, 'weight_to', item.weight_to !== null ? item.weight_to : '', 'number');

        // Billing unit badge (editable via select)
        html += AllshipAdmin.ratesEditableBillingCell(item.id, item.billing_unit);

        // Editable: price_vnd
        var priceDisplay = Number(item.price_vnd).toLocaleString('vi-VN');
        html += '<td class="as-rates-col-price as-rates-cell-editable" onclick="AllshipAdmin.startCellEdit(this)" data-id="' + item.id + '" data-field="price_vnd">';
        html += '<span class="as-rates-cell-value">' + priceDisplay + '</span>';
        html += '<input class="as-rates-cell-input" type="number" value="' + item.price_vnd + '" min="0" onblur="AllshipAdmin.saveCellEdit(this)" onkeydown="AllshipAdmin.cellKeyHandler(event,this)">';
        html += '</td>';

        // Editable: sort_order
        html += AllshipAdmin.ratesEditableCell(item.id, 'sort_order', item.sort_order, 'number');

        // Delete button
        html += '<td style="text-align:center;"><button type="button" class="as-rates-btn-delete-row" title="Xóa" onclick="AllshipAdmin.deleteSingleRate(' + item.id + ')"><span class="dashicons dashicons-trash"></span></button></td>';

        html += '</tr>';
      });

      tbody.innerHTML = html;
    },

    ratesEditableCell: function(id, field, value, inputType) {
      var displayVal = (value !== null && value !== '' && value !== undefined) ? AllshipAdmin.escHtml(String(value)) : '<span style="color:var(--as-text-light);">—</span>';
      var html = '<td class="as-rates-cell-editable" onclick="AllshipAdmin.startCellEdit(this)" data-id="' + id + '" data-field="' + field + '">';
      html += '<span class="as-rates-cell-value">' + displayVal + '</span>';
      html += '<input class="as-rates-cell-input" type="' + inputType + '"' + (inputType === 'number' ? ' step="0.001"' : '') + ' value="' + AllshipAdmin.escAttr(String(value !== null && value !== undefined ? value : '')) + '" onblur="AllshipAdmin.saveCellEdit(this)" onkeydown="AllshipAdmin.cellKeyHandler(event,this)">';
      html += '</td>';
      return html;
    },

    ratesEditableBillingCell: function(id, value) {
      var badgeClass = 'as-rates-billing-badge as-rates-billing-badge--' + value;
      var html = '<td class="as-rates-cell-editable" onclick="AllshipAdmin.startBillingEdit(this)" data-id="' + id + '" data-field="billing_unit">';
      html += '<span class="as-rates-cell-value"><span class="' + badgeClass + '">' + AllshipAdmin.escHtml(value) + '</span></span>';
      html += '<select class="as-rates-cell-input" onchange="AllshipAdmin.saveCellEdit(this)" onblur="AllshipAdmin.saveCellEdit(this)">';
      ['flat', 'per_kg', 'minimum', 'envelope'].forEach(function(opt) {
        html += '<option value="' + opt + '"' + (opt === value ? ' selected' : '') + '>' + opt + '</option>';
      });
      html += '</select>';
      html += '</td>';
      return html;
    },

    startCellEdit: function(td) {
      if (td.classList.contains('as-rates-cell--editing')) return;
      td.classList.add('as-rates-cell--editing');
      var input = td.querySelector('.as-rates-cell-input');
      if (input) {
        input.focus();
        if (input.type !== 'number') {
          input.select();
        }
      }
    },

    startBillingEdit: function(td) {
      if (td.classList.contains('as-rates-cell--editing')) return;
      td.classList.add('as-rates-cell--editing');
      var sel = td.querySelector('.as-rates-cell-input');
      if (sel) sel.focus();
    },

    cellKeyHandler: function(e, input) {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        // Cancel edit — restore original value
        var td = input.closest('.as-rates-cell-editable');
        if (td) {
          td.classList.remove('as-rates-cell--editing');
        }
      }
    },

    saveCellEdit: function(input) {
      var td = input.closest('.as-rates-cell-editable');
      if (!td) return;

      td.classList.remove('as-rates-cell--editing');

      var id = td.dataset.id;
      var field = td.dataset.field;
      var newVal = input.value;

      // Check if value actually changed
      var valueSpan = td.querySelector('.as-rates-cell-value');
      var oldDisplayVal = valueSpan ? valueSpan.textContent.trim() : '';

      // For price_vnd, compare raw number
      if (field === 'price_vnd') {
        var oldNum = parseInt(oldDisplayVal.replace(/\D/g, ''), 10) || 0;
        if (oldNum === parseInt(newVal, 10)) return;
      }

      var tr = td.closest('tr');
      if (tr) tr.classList.add('as-rates-row--saving');

      $.post(config.ajaxUrl, {
        action: 'ups_rates_update_cell',
        nonce: config.nonce,
        id: id,
        field: field,
        value: newVal
      }, function(res) {
        if (tr) tr.classList.remove('as-rates-row--saving');

        if (res.success) {
          // Update display value
          if (field === 'price_vnd') {
            valueSpan.textContent = Number(newVal).toLocaleString('vi-VN');
          } else if (field === 'billing_unit') {
            valueSpan.innerHTML = '<span class="as-rates-billing-badge as-rates-billing-badge--' + newVal + '">' + AllshipAdmin.escHtml(newVal) + '</span>';
          } else {
            var display = (newVal !== '' && newVal !== null) ? AllshipAdmin.escHtml(String(newVal)) : '<span style="color:var(--as-text-light);">—</span>';
            valueSpan.innerHTML = display;
          }
          td.classList.add('as-rates-cell--saved');
          setTimeout(function() { td.classList.remove('as-rates-cell--saved'); }, 600);
        } else {
          td.classList.add('as-rates-cell--error');
          setTimeout(function() { td.classList.remove('as-rates-cell--error'); }, 600);
          AllshipAdmin.showRatesNotice((res.data && res.data.message) || '', true);
        }
      }).fail(function() {
        if (tr) tr.classList.remove('as-rates-row--saving');
        td.classList.add('as-rates-cell--error');
        setTimeout(function() { td.classList.remove('as-rates-cell--error'); }, 600);
      });
    },

    handleAddRate: function() {
      var btn = document.getElementById('btnSubmitAddRate');
      if (btn) btn.disabled = true;

      var cardId = document.getElementById('ratesFilterCard').value;
      if (!cardId) {
        alert('Vui lòng chọn Rate Card trước.');
        if (btn) btn.disabled = false;
        return;
      }

      var editId = document.getElementById('editRateId').value;
      var actionName = editId ? 'ups_rates_update_row' : 'ups_rates_add_row';
      var payload = {
        action: actionName,
        nonce: config.nonce,
        rate_card_id: cardId,
        rate_group: document.getElementById('addRateGroup').value,
        zone: document.getElementById('addRateZone').value,
        weight_label: document.getElementById('addRateLabel').value,
        weight_from: document.getElementById('addRateFrom').value,
        weight_to: document.getElementById('addRateTo').value,
        billing_unit: document.getElementById('addRateBilling').value,
        price_vnd: document.getElementById('addRatePrice').value,
        sort_order: document.getElementById('addRateSort').value
      };
      if (editId) {
        payload.id = editId;
      }

      $.post(config.ajaxUrl, payload, function(res) {
        if (btn) btn.disabled = false;
        if (res.success) {
          AllshipAdmin.closeModals();
          document.getElementById('formAddRate').reset();
          AllshipAdmin.showRatesNotice(res.data.message, false);
          if (!editId) {
            AllshipAdmin.loadRatesFilters(cardId);
          }
          AllshipAdmin.loadRates();
        } else {
          alert((res.data && res.data.message) || (editId ? 'Không thể cập nhật dòng cước.' : 'Không thể thêm dòng cước.'));
        }
      }).fail(function() {
        if (btn) btn.disabled = false;
        alert('Lỗi kết nối máy chủ.');
      });
    },

    handleEditSelected: function() {
      var checked = document.querySelectorAll('.as-rates-row-check:checked');
      if (checked.length !== 1) return;
      var id = parseInt(checked[0].value, 10);
      
      var item = null;
      if (AllshipAdmin.ratesData && AllshipAdmin.ratesData.items) {
        item = AllshipAdmin.ratesData.items.find(function(i) { return parseInt(i.id, 10) === id; });
      }
      if (!item) return;

      const modal = document.getElementById('modalAddRate');
      if (modal) {
        document.getElementById('editRateId').value = item.id;
        document.getElementById('modalRateTitle').textContent = 'Sửa dòng cước #' + item.id;
        var btnSubmit = document.getElementById('btnSubmitAddRate');
        if(btnSubmit) btnSubmit.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> Lưu thay đổi';
        
        document.getElementById('addRateGroup').value = item.rate_group;
        document.getElementById('addRateZone').value = item.zone;
        document.getElementById('addRateLabel').value = item.weight_label;
        document.getElementById('addRateFrom').value = item.weight_from !== null ? item.weight_from : '';
        document.getElementById('addRateTo').value = item.weight_to !== null ? item.weight_to : '';
        document.getElementById('addRateBilling').value = item.billing_unit;
        document.getElementById('addRatePrice').value = item.price_vnd;
        document.getElementById('addRateSort').value = item.sort_order;
        
        modal.style.display = 'flex';
      }
    },

    deleteSingleRate: function(id) {
      if (!confirm('Xóa dòng cước #' + id + '?')) return;
      $.post(config.ajaxUrl, {
        action: 'ups_rates_delete_rows',
        nonce: config.nonce,
        ids: JSON.stringify([id])
      }, function(res) {
        if (res.success) {
          AllshipAdmin.showRatesNotice(res.data.message, false);
          AllshipAdmin.loadRates();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi xóa.');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    handleDeleteSelected: function() {
      var checked = document.querySelectorAll('.as-rates-row-check:checked');
      if (checked.length === 0) return;

      if (!confirm('Xóa ' + checked.length + ' dòng cước đã chọn?')) return;

      var ids = [];
      checked.forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });

      $.post(config.ajaxUrl, {
        action: 'ups_rates_delete_rows',
        nonce: config.nonce,
        ids: JSON.stringify(ids)
      }, function(res) {
        if (res.success) {
          AllshipAdmin.showRatesNotice(res.data.message, false);
          AllshipAdmin.loadRates();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi xóa.');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    updateDeleteButton: function() {
      var checked = document.querySelectorAll('.as-rates-row-check:checked');
      var btnDel = document.getElementById('btnDeleteSelected');
      var btnEdit = document.getElementById('btnEditSelected');
      
      if (btnDel) {
        btnDel.style.display = checked.length > 0 ? 'inline-flex' : 'none';
        btnDel.textContent = 'Xóa ' + checked.length + ' dòng';
      }
      
      if (btnEdit) {
        btnEdit.style.display = checked.length === 1 ? 'inline-flex' : 'none';
      }
    },

    renderRatesPagination: function(data) {
      var topContainer = document.getElementById('ratesPaginationTop');
      var btmContainer = document.getElementById('ratesPaginationBottom');
      if (!topContainer || !btmContainer) return;

      if (data.pages <= 1) {
        topContainer.innerHTML = '<span class="as-rates-pg-info">Trang 1/' + data.pages + ' (' + data.total + ' dòng)</span>';
        btmContainer.style.display = 'none';
        return;
      }

      btmContainer.style.display = 'flex';

      var html = '<span class="as-rates-pg-info">Trang ' + data.page + '/' + data.pages + ' (' + data.total.toLocaleString() + ' dòng)</span>';

      // Prev
      html += '<button class="as-rates-pg-btn" ' + (data.page <= 1 ? 'disabled' : 'onclick="AllshipAdmin.goRatesPage(' + (data.page - 1) + ')"') + '>&laquo;</button>';

      // Page numbers (show max 7)
      var startPage = Math.max(1, data.page - 3);
      var endPage = Math.min(data.pages, startPage + 6);
      if (endPage - startPage < 6) {
        startPage = Math.max(1, endPage - 6);
      }

      for (var p = startPage; p <= endPage; p++) {
        html += '<button class="as-rates-pg-btn' + (p === data.page ? ' as-rates-pg-btn--active' : '') + '" onclick="AllshipAdmin.goRatesPage(' + p + ')">' + p + '</button>';
      }

      // Next
      html += '<button class="as-rates-pg-btn" ' + (data.page >= data.pages ? 'disabled' : 'onclick="AllshipAdmin.goRatesPage(' + (data.page + 1) + ')"') + '>&raquo;</button>';

      topContainer.innerHTML = html;
      btmContainer.innerHTML = html;
    },

    goRatesPage: function(page) {
      AllshipAdmin.ratesCurrentPage = page;
      AllshipAdmin.loadRates();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    showRatesNotice: function(msg, isError) {
      var el = document.getElementById('allshipRatesNotice');
      if (!el) return;
      el.className = 'notice is-dismissible ' + (isError ? 'notice-error' : 'notice-success');
      el.querySelector('p').textContent = msg;
      el.style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    // =======================================================================
    // ZONES EDIT PAGE (Step 5.5)
    // =======================================================================
    zonesCurrentPage: 1,
    zonesData: null,

    initZonesPage: function() {
      const cardSelect = document.getElementById('zonesFilterCard');
      const btnFilter = document.getElementById('btnZonesFilter');
      const btnReset = document.getElementById('btnZonesReset');
      const btnAdd = document.getElementById('btnAddZone');
      const btnDeleteSelected = document.getElementById('btnDeleteSelectedZones');
      const btnEditSelected = document.getElementById('btnEditSelectedZone');
      const checkAll = document.getElementById('zonesCheckAll');
      const formAdd = document.getElementById('formAddZone');
      const searchInput = document.getElementById('zonesFilterSearch');

      if (!cardSelect) return; // Not on zones page

      // Enable filter button when card selected
      cardSelect.addEventListener('change', function() {
        btnFilter.disabled = !this.value;
        if (this.value) {
          AllshipAdmin.loadZonesFilters(this.value);
          AllshipAdmin.zonesCurrentPage = 1;
          AllshipAdmin.loadZones();
        } else {
          var container = document.getElementById('zonesTableContainer');
          var toolbar = document.getElementById('zonesToolbar');
          var empty = document.getElementById('zonesEmptyState');
          if (container) container.style.display = 'none';
          if (toolbar) toolbar.style.display = 'none';
          if (empty) empty.style.display = 'block';
        }
      });

      // Auto-load filters and table if card pre-selected
      if (cardSelect.value) {
        btnFilter.disabled = false;
        AllshipAdmin.loadZonesFilters(cardSelect.value);
        AllshipAdmin.zonesCurrentPage = 1;
        AllshipAdmin.loadZones();
      }

      btnFilter.addEventListener('click', function() {
        AllshipAdmin.zonesCurrentPage = 1;
        AllshipAdmin.loadZones();
      });

      btnReset.addEventListener('click', function() {
        document.getElementById('zonesFilterDirection').value = '';
        document.getElementById('zonesFilterService').value = '';
        document.getElementById('zonesFilterZone').value = '';
        document.getElementById('zonesFilterStatus').value = '';
        searchInput.value = '';
        AllshipAdmin.zonesCurrentPage = 1;
        if (cardSelect.value) {
          AllshipAdmin.loadZones();
        }
      });

      // Auto filter when selects change
      const autoFilterSelects = ['zonesFilterDirection', 'zonesFilterService', 'zonesFilterZone', 'zonesFilterStatus'];
      autoFilterSelects.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
          el.addEventListener('change', function() {
            if (cardSelect.value) {
              AllshipAdmin.zonesCurrentPage = 1;
              AllshipAdmin.loadZones();
            }
          });
        }
      });

      // Auto filter on search input (with debounce)
      let searchTimeout;
      searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
          if (cardSelect.value) {
            AllshipAdmin.zonesCurrentPage = 1;
            AllshipAdmin.loadZones();
          }
        }, 400);
      });

      // Enter key on search
      searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(searchTimeout);
          if (cardSelect.value) {
            AllshipAdmin.zonesCurrentPage = 1;
            AllshipAdmin.loadZones();
          }
        }
      });

      // Add zone button
      btnAdd.addEventListener('click', function() {
        const modal = document.getElementById('modalAddZone');
        if (modal) {
          document.getElementById('editZoneId').value = '';
          document.getElementById('modalZoneTitle').textContent = 'Thêm vùng cước mới';
          var btnSubmit = document.getElementById('btnSubmitAddZone');
          if (btnSubmit) btnSubmit.innerHTML = '<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Thêm vùng cước';
          document.getElementById('formAddZone').reset();

          var dirFilter = document.getElementById('zonesFilterDirection');
          var svcFilter = document.getElementById('zonesFilterService');
          if (dirFilter && dirFilter.value) {
            document.getElementById('addZoneDirection').value = dirFilter.value;
          }
          if (svcFilter && svcFilter.value) {
            document.getElementById('addZoneService').value = svcFilter.value;
          }

          modal.style.display = 'flex';
          var zc = document.getElementById('addZoneCountry'); if (zc) zc.focus();
        }
      });

      // Edit selected button
      if (btnEditSelected) {
        btnEditSelected.addEventListener('click', function() {
          AllshipAdmin.handleEditSelectedZone();
        });
      }

      // Submit add/edit form
      formAdd.addEventListener('submit', function(e) {
        e.preventDefault();
        AllshipAdmin.handleAddZone();
      });

      // Delete selected
      btnDeleteSelected.addEventListener('click', function() {
        AllshipAdmin.handleDeleteSelectedZones();
      });

      // Check all
      checkAll.addEventListener('change', function() {
        document.querySelectorAll('.as-zones-row-check').forEach(function(cb) {
          cb.checked = checkAll.checked;
        });
        AllshipAdmin.updateZoneDeleteButton();
      });
    },

    loadZonesFilters: function(rateCardId) {
      $.ajax({
        url: config.ajaxUrl,
        type: 'GET',
        data: {
          action: 'ups_zones_get_filters',
          nonce: config.nonce,
          rate_card_id: rateCardId,
          zone_set_id: rateCardId
        }
      }).done(function(res) {
        if (!res.success) return;
        var d = res.data;

        var svcSel = document.getElementById('zonesFilterService');
        var zoneSel = document.getElementById('zonesFilterZone');
        var addSvcSel = document.getElementById('addZoneService');

        if (svcSel) {
          var oldSvc = svcSel.value;
          svcSel.innerHTML = '<option value="">Tất cả</option>';
          (d.services || []).forEach(function(s) {
            svcSel.innerHTML += '<option value="' + AllshipAdmin.escHtml(s) + '">' + AllshipAdmin.escHtml(s) + '</option>';
          });
          if (oldSvc) svcSel.value = oldSvc;
        }

        if (zoneSel) {
          var oldZone = zoneSel.value;
          zoneSel.innerHTML = '<option value="">Tất cả</option>';
          (d.zones || []).forEach(function(z) {
            zoneSel.innerHTML += '<option value="' + AllshipAdmin.escHtml(z) + '">Zone ' + AllshipAdmin.escHtml(z) + '</option>';
          });
          if (oldZone) zoneSel.value = oldZone;
        }

        if (addSvcSel) {
          var oldAddSvc = addSvcSel.value;
          addSvcSel.innerHTML = '<option value="">— Chọn dịch vụ —</option>';
          (d.services && d.services.length > 0 ? d.services : ['EXW', 'WXS', 'XPD', 'XPR', 'WXP', 'WFM']).forEach(function(s) {
            addSvcSel.innerHTML += '<option value="' + AllshipAdmin.escHtml(s) + '">' + AllshipAdmin.escHtml(s) + '</option>';
          });
          if (oldAddSvc) addSvcSel.value = oldAddSvc;
        }
      });
    },

    loadZones: function() {
      var cardId = document.getElementById('zonesFilterCard').value;
      if (!cardId) return;

      var tbody = document.getElementById('zonesTableBody');
      tbody.innerHTML = '<tr><td colspan="9" class="as-rates-loading"><span class="dashicons dashicons-update"></span> Đang tải...</td></tr>';

      document.getElementById('zonesTableContainer').style.display = 'block';
      document.getElementById('zonesEmptyState').style.display = 'none';
      document.getElementById('zonesToolbar').style.display = 'flex';

      $.ajax({
        url: config.ajaxUrl,
        type: 'GET',
        data: {
          action: 'ups_zones_list',
          nonce: config.nonce,
          rate_card_id: cardId,
          zone_set_id: cardId,
          direction: document.getElementById('zonesFilterDirection').value,
          service_code: document.getElementById('zonesFilterService').value,
          zone: document.getElementById('zonesFilterZone').value,
          is_available: document.getElementById('zonesFilterStatus').value,
          search: document.getElementById('zonesFilterSearch').value,
          paged: AllshipAdmin.zonesCurrentPage
        }
      }).done(function(res) {
        if (!res.success) {
          tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--as-danger);padding:20px;">' + AllshipAdmin.escHtml((res.data && res.data.message) || 'Lỗi.') + '</td></tr>';
          return;
        }

        AllshipAdmin.zonesData = res.data;
        AllshipAdmin.renderZonesTable(res.data);
        AllshipAdmin.renderZonesPagination(res.data);

        var label = document.getElementById('zonesCountLabel');
        if (label) {
          label.textContent = res.data.total.toLocaleString() + ' vùng cước';
        }

        var checkAll = document.getElementById('zonesCheckAll');
        if (checkAll) checkAll.checked = false;
        AllshipAdmin.updateZoneDeleteButton();

      }).fail(function() {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--as-danger);padding:20px;">Lỗi kết nối máy chủ.</td></tr>';
      });
    },

    renderZonesTable: function(data) {
      var tbody = document.getElementById('zonesTableBody');
      var items = data.items || [];

      if (items.length === 0) {
        tbody.innerHTML = '<tr class="as-rates-empty-row"><td colspan="9" style="text-align:center;color:var(--as-text-muted);padding:40px 20px;">Không tìm thấy vùng cước nào phù hợp.</td></tr>';
        return;
      }

      var html = '';
      items.forEach(function(item) {
        var dirClass = item.direction === 'export' ? 'as-zone-dir-badge--export' : 'as-zone-dir-badge--import';
        var dirLabel = item.direction === 'export' ? 'Xuất khẩu' : 'Nhập khẩu';
        var statusClass = item.is_available ? 'as-zone-status-badge--active' : 'as-zone-status-badge--inactive';
        var statusLabel = item.is_available ? 'Có hiệu lực' : 'Không hỗ trợ';

        html += '<tr data-id="' + item.id + '">';
        html += '<td class="as-rates-col-check"><input type="checkbox" class="as-zones-row-check" value="' + item.id + '" onchange="AllshipAdmin.updateZoneDeleteButton()"></td>';
        html += '<td style="color:var(--as-text-light);font-size:12px;">#' + item.id + '</td>';
        html += '<td><span class="as-zone-iata-badge">' + AllshipAdmin.escHtml(item.iata_code) + '</span> ' + AllshipAdmin.escHtml(item.country_name) + '</td>';
        html += '<td><span class="as-zone-dir-badge ' + dirClass + '">' + dirLabel + '</span></td>';
        html += '<td><span class="as-zone-service-badge">' + AllshipAdmin.escHtml(item.service_code) + '</span></td>';
        html += '<td>' + (item.service_type ? AllshipAdmin.escHtml(item.service_type) : '<span style="color:var(--as-text-light);">—</span>') + '</td>';

        // Editable Zone Cell
        var zoneDisplay = (item.zone !== null && item.zone !== '' && item.zone !== undefined) ? AllshipAdmin.escHtml(String(item.zone)) : '<span style="color:var(--as-text-light);">—</span>';
        html += '<td class="as-rates-cell-editable" onclick="AllshipAdmin.startZoneCellEdit(this)" data-id="' + item.id + '" data-field="zone">';
        html += '<span class="as-rates-cell-value">' + zoneDisplay + '</span>';
        html += '<input class="as-rates-cell-input" type="text" value="' + AllshipAdmin.escAttr(String(item.zone || '')) + '" onblur="AllshipAdmin.saveZoneCellEdit(this)" onkeydown="AllshipAdmin.cellKeyHandler(event,this)">';
        html += '</td>';

        // Status badge (click to toggle)
        html += '<td class="as-rates-cell-editable" onclick="AllshipAdmin.toggleZoneStatus(' + item.id + ', ' + (item.is_available ? 0 : 1) + ', this)" title="Bấm để đổi trạng thái">';
        html += '<span class="as-zone-status-badge ' + statusClass + '">' + statusLabel + '</span>';
        html += '</td>';

        // Actions: Delete button
        html += '<td style="text-align:center;"><button type="button" class="as-rates-btn-delete-row" title="Xóa" onclick="AllshipAdmin.deleteSingleZone(' + item.id + ')"><span class="dashicons dashicons-trash"></span></button></td>';

        html += '</tr>';
      });

      tbody.innerHTML = html;
    },

    startZoneCellEdit: function(td) {
      if (td.classList.contains('as-rates-cell--editing')) return;
      td.classList.add('as-rates-cell--editing');
      var input = td.querySelector('.as-rates-cell-input');
      if (input) {
        input.focus();
        input.select();
      }
    },

    saveZoneCellEdit: function(input) {
      var td = input.closest('.as-rates-cell-editable');
      if (!td) return;

      td.classList.remove('as-rates-cell--editing');

      var id = td.dataset.id;
      var field = td.dataset.field;
      var newVal = input.value.trim();

      var valueSpan = td.querySelector('.as-rates-cell-value');
      var oldDisplayVal = valueSpan ? valueSpan.textContent.trim() : '';
      if (oldDisplayVal === newVal || (oldDisplayVal === '—' && newVal === '')) return;

      var tr = td.closest('tr');
      if (tr) tr.classList.add('as-rates-row--saving');

      $.post(config.ajaxUrl, {
        action: 'ups_zones_update_cell',
        nonce: config.nonce,
        id: id,
        field: field,
        value: newVal
      }, function(res) {
        if (tr) tr.classList.remove('as-rates-row--saving');

        if (res.success) {
          valueSpan.innerHTML = (newVal !== '' && newVal !== null) ? AllshipAdmin.escHtml(String(newVal)) : '<span style="color:var(--as-text-light);">—</span>';
          td.classList.add('as-rates-cell--saved');
          setTimeout(function() { td.classList.remove('as-rates-cell--saved'); }, 600);

          if (res.data && res.data.is_available !== null && res.data.is_available !== undefined) {
            var statusTd = tr ? tr.querySelector('.as-zone-status-badge') : null;
            if (statusTd) {
              statusTd.className = 'as-zone-status-badge ' + (res.data.is_available ? 'as-zone-status-badge--active' : 'as-zone-status-badge--inactive');
              statusTd.textContent = res.data.is_available ? 'Có hiệu lực' : 'Không hỗ trợ';
            }
          }
        } else {
          td.classList.add('as-rates-cell--error');
          setTimeout(function() { td.classList.remove('as-rates-cell--error'); }, 600);
          AllshipAdmin.showZonesNotice((res.data && res.data.message) || '', true);
        }
      }).fail(function() {
        if (tr) tr.classList.remove('as-rates-row--saving');
        td.classList.add('as-rates-cell--error');
        setTimeout(function() { td.classList.remove('as-rates-cell--error'); }, 600);
      });
    },

    toggleZoneStatus: function(id, newStatus, td) {
      var tr = td.closest('tr');
      if (tr) tr.classList.add('as-rates-row--saving');

      $.post(config.ajaxUrl, {
        action: 'ups_zones_update_cell',
        nonce: config.nonce,
        id: id,
        field: 'is_available',
        value: newStatus
      }, function(res) {
        if (tr) tr.classList.remove('as-rates-row--saving');
        if (res.success) {
          var badge = td.querySelector('.as-zone-status-badge');
          if (badge) {
            badge.className = 'as-zone-status-badge ' + (newStatus ? 'as-zone-status-badge--active' : 'as-zone-status-badge--inactive');
            badge.textContent = newStatus ? 'Có hiệu lực' : 'Không hỗ trợ';
            td.setAttribute('onclick', 'AllshipAdmin.toggleZoneStatus(' + id + ', ' + (newStatus ? 0 : 1) + ', this)');
          }
          td.classList.add('as-rates-cell--saved');
          setTimeout(function() { td.classList.remove('as-rates-cell--saved'); }, 600);
        } else {
          AllshipAdmin.showZonesNotice((res.data && res.data.message) || 'Lỗi cập nhật trạng thái.', true);
        }
      }).fail(function() {
        if (tr) tr.classList.remove('as-rates-row--saving');
        alert('Lỗi kết nối máy chủ.');
      });
    },

    handleAddZone: function() {
      var btn = document.getElementById('btnSubmitAddZone');
      if (btn) btn.disabled = true;

      var cardId = document.getElementById('zonesFilterCard').value;
      if (!cardId) {
        alert('Vui lòng chọn Rate Card trước.');
        if (btn) btn.disabled = false;
        return;
      }

      var editId = document.getElementById('editZoneId').value;
      var actionName = editId ? 'ups_zones_update_row' : 'ups_zones_add_row';
      var payload = {
        action: actionName,
        nonce: config.nonce,
        rate_card_id: cardId,
        zone_set_id: cardId,
        country_id: document.getElementById('addZoneCountry').value,
        direction: document.getElementById('addZoneDirection').value,
        service_code: document.getElementById('addZoneService').value,
        service_type: document.getElementById('addZoneType').value,
        zone: document.getElementById('addZoneCode').value,
        is_available: document.getElementById('addZoneAvailable').value
      };
      if (editId) {
        payload.id = editId;
      }

      $.post(config.ajaxUrl, payload, function(res) {
        if (btn) btn.disabled = false;
        if (res.success) {
          AllshipAdmin.closeModals();
          document.getElementById('formAddZone').reset();
          AllshipAdmin.showZonesNotice(res.data.message, false);
          if (!editId) {
            AllshipAdmin.loadZonesFilters(cardId);
          }
          AllshipAdmin.loadZones();
        } else {
          alert((res.data && res.data.message) || (editId ? 'Không thể cập nhật vùng cước.' : 'Không thể thêm vùng cước.'));
        }
      }).fail(function() {
        if (btn) btn.disabled = false;
        alert('Lỗi kết nối máy chủ.');
      });
    },

    handleEditSelectedZone: function() {
      var checked = document.querySelectorAll('.as-zones-row-check:checked');
      if (checked.length !== 1) return;
      var id = parseInt(checked[0].value, 10);

      var item = null;
      if (AllshipAdmin.zonesData && AllshipAdmin.zonesData.items) {
        item = AllshipAdmin.zonesData.items.find(function(i) { return parseInt(i.id, 10) === id; });
      }
      if (!item) return;

      const modal = document.getElementById('modalAddZone');
      if (modal) {
        document.getElementById('editZoneId').value = item.id;
        document.getElementById('modalZoneTitle').textContent = 'Sửa vùng cước #' + item.id;
        var btnSubmit = document.getElementById('btnSubmitAddZone');
        if (btnSubmit) btnSubmit.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> Lưu thay đổi';

        document.getElementById('addZoneCountry').value = item.country_id;
        document.getElementById('addZoneDirection').value = item.direction;
        document.getElementById('addZoneService').value = item.service_code;
        document.getElementById('addZoneType').value = item.service_type || '';
        document.getElementById('addZoneCode').value = item.zone || '';
        document.getElementById('addZoneAvailable').value = item.is_available;

        modal.style.display = 'flex';
      }
    },

    deleteSingleZone: function(id) {
      if (!confirm('Xóa vùng cước #' + id + '?')) return;
      $.post(config.ajaxUrl, {
        action: 'ups_zones_delete_rows',
        nonce: config.nonce,
        ids: JSON.stringify([id])
      }, function(res) {
        if (res.success) {
          AllshipAdmin.showZonesNotice(res.data.message, false);
          AllshipAdmin.loadZones();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi xóa.');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    handleDeleteSelectedZones: function() {
      var checked = document.querySelectorAll('.as-zones-row-check:checked');
      if (checked.length === 0) return;

      if (!confirm('Xóa ' + checked.length + ' vùng cước đã chọn?')) return;

      var ids = [];
      checked.forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });

      $.post(config.ajaxUrl, {
        action: 'ups_zones_delete_rows',
        nonce: config.nonce,
        ids: JSON.stringify(ids)
      }, function(res) {
        if (res.success) {
          AllshipAdmin.showZonesNotice(res.data.message, false);
          AllshipAdmin.loadZones();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi xóa.');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    updateZoneDeleteButton: function() {
      var checked = document.querySelectorAll('.as-zones-row-check:checked');
      var btnDel = document.getElementById('btnDeleteSelectedZones');
      var btnEdit = document.getElementById('btnEditSelectedZone');

      if (btnDel) {
        btnDel.style.display = checked.length > 0 ? 'inline-flex' : 'none';
        btnDel.textContent = 'Xóa ' + checked.length + ' dòng';
      }

      if (btnEdit) {
        btnEdit.style.display = checked.length === 1 ? 'inline-flex' : 'none';
      }
    },

    renderZonesPagination: function(data) {
      var topContainer = document.getElementById('zonesPaginationTop');
      var btmContainer = document.getElementById('zonesPaginationBottom');
      if (!topContainer || !btmContainer) return;

      if (data.pages <= 1) {
        topContainer.innerHTML = '<span class="as-rates-pg-info">Trang 1/' + data.pages + ' (' + data.total + ' dòng)</span>';
        btmContainer.style.display = 'none';
        return;
      }

      btmContainer.style.display = 'flex';

      var html = '<span class="as-rates-pg-info">Trang ' + data.page + '/' + data.pages + ' (' + data.total.toLocaleString() + ' dòng)</span>';

      html += '<button class="as-rates-pg-btn" ' + (data.page <= 1 ? 'disabled' : 'onclick="AllshipAdmin.goZonesPage(' + (data.page - 1) + ')"') + '>&laquo;</button>';

      var startPage = Math.max(1, data.page - 3);
      var endPage = Math.min(data.pages, startPage + 6);
      if (endPage - startPage < 6) {
        startPage = Math.max(1, endPage - 6);
      }

      for (var p = startPage; p <= endPage; p++) {
        html += '<button class="as-rates-pg-btn' + (p === data.page ? ' as-rates-pg-btn--active' : '') + '" onclick="AllshipAdmin.goZonesPage(' + p + ')">' + p + '</button>';
      }

      html += '<button class="as-rates-pg-btn" ' + (data.page >= data.pages ? 'disabled' : 'onclick="AllshipAdmin.goZonesPage(' + (data.page + 1) + ')"') + '>&raquo;</button>';

      topContainer.innerHTML = html;
      btmContainer.innerHTML = html;
    },

    goZonesPage: function(page) {
      AllshipAdmin.zonesCurrentPage = page;
      AllshipAdmin.loadZones();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    showZonesNotice: function(msg, isError) {
      var el = document.getElementById('allshipZonesNotice');
      if (!el) return;
      el.className = 'notice is-dismissible ' + (isError ? 'notice-error' : 'notice-success');
      el.querySelector('p').textContent = msg;
      el.style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    // =======================================================================
    // COUNTRIES EDIT PAGE (Step 5.6)
    // =======================================================================
    countriesCurrentPage: 1,
    countriesData: null,

    initCountriesPage: function() {
      var searchInput = document.getElementById('countriesFilterSearch');
      var statusSelect = document.getElementById('countriesFilterStatus');
      var usSelect = document.getElementById('countriesFilterUS');
      var btnFilter = document.getElementById('btnCountriesFilter');
      var btnReset = document.getElementById('btnCountriesReset');
      var btnAdd = document.getElementById('btnAddCountry');
      var form = document.getElementById('formCountry');
      var checkAll = document.getElementById('countriesCheckAll');
      var btnBulkAct = document.getElementById('btnBulkActivate');
      var btnBulkDeact = document.getElementById('btnBulkDeactivate');

      if (!document.getElementById('countriesTable')) return; // Not on countries page

      // Initial load
      AllshipAdmin.loadCountries();

      // Search input with debounce
      var searchTimer = null;
      if (searchInput) {
        searchInput.addEventListener('input', function() {
          clearTimeout(searchTimer);
          searchTimer = setTimeout(function() {
            AllshipAdmin.countriesCurrentPage = 1;
            AllshipAdmin.loadCountries();
          }, 350);
        });

        searchInput.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimer);
            AllshipAdmin.countriesCurrentPage = 1;
            AllshipAdmin.loadCountries();
          }
        });
      }

      // Filter dropdown changes
      if (statusSelect) {
        statusSelect.addEventListener('change', function() {
          AllshipAdmin.countriesCurrentPage = 1;
          AllshipAdmin.loadCountries();
        });
      }
      if (usSelect) {
        usSelect.addEventListener('change', function() {
          AllshipAdmin.countriesCurrentPage = 1;
          AllshipAdmin.loadCountries();
        });
      }

      // Filter and Reset buttons
      if (btnFilter) {
        btnFilter.addEventListener('click', function() {
          AllshipAdmin.countriesCurrentPage = 1;
          AllshipAdmin.loadCountries();
        });
      }
      if (btnReset) {
        btnReset.addEventListener('click', function() {
          if (searchInput) searchInput.value = '';
          if (statusSelect) statusSelect.value = '';
          if (usSelect) usSelect.value = '';
          AllshipAdmin.countriesCurrentPage = 1;
          AllshipAdmin.loadCountries();
        });
      }

      // Add Country button
      if (btnAdd) {
        btnAdd.addEventListener('click', function() {
          AllshipAdmin.openAddCountryModal();
        });
      }

      // Form submit
      if (form) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          AllshipAdmin.handleSubmitCountry();
        });
      }

      // Check all
      if (checkAll) {
        checkAll.addEventListener('change', function() {
          document.querySelectorAll('.as-country-row-check').forEach(function(cb) {
            cb.checked = checkAll.checked;
          });
          AllshipAdmin.updateCountriesBulkButtons();
        });
      }

      // Bulk activate / deactivate
      if (btnBulkAct) {
        btnBulkAct.addEventListener('click', function() {
          AllshipAdmin.handleBulkCountriesStatus(1);
        });
      }
      if (btnBulkDeact) {
        btnBulkDeact.addEventListener('click', function() {
          AllshipAdmin.handleBulkCountriesStatus(0);
        });
      }
    },

    loadCountries: function() {
      var tbody = document.getElementById('countriesTableBody');
      if (!tbody) return;

      tbody.innerHTML = '<tr><td colspan="9" class="as-rates-loading"><span class="dashicons dashicons-update"></span> Đang tải danh sách quốc gia...</td></tr>';

      var searchVal = document.getElementById('countriesFilterSearch') ? document.getElementById('countriesFilterSearch').value : '';
      var statusVal = document.getElementById('countriesFilterStatus') ? document.getElementById('countriesFilterStatus').value : '';
      var usVal = document.getElementById('countriesFilterUS') ? document.getElementById('countriesFilterUS').value : '';

      $.ajax({
        url: config.ajaxUrl,
        type: 'GET',
        data: {
          action: 'ups_countries_list',
          nonce: config.nonce,
          search: searchVal,
          status: statusVal,
          is_us_override: usVal,
          paged: AllshipAdmin.countriesCurrentPage
        }
      }).done(function(res) {
        if (!res.success) {
          tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--as-danger);padding:25px;">' + AllshipAdmin.escHtml((res.data && res.data.message) || 'Lỗi khi tải dữ liệu.') + '</td></tr>';
          return;
        }

        AllshipAdmin.countriesData = res.data;
        AllshipAdmin.renderCountriesTable(res.data);
        AllshipAdmin.renderCountriesPagination(res.data);

        // Update count label
        var countLabel = document.getElementById('countriesCountLabel');
        if (countLabel) {
          countLabel.textContent = res.data.total.toLocaleString() + ' quốc gia (' + res.data.active_count.toLocaleString() + ' hoạt động / ' + res.data.inactive_count.toLocaleString() + ' tắt)';
        }

        // Reset check all
        var checkAll = document.getElementById('countriesCheckAll');
        if (checkAll) checkAll.checked = false;
        AllshipAdmin.updateCountriesBulkButtons();

      }).fail(function() {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--as-danger);padding:25px;">Lỗi kết nối máy chủ.</td></tr>';
      });
    },

    renderCountriesTable: function(data) {
      var tbody = document.getElementById('countriesTableBody');
      var items = data.items || [];

      if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--as-text-muted);padding:40px 20px;">Không tìm thấy quốc gia nào phù hợp.</td></tr>';
        return;
      }

      var html = '';
      items.forEach(function(item) {
        var isChecked = item.is_active === 1 ? 'checked' : '';
        var usBadgeClass = item.is_us_override === 1 ? 'as-country-override-badge--active' : 'as-country-override-badge--inactive';
        var usBadgeText = item.is_us_override === 1 ? 'US5 (Bật)' : 'Tắt';

        html += '<tr data-id="' + item.id + '">';
        html += '<td class="as-rates-col-check"><input type="checkbox" class="as-country-row-check" value="' + item.id + '" onchange="AllshipAdmin.updateCountriesBulkButtons()"></td>';
        html += '<td style="color:var(--as-text-light);font-size:12px;">#' + item.id + '</td>';
        html += '<td><span class="as-country-iata-badge">' + AllshipAdmin.escHtml(item.iata_code) + '</span></td>';
        html += '<td><strong>' + AllshipAdmin.escHtml(item.country_name) + '</strong></td>';
        html += '<td style="color:var(--as-text-muted);">' + AllshipAdmin.escHtml(item.normalized_name || '—') + '</td>';

        // US Override Toggle
        html += '<td style="text-align:center;"><span class="as-country-override-badge ' + usBadgeClass + '" onclick="AllshipAdmin.toggleCountryOverride(' + item.id + ')" title="Bấm để bật/tắt US Override">' + usBadgeText + '</span></td>';

        // Extended Area Note
        html += '<td style="text-align:center;">' + (item.has_extended_area_note === 1 ? '<span class="as-country-ext-badge">Có dấu *</span>' : '<span style="color:var(--as-text-light);">—</span>') + '</td>';

        // Toggle Switch for is_active
        html += '<td style="text-align:center;">';
        html += '<label class="as-switch" id="switchCountry_' + item.id + '">';
        html += '<input type="checkbox" ' + isChecked + ' onchange="AllshipAdmin.toggleCountryActive(' + item.id + ', this.checked, this)">';
        html += '<span class="as-switch-slider"></span>';
        html += '</label>';
        html += '</td>';

        // Edit button
        html += '<td style="text-align:center;">';
        html += '<button type="button" class="as-country-btn-edit" title="Chỉnh sửa" onclick="AllshipAdmin.openEditCountryModal(' + item.id + ')"><span class="dashicons dashicons-edit"></span></button>';
        html += '</td>';

        html += '</tr>';
      });

      tbody.innerHTML = html;
    },

    toggleCountryActive: function(id, isChecked, inputEl) {
      var switchContainer = document.getElementById('switchCountry_' + id);
      if (switchContainer) switchContainer.classList.add('is-loading');
      if (inputEl) inputEl.disabled = true;

      $.post(config.ajaxUrl, {
        action: 'ups_countries_toggle_active',
        nonce: config.nonce,
        id: id,
        is_active: isChecked ? 1 : 0
      }, function(res) {
        if (switchContainer) switchContainer.classList.remove('is-loading');
        if (inputEl) inputEl.disabled = false;

        if (res.success) {
          AllshipAdmin.showCountriesNotice(res.data.message, false);
          if (AllshipAdmin.countriesData) {
            if (isChecked) {
              AllshipAdmin.countriesData.active_count++;
              AllshipAdmin.countriesData.inactive_count--;
            } else {
              AllshipAdmin.countriesData.active_count--;
              AllshipAdmin.countriesData.inactive_count++;
            }
            var countLabel = document.getElementById('countriesCountLabel');
            if (countLabel) {
              countLabel.textContent = AllshipAdmin.countriesData.total.toLocaleString() + ' quốc gia (' + AllshipAdmin.countriesData.active_count.toLocaleString() + ' hoạt động / ' + AllshipAdmin.countriesData.inactive_count.toLocaleString() + ' tắt)';
            }
          }
        } else {
          if (inputEl) inputEl.checked = !isChecked;
          AllshipAdmin.showCountriesNotice((res.data && res.data.message) || 'Lỗi khi thay đổi trạng thái.', true);
        }
      }).fail(function() {
        if (switchContainer) switchContainer.classList.remove('is-loading');
        if (inputEl) {
          inputEl.disabled = false;
          inputEl.checked = !isChecked;
        }
        AllshipAdmin.showCountriesNotice('Lỗi kết nối máy chủ.', true);
      });
    },

    toggleCountryOverride: function(id) {
      $.post(config.ajaxUrl, {
        action: 'ups_countries_toggle_us_override',
        nonce: config.nonce,
        id: id
      }, function(res) {
        if (res.success) {
          AllshipAdmin.showCountriesNotice(res.data.message, false);
          AllshipAdmin.loadCountries();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi cập nhật US Override.');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    openAddCountryModal: function() {
      var modal = document.getElementById('modalCountry');
      if (!modal) return;

      document.getElementById('editCountryId').value = '';
      document.getElementById('modalCountryTitle').textContent = 'Thêm quốc gia mới';
      document.getElementById('btnSubmitCountryLabel').textContent = 'Thêm quốc gia';
      document.getElementById('formCountry').reset();

      modal.style.display = 'flex';
      var iata = document.getElementById('countryIata');
      if (iata) {
        iata.readOnly = false;
        iata.focus();
      }
    },

    openEditCountryModal: function(id) {
      var item = null;
      if (AllshipAdmin.countriesData && AllshipAdmin.countriesData.items) {
        item = AllshipAdmin.countriesData.items.find(function(c) { return c.id === id; });
      }
      if (!item) return;

      var modal = document.getElementById('modalCountry');
      if (!modal) return;

      document.getElementById('editCountryId').value = item.id;
      document.getElementById('modalCountryTitle').textContent = 'Sửa thông tin quốc gia #' + item.id;
      document.getElementById('btnSubmitCountryLabel').textContent = 'Lưu thay đổi';

      var iata = document.getElementById('countryIata');
      if (iata) {
        iata.value = item.iata_code;
        iata.readOnly = false;
      }
      document.getElementById('countryName').value = item.country_name;
      document.getElementById('countryNormName').value = item.normalized_name || '';
      document.getElementById('countryUsOverride').value = item.is_us_override;
      document.getElementById('countryExtended').value = item.has_extended_area_note;
      document.getElementById('countryActive').value = item.is_active;

      modal.style.display = 'flex';
    },

    handleSubmitCountry: function() {
      var btn = document.getElementById('btnSubmitCountry');
      if (btn) btn.disabled = true;

      var editId = document.getElementById('editCountryId').value;
      var actionName = editId ? 'ups_countries_update' : 'ups_countries_add';

      var payload = {
        action: actionName,
        nonce: config.nonce,
        iata_code: document.getElementById('countryIata').value,
        country_name: document.getElementById('countryName').value,
        normalized_name: document.getElementById('countryNormName').value,
        is_us_override: document.getElementById('countryUsOverride').value,
        has_extended_area_note: document.getElementById('countryExtended').value,
        is_active: document.getElementById('countryActive').value
      };
      if (editId) {
        payload.id = editId;
      }

      $.post(config.ajaxUrl, payload, function(res) {
        if (btn) btn.disabled = false;
        if (res.success) {
          AllshipAdmin.closeModals();
          AllshipAdmin.showCountriesNotice(res.data.message, false);
          AllshipAdmin.loadCountries();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi lưu thông tin.');
        }
      }).fail(function() {
        if (btn) btn.disabled = false;
        alert('Lỗi kết nối máy chủ.');
      });
    },

    handleBulkCountriesStatus: function(isActive) {
      var checked = document.querySelectorAll('.as-country-row-check:checked');
      if (checked.length === 0) return;

      var actionText = isActive ? 'kích hoạt' : 'tắt kích hoạt';
      if (!confirm('Bạn có chắc muốn ' + actionText + ' ' + checked.length + ' quốc gia đã chọn?')) return;

      var ids = [];
      checked.forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });

      $.post(config.ajaxUrl, {
        action: 'ups_countries_bulk_status',
        nonce: config.nonce,
        ids: JSON.stringify(ids),
        is_active: isActive
      }, function(res) {
        if (res.success) {
          AllshipAdmin.showCountriesNotice(res.data.message, false);
          AllshipAdmin.loadCountries();
        } else {
          alert((res.data && res.data.message) || 'Lỗi khi thực hiện thao tác.');
        }
      }).fail(function() {
        alert('Lỗi kết nối máy chủ.');
      });
    },

    updateCountriesBulkButtons: function() {
      var checked = document.querySelectorAll('.as-country-row-check:checked');
      var btnAct = document.getElementById('btnBulkActivate');
      var btnDeact = document.getElementById('btnBulkDeactivate');

      if (btnAct) {
        btnAct.style.display = checked.length > 0 ? 'inline-flex' : 'none';
        btnAct.innerHTML = '<span class="dashicons dashicons-yes-alt" style="margin-top:3px;"></span> Bật ' + checked.length + ' đã chọn';
      }
      if (btnDeact) {
        btnDeact.style.display = checked.length > 0 ? 'inline-flex' : 'none';
        btnDeact.innerHTML = '<span class="dashicons dashicons-dismiss" style="margin-top:3px;"></span> Tắt ' + checked.length + ' đã chọn';
      }
    },

    renderCountriesPagination: function(data) {
      var topContainer = document.getElementById('countriesPaginationTop');
      var btmContainer = document.getElementById('countriesPaginationBottom');
      if (!topContainer || !btmContainer) return;

      if (data.pages <= 1) {
        topContainer.innerHTML = '<span class="as-rates-pg-info">Trang 1/' + data.pages + ' (' + data.total.toLocaleString() + ' mục)</span>';
        btmContainer.style.display = 'none';
        return;
      }

      btmContainer.style.display = 'flex';
      var html = '<span class="as-rates-pg-info">Trang ' + data.page + '/' + data.pages + ' (' + data.total.toLocaleString() + ' mục)</span>';

      // Prev
      html += '<button class="as-rates-pg-btn" ' + (data.page <= 1 ? 'disabled' : 'onclick="AllshipAdmin.goCountriesPage(' + (data.page - 1) + ')"') + '>&laquo;</button>';

      var startPage = Math.max(1, data.page - 3);
      var endPage = Math.min(data.pages, startPage + 6);
      if (endPage - startPage < 6) {
        startPage = Math.max(1, endPage - 6);
      }

      for (var p = startPage; p <= endPage; p++) {
        html += '<button class="as-rates-pg-btn' + (p === data.page ? ' as-rates-pg-btn--active' : '') + '" onclick="AllshipAdmin.goCountriesPage(' + p + ')">' + p + '</button>';
      }

      // Next
      html += '<button class="as-rates-pg-btn" ' + (data.page >= data.pages ? 'disabled' : 'onclick="AllshipAdmin.goCountriesPage(' + (data.page + 1) + ')"') + '>&raquo;</button>';

      topContainer.innerHTML = html;
      btmContainer.innerHTML = html;
    },

    goCountriesPage: function(p) {
      AllshipAdmin.countriesCurrentPage = p;
      AllshipAdmin.loadCountries();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    showCountriesNotice: function(msg, isError) {
      var el = document.getElementById('allshipCountriesNotice');
      if (!el) return;
      el.className = 'notice is-dismissible ' + (isError ? 'notice-error' : 'notice-success');
      el.querySelector('p').textContent = msg;
      el.style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },


    escHtml: function(str) {
      if (!str && str !== 0) return '';
      var d = document.createElement('div');
      d.textContent = String(str);
      return d.innerHTML;
    },

    escAttr: function(str) {
      if (!str && str !== 0) return '';
      return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
  };

  window.AllshipAdmin = AllshipAdmin;

  $(document).ready(function() {
    AllshipAdmin.init();
    AllshipAdmin.initRatesPage();
    AllshipAdmin.initZonesPage();
    AllshipAdmin.initCountriesPage();
  });

})(window, document, jQuery);

