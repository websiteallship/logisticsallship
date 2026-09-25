jQuery(document).ready(function ($) {
    if (typeof ffFrontendEntries === 'undefined') {
        return;
    }

    const container = $('.ff-lead-dashboard');
    const tableBody = container.find('.ff-frontend-table tbody');
    const pagination = container.find('.ff-pagination');
    const paginationInfo = container.find('.ff-pagination-info');
    const newLeadAlert = container.find('.ff-new-lead-alert');
    const modal = $('#ff-detail-modal');
    
    let currentPage = 1;
    let latestEntryId = 0;
    let currentEntries = [];
    let activeModalEntry = null;
    let currentXhr = null;
    let searchDebounceTimer = null;

    const fieldMap = {
        'names': 'Họ và tên',
        'name': 'Họ và tên',
        'ho_va_ten': 'Họ và tên',
        'ho_ten': 'Họ và tên',
        'full_name': 'Họ và tên',
        'first_name': 'Tên',
        'last_name': 'Họ',
        'phone': 'Số điện thoại',
        'so_dien_thoai': 'Số điện thoại',
        'mobile': 'Số điện thoại',
        'tel': 'Số điện thoại',
        'email': 'Email',
        'ten_cong_ty': 'Công ty / Doanh nghiệp',
        'ho_ten_cong_ty': 'Công ty / Doanh nghiệp',
        'cong_ty': 'Công ty / Doanh nghiệp',
        'company': 'Công ty / Doanh nghiệp',
        'dich_vu_quan_tam': 'Dịch vụ quan tâm',
        'service': 'Dịch vụ quan tâm',
        'dich_vu': 'Dịch vụ quan tâm',
        'noi_dung_yeu_cau': 'Nội dung yêu cầu',
        'ghi_chu': 'Ghi chú / Yêu cầu',
        'message': 'Nội dung tin nhắn',
        'subject': 'Tiêu đề'
    };

    function getFieldLabel(key) {
        return fieldMap[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    function loadEntries(isSilent = false, isRealtime = false) {
        const form_id = container.find('.ff-form-filter').val() || 0;
        const status  = container.find('.ff-status-filter').val();
        const search  = container.find('.ff-search-input').val();
        const tableWrapper = container.find('.ff-table-wrapper');
        const searchSpinner = container.find('.ff-search-spinner');

        // Hủy request trước nếu đang chạy để tránh chèn kết quả cũ
        if (currentXhr && currentXhr.readyState !== 4) {
            currentXhr.abort();
        }

        if (isRealtime) {
            container.find('.ff-search-icon').hide();
            searchSpinner.show();
            tableWrapper.addClass('is-loading');
        } else if (!isSilent) {
            tableWrapper.addClass('is-loading');
            if (tableBody.children().length === 0 || tableBody.find('.dashicons-update').length > 0) {
                tableBody.html('<tr><td colspan="8" style="text-align:center; padding: 40px;"><span class="dashicons dashicons-update rotating"></span> Đang tải dữ liệu...</td></tr>');
            }
        }

        currentXhr = $.ajax({
            url: ffFrontendEntries.ajax_url,
            type: 'POST',
            data: {
                action: 'ff_frontend_entries',
                nonce: ffFrontendEntries.nonce,
                form_id: form_id,
                page: currentPage,
                status: status,
                search: search
            },
            success: function (response) {
                if (response.success) {
                    currentEntries = response.data.data || [];
                    if (currentEntries.length > 0) {
                        latestEntryId = Math.max(latestEntryId, parseInt(currentEntries[0].id) || 0);
                    }
                    renderTable(response.data);
                    updateStats(response.data.stats);
                    newLeadAlert.slideUp(200);
                } else {
                    tableBody.html('<tr><td colspan="8" style="color:#ce2027; text-align:center; padding: 25px;">' + (response.data && response.data.message ? response.data.message : 'Đã có lỗi xảy ra.') + '</td></tr>');
                }
            },
            error: function (xhr, status) {
                if (status !== 'abort') {
                    tableBody.html('<tr><td colspan="8" style="color:#ce2027; text-align:center; padding: 25px;">Không thể kết nối máy chủ.</td></tr>');
                }
            },
            complete: function () {
                searchSpinner.hide();
                container.find('.ff-search-icon').show();
                tableWrapper.removeClass('is-loading');
            }
        });
    }

    function updateStats(stats) {
        if (!stats) return;
        $('#ff-stat-total-val').text(stats.total || 0);
        $('#ff-stat-unread-val').text(stats.unread || 0);
        $('#ff-stat-read-val').text(stats.read || 0);

        if (stats.unread > 0) {
            $('#ff-card-unread').addClass('has-unread');
            $('.ff-unread-badge').show().text(stats.unread + ' MỚI');
        } else {
            $('#ff-card-unread').removeClass('has-unread');
            $('.ff-unread-badge').hide();
        }
    }

    function syncActiveCard(status) {
        $('.ff-stat-card').removeClass('is-active');
        if (status === 'unread') {
            $('.ff-stat-unread').addClass('is-active');
        } else if (status === 'read') {
            $('.ff-stat-read').addClass('is-active');
        } else if (status === '' || status === 'all') {
            $('.ff-stat-total').addClass('is-active');
        }
    }

    function extractLeadData(response) {
        let name = '';
        let company = '';
        let phone = '';
        let email = '';
        let service = '';
        let message = '';
        let otherFields = [];

        if (response && typeof response === 'object') {
            for (const [k, v] of Object.entries(response)) {
                if (v === null || v === undefined || v === '') continue;

                let valStr = (typeof v === 'object') ? Object.values(v).filter(Boolean).join(' ') : String(v);

                if (['names', 'name', 'ho_va_ten', 'ho_ten', 'full_name'].includes(k)) {
                    name = valStr;
                } else if (k === 'ho_ten_cong_ty') {
                    // Trường gộp Họ tên / Công ty
                    if (!name) {
                        name = valStr;
                    } else {
                        company = valStr;
                    }
                } else if (['ten_cong_ty', 'cong_ty', 'company'].includes(k)) {
                    company = valStr;
                } else if (['phone', 'so_dien_thoai', 'mobile', 'tel'].includes(k)) {
                    phone = valStr;
                } else if (['email'].includes(k)) {
                    email = valStr;
                } else if (['dich_vu_quan_tam', 'service', 'dich_vu'].includes(k)) {
                    const fallbackMap = {
                        'duong-bien': 'Đường biển (FCL/LCL)',
                        'hang-khong': 'Đường hàng không',
                        'noi-dia': 'Đường bộ nội địa',
                        'hai-quan': 'Dịch vụ hải quan',
                        'kho-bai': 'Kho bãi - Fulfillment',
                        'du-an': 'Hàng dự án',
                        'can-tu-van-chung': 'Cần tư vấn chung',
                        'khac': 'Khác'
                    };
                    service = fallbackMap[valStr] || valStr;
                } else if (['noi_dung_yeu_cau', 'ghi_chu', 'message', 'note'].includes(k)) {
                    message = valStr;
                } else {
                    otherFields.push({ key: k, label: getFieldLabel(k), value: valStr });
                }
            }
        }

        // Nếu chỉ có company mà không có name, hiển thị company làm tiêu đề chính
        if (!name && company) {
            name = company;
            company = '';
        }

        return { name, company, phone, email, service, message, otherFields };
    }

    function renderTable(data) {
        tableBody.empty();

        if (!data || !data.data || data.data.length === 0) {
            tableBody.html('<tr><td colspan="8" style="text-align:center; padding: 40px; color: #64748b;">Không tìm thấy lead nào phù hợp với bộ lọc.</td></tr>');
            pagination.empty();
            paginationInfo.empty();
            return;
        }

        data.data.forEach(function (entry) {
            try {
                const lead = extractLeadData(entry.response);
                const isUnread = entry.status === 'unread';

                // Khách hàng
                let customerHtml = '<div class="ff-customer-cell">';
                if (lead.name) {
                    customerHtml += `<div class="ff-lead-name">${escapeHtml(lead.name)}</div>`;
                }
                if (lead.company && lead.company !== lead.name) {
                    customerHtml += `<div class="ff-lead-company"><span class="dashicons dashicons-building"></span> ${escapeHtml(lead.company)}</div>`;
                }
                if (!lead.name && !lead.company) {
                    customerHtml += `<span class="ff-text-muted">-</span>`;
                }
                customerHtml += '</div>';

                // Liên hệ
                let contactHtml = '<div class="ff-contact-cell">';
                if (lead.phone) {
                    contactHtml += `<a href="tel:${escapeHtml(lead.phone)}" class="ff-contact-phone" title="Bấm gọi ngay"><span class="dashicons dashicons-phone"></span> ${escapeHtml(lead.phone)}</a>`;
                }
                if (lead.email) {
                    contactHtml += `<a href="mailto:${escapeHtml(lead.email)}" class="ff-contact-email" title="Gửi email"><span class="dashicons dashicons-email"></span> ${escapeHtml(lead.email)}</a>`;
                }
                if (!lead.phone && !lead.email) {
                    contactHtml += '<span class="ff-text-muted">-</span>';
                }
                contactHtml += '</div>';

                // Nhu cầu & Dịch vụ
                let needHtml = '<div class="ff-need-cell">';
                if (lead.service) {
                    needHtml += `<span class="ff-service-badge">${escapeHtml(lead.service)}</span>`;
                }
                if (lead.message) {
                    let shortMsg = lead.message.length > 70 ? lead.message.substring(0, 70) + '...' : lead.message;
                    needHtml += `<div class="ff-lead-message" title="${escapeHtml(lead.message)}">${escapeHtml(shortMsg)}</div>`;
                }
                if (!lead.service && !lead.message) {
                    needHtml += '<span class="ff-text-muted">-</span>';
                }
                needHtml += '</div>';

                // Trạng thái badge
                let statusBadge = isUnread 
                    ? '<span class="ff-status-badge ff-status-unread" title="Click để chuyển sang Đã đọc">Chưa đọc</span>'
                    : '<span class="ff-status-badge ff-status-read" title="Click để chuyển sang Chưa đọc">Đã đọc</span>';
                
                if (entry.status === 'trashed') {
                    statusBadge = '<span class="ff-status-badge ff-status-trashed">Thùng rác</span>';
                }

                let formTitle = entry.form_title || ('Form #' + entry.form_id);

                const tr = `
                    <tr class="${isUnread ? 'ff-row-unread' : ''}" data-id="${entry.id}">
                        <td class="ff-col-id">#${entry.id}</td>
                        <td class="ff-col-form"><span class="ff-form-badge" title="${escapeHtml(formTitle)}">${escapeHtml(formTitle)}</span></td>
                        <td>${customerHtml}</td>
                        <td>${contactHtml}</td>
                        <td>${needHtml}</td>
                        <td style="text-align:center;">
                            <button type="button" class="ff-btn-toggle-row-status" data-id="${entry.id}" data-status="${entry.status}" title="Click để đổi trạng thái">
                                ${statusBadge}
                            </button>
                        </td>
                        <td class="ff-col-date">${entry.created_at}</td>
                        <td class="ff-col-action" style="text-align:center;">
                            <button type="button" class="ff-btn-detail" data-id="${entry.id}" title="Xem chi tiết">
                                <span class="dashicons dashicons-visibility"></span> Chi tiết
                            </button>
                        </td>
                    </tr>
                `;
                tableBody.append(tr);
            } catch (err) {
                console.error("Lỗi render lead:", entry, err);
            }
        });

        renderPagination(data);
    }

    function renderPagination(data) {
        pagination.empty();
        paginationInfo.text(`Hiển thị ${data.data.length} / tổng số ${data.total} lead`);

        if (data.last_page <= 1) return;

        if (currentPage > 1) {
            pagination.append(`<button type="button" class="ff-page-btn" data-page="${currentPage - 1}">‹ Trước</button>`);
        }

        for (let i = 1; i <= data.last_page; i++) {
            if (i === 1 || i === data.last_page || (i >= currentPage - 2 && i <= currentPage + 2)) {
                const activeClass = (i === currentPage) ? 'active' : '';
                pagination.append(`<button type="button" class="ff-page-btn ${activeClass}" data-page="${i}">${i}</button>`);
            } else if (i === currentPage - 3 || i === currentPage + 3) {
                pagination.append(`<span class="ff-page-dots">...</span>`);
            }
        }

        if (currentPage < data.last_page) {
            pagination.append(`<button type="button" class="ff-page-btn" data-page="${currentPage + 1}">Sau ›</button>`);
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Modal View Detail
    function openDetailModal(entryId) {
        const entry = currentEntries.find(e => String(e.id) === String(entryId));
        if (!entry) return;

        activeModalEntry = entry;
        const lead = extractLeadData(entry.response);

        // Tự động chuyển thành 'read' (Đã đọc) vào database FluentForm khi xem chi tiết
        if (entry.status === 'unread') {
            updateEntryStatus(entry.id, 'read', function () {
                entry.status = 'read';
                updateModalStatusBtn('read');
                const row = container.find(`tr[data-id="${entry.id}"]`);
                row.removeClass('ff-row-unread');
                row.find('.ff-status-badge')
                    .removeClass('ff-status-unread')
                    .addClass('ff-status-read')
                    .text('Đã đọc');
                row.find('.ff-btn-toggle-row-status').data('status', 'read');
            });
        }

        $('#ff-modal-lead-id').text('#' + entry.id);
        $('#ff-modal-form-badge').text(entry.form_title || ('Form #' + entry.form_id));

        // Contact Info
        let contactHtml = '';
        if (lead.name) contactHtml += `<div class="ff-modal-field"><label>Họ tên / Khách hàng:</label><strong>${escapeHtml(lead.name)}</strong></div>`;
        if (lead.company && lead.company !== lead.name) contactHtml += `<div class="ff-modal-field"><label>Công ty:</label><span>${escapeHtml(lead.company)}</span></div>`;
        if (lead.phone) contactHtml += `<div class="ff-modal-field"><label>Số điện thoại:</label><a href="tel:${escapeHtml(lead.phone)}" class="ff-contact-phone"><span class="dashicons dashicons-phone"></span> ${escapeHtml(lead.phone)}</a></div>`;
        if (lead.email) contactHtml += `<div class="ff-modal-field"><label>Email:</label><a href="mailto:${escapeHtml(lead.email)}" class="ff-contact-email"><span class="dashicons dashicons-email"></span> ${escapeHtml(lead.email)}</a></div>`;

        if (!contactHtml) contactHtml = '<p class="ff-text-muted">Không có thông tin liên hệ riêng biệt.</p>';
        $('#ff-modal-contact-info').html(contactHtml);

        // Request Info
        let requestHtml = '';
        if (lead.service) requestHtml += `<div class="ff-modal-field"><label>Dịch vụ quan tâm:</label><span class="ff-service-badge">${escapeHtml(lead.service)}</span></div>`;
        if (lead.message) requestHtml += `<div class="ff-modal-field"><label>Nội dung chi tiết:</label><div class="ff-modal-box">${escapeHtml(lead.message)}</div></div>`;
        
        if (lead.otherFields && lead.otherFields.length > 0) {
            lead.otherFields.forEach(f => {
                requestHtml += `<div class="ff-modal-field"><label>${escapeHtml(f.label)}:</label><span>${escapeHtml(f.value)}</span></div>`;
            });
        }
        if (!requestHtml) requestHtml = '<p class="ff-text-muted">Không có nội dung thêm.</p>';
        $('#ff-modal-request-info').html(requestHtml);

        // Meta info
        let metaHtml = `
            <span><span class="dashicons dashicons-clock"></span> <strong>Thời gian:</strong> ${entry.created_at}</span>
            <span><span class="dashicons dashicons-admin-generic"></span> <strong>Thiết bị:</strong> ${entry.browser || 'Web'}</span>
            <span><span class="dashicons dashicons-admin-site"></span> <strong>IP:</strong> ${entry.ip || '-'}</span>
        `;
        $('#ff-modal-meta-info').html(metaHtml);

        updateModalStatusBtn(entry.status);

        modal.fadeIn(150);
    }

    function updateModalStatusBtn(status) {
        const btn = $('#ff-btn-toggle-status');
        if (status === 'unread') {
            btn.text('Đánh dấu đã đọc').removeClass('is-read').addClass('is-unread');
        } else {
            btn.text('Đánh dấu chưa đọc').removeClass('is-unread').addClass('is-read');
        }
    }

    function updateEntryStatus(entryId, newStatus, callback) {
        const form_id = container.find('.ff-form-filter').val() || 0;
        $.ajax({
            url: ffFrontendEntries.ajax_url,
            type: 'POST',
            data: {
                action: 'ff_frontend_update_status',
                nonce: ffFrontendEntries.nonce,
                entry_id: entryId,
                status: newStatus,
                form_id: form_id
            },
            success: function (res) {
                if (res.success) {
                    if (res.data && res.data.stats) {
                        updateStats(res.data.stats);
                    }
                    if (callback) callback(newStatus);
                } else {
                    alert(res.data && res.data.message ? res.data.message : 'Lỗi cập nhật trạng thái.');
                }
            }
        });
    }

    // Export CSV
    function exportToCsv() {
        if (!currentEntries || currentEntries.length === 0) {
            alert('Không có dữ liệu để xuất file.');
            return;
        }

        let csvContent = "\uFEFF"; // UTF-8 BOM
        csvContent += "ID,Form,Khach Hang,Cong Ty,So Dien Thoai,Email,Dich Vu,Noi Dung,Trang Thai,Thoi Gian\n";

        currentEntries.forEach(entry => {
            const lead = extractLeadData(entry.response);
            const row = [
                entry.id,
                `"${(entry.form_title || '').replace(/"/g, '""')}"`,
                `"${(lead.name || '').replace(/"/g, '""')}"`,
                `"${(lead.company || '').replace(/"/g, '""')}"`,
                `"${(lead.phone || '').replace(/"/g, '""')}"`,
                `"${(lead.email || '').replace(/"/g, '""')}"`,
                `"${(lead.service || '').replace(/"/g, '""')}"`,
                `"${(lead.message || '').replace(/"/g, '""')}"`,
                `"${entry.status}"`,
                `"${entry.created_at}"`
            ];
            csvContent += row.join(",") + "\n";
        });

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", `allship_leads_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Polling Real-time Check for New Leads (25s)
    setInterval(function () {
        if (latestEntryId === 0) return;

        $.ajax({
            url: ffFrontendEntries.ajax_url,
            type: 'POST',
            data: {
                action: 'ff_frontend_check_new',
                nonce: ffFrontendEntries.nonce,
                latest_id: latestEntryId
            },
            success: function (res) {
                if (res.success && res.data.has_new) {
                    newLeadAlert.slideDown(200);
                    $('.ff-alert-text').text(`Có ${res.data.new_count} lead mới chưa đọc`);
                }
            }
        });
    }, 25000);

    // Realtime Search (250ms debounce)
    container.on('input', '.ff-search-input', function () {
        const val = $(this).val().trim();
        const clearBtn = container.find('.ff-search-clear');
        if (val.length > 0) {
            clearBtn.show();
        } else {
            clearBtn.hide();
        }

        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(function () {
            currentPage = 1;
            loadEntries(false, true);
        }, 250);
    });

    // Xóa tìm kiếm nhanh
    container.on('click', '.ff-search-clear', function () {
        const input = container.find('.ff-search-input');
        input.val('').focus();
        $(this).hide();
        currentPage = 1;
        loadEntries(false, true);
    });

    container.on('keypress', '.ff-search-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            clearTimeout(searchDebounceTimer);
            currentPage = 1;
            loadEntries(false, true);
        }
    });

    // Click vào stat card để lọc tương ứng
    container.on('click', '.ff-stat-card', function () {
        const targetStatus = $(this).data('status');
        container.find('.ff-status-filter').val(targetStatus);
        syncActiveCard(targetStatus);
        currentPage = 1;
        loadEntries();
    });

    container.on('click', '.ff-btn-search', function () {
        currentPage = 1;
        loadEntries();
    });

    container.on('change', '.ff-status-filter', function () {
        syncActiveCard($(this).val());
        currentPage = 1;
        loadEntries();
    });

    container.on('change', '.ff-form-filter', function () {
        currentPage = 1;
        loadEntries();
    });

    container.on('click', '.ff-btn-refresh, .ff-alert-btn-refresh', function () {
        const icon = $(this).find('.dashicons-update');
        icon.addClass('rotating');
        loadEntries();
        setTimeout(() => icon.removeClass('rotating'), 700);
    });

    container.on('click', '.ff-btn-export', exportToCsv);

    container.on('click', '.ff-page-btn', function () {
        currentPage = parseInt($(this).data('page'));
        loadEntries();
    });

    // View detail click
    container.on('click', '.ff-btn-detail', function () {
        const id = $(this).data('id');
        openDetailModal(id);
    });

    // Quick toggle status from row
    container.on('click', '.ff-btn-toggle-row-status', function () {
        const id = $(this).data('id');
        const currentStatus = $(this).data('status');
        const newStatus = (currentStatus === 'unread') ? 'read' : 'unread';
        const btn = $(this);
        const entry = currentEntries.find(e => String(e.id) === String(id));
        
        updateEntryStatus(id, newStatus, function () {
            if (entry) entry.status = newStatus;
            const row = container.find(`tr[data-id="${id}"]`);
            if (newStatus === 'read') {
                row.removeClass('ff-row-unread');
                btn.find('.ff-status-badge')
                    .removeClass('ff-status-unread')
                    .addClass('ff-status-read')
                    .text('Đã đọc');
                btn.data('status', 'read');
            } else {
                row.addClass('ff-row-unread');
                btn.find('.ff-status-badge')
                    .removeClass('ff-status-read')
                    .addClass('ff-status-unread')
                    .text('Chưa đọc');
                btn.data('status', 'unread');
            }
        });
    });

    // Modal Events
    modal.on('click', '.ff-modal-close, .ff-modal-overlay, .ff-btn-modal-close', function () {
        modal.fadeOut(150);
        activeModalEntry = null;
    });

    $('#ff-btn-toggle-status').on('click', function () {
        if (!activeModalEntry) return;
        const newStatus = (activeModalEntry.status === 'unread') ? 'read' : 'unread';
        updateEntryStatus(activeModalEntry.id, newStatus, function (status) {
            activeModalEntry.status = status;
            updateModalStatusBtn(status);
            const row = container.find(`tr[data-id="${activeModalEntry.id}"]`);
            const btn = row.find('.ff-btn-toggle-row-status');
            if (status === 'read') {
                row.removeClass('ff-row-unread');
                btn.find('.ff-status-badge')
                    .removeClass('ff-status-unread')
                    .addClass('ff-status-read')
                    .text('Đã đọc');
                btn.data('status', 'read');
            } else {
                row.addClass('ff-row-unread');
                btn.find('.ff-status-badge')
                    .removeClass('ff-status-read')
                    .addClass('ff-status-unread')
                    .text('Chưa đọc');
                btn.data('status', 'unread');
            }
        });
    });

    $('#ff-btn-copy-info').on('click', function () {
        if (!activeModalEntry) return;
        const lead = extractLeadData(activeModalEntry.response);
        let text = `[ALLSHIP LEAD #${activeModalEntry.id}]\n`;
        text += `Form: ${activeModalEntry.form_title || activeModalEntry.form_id}\n`;
        if (lead.name) text += `Họ tên: ${lead.name}\n`;
        if (lead.company) text += `Công ty: ${lead.company}\n`;
        if (lead.phone) text += `SĐT: ${lead.phone}\n`;
        if (lead.email) text += `Email: ${lead.email}\n`;
        if (lead.service) text += `Dịch vụ: ${lead.service}\n`;
        if (lead.message) text += `Nội dung: ${lead.message}\n`;
        text += `Thời gian: ${activeModalEntry.created_at}\n`;

        navigator.clipboard.writeText(text).then(() => {
            const btn = $(this);
            const orig = btn.html();
            btn.html('<span class="dashicons dashicons-yes"></span> Đã Copy!');
            setTimeout(() => btn.html(orig), 1600);
        });
    });

    // Initial load
    syncActiveCard(container.find('.ff-status-filter').val() || '');
    loadEntries();
});
