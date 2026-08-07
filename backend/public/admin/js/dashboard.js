(function (window) {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function fmtInt(n) {
    var v = Number(n);
    if (!isFinite(v)) return "0";
    return String(Math.trunc(v));
  }

  function fmtDateTime(value) {
    if (!value) return "—";
    var d = new Date(String(value).replace(" ", "T"));
    if (isNaN(d.getTime())) return esc(value);
    return (
      d.toLocaleDateString("tr-TR") +
      " " +
      d.toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" })
    );
  }

  function setText(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function setLoading(isLoading) {
    var loading = document.getElementById("stats-loading");
    var content = document.getElementById("stats-content");
    var err = document.getElementById("stats-error");
    if (loading) loading.classList.toggle("d-none", !isLoading);
    if (content) content.classList.toggle("d-none", isLoading);
    if (err && isLoading) err.classList.add("d-none");
  }

  function showError(message) {
    var err = document.getElementById("stats-error");
    if (!err) return;
    err.textContent = message || "İstatistikler yüklenemedi.";
    err.classList.remove("d-none");
    var content = document.getElementById("stats-content");
    if (content) content.classList.add("d-none");
    var loading = document.getElementById("stats-loading");
    if (loading) loading.classList.add("d-none");
  }

  function renderActiveUsers(activeUsers) {
    var wrap = document.getElementById("active-users-wrap");
    if (!wrap) return;

    if (activeUsers == null) {
      wrap.classList.add("d-none");
      return;
    }

    wrap.classList.remove("d-none");
    setText("stat-active-users", fmtInt(activeUsers));
  }

  function renderRecent(items) {
    var tbody = document.getElementById("recent-uploads-tbody");
    if (!tbody) return;

    if (!items || !items.length) {
      tbody.innerHTML =
        '<tr><td colspan="5" class="text-secondary text-center py-4">' +
        "Henüz hiç yükleme yok." +
        "</td></tr>";
      return;
    }

    tbody.innerHTML = items
      .map(function (row) {
        var type = row.file_type;
        var label = AdminUtils.getFileTypeLabel(type);
        var badge = AdminUtils.getFileTypeBadgeClass(type);
        return (
          "<tr>" +
          "<td>" +
          esc(fmtDateTime(row.uploaded_at)) +
          "</td>" +
          "<td><span class='badge " +
          esc(badge) +
          "'>" +
          esc(label) +
          "</span></td>" +
          "<td>" +
          esc(row.belge_no || "—") +
          "</td>" +
          "<td>" +
          esc(row.cari_kod || "—") +
          "</td>" +
          "<td>" +
          esc(row.uploaded_by_name || "—") +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function renderStats(data) {
    var today = data.today || {};
    var totals = data.totals || {};

    setText("today-total", fmtInt(today.total));
    setText("today-signed", fmtInt(today.signed_document));
    setText("today-photo", fmtInt(today.photo));
    setText("today-attachment", fmtInt(today.attachment));
    setText(
      "today-size",
      AdminUtils.formatFileSize(today.total_size_bytes || 0)
    );

    setText("total-documents", fmtInt(totals.documents));
    setText("total-signed", fmtInt(totals.signed_document));
    setText("total-photo", fmtInt(totals.photo));
    setText("total-attachment", fmtInt(totals.attachment));
    setText(
      "total-size",
      AdminUtils.formatFileSize(totals.total_size_bytes || 0)
    );

    renderActiveUsers(data.active_users);
    renderRecent(data.recent_uploads || []);
  }

  async function loadStats() {
    setLoading(true);
    try {
      var data = await Api.get("/api/admin/stats");
      renderStats(data || {});
      setLoading(false);
    } catch (err) {
      showError((err && err.message) || "İstatistikler yüklenemedi.");
    }
  }

  function init() {
    if (!window.AdminLayout || !AdminLayout.init()) return;
    if (!window.AdminUtils) {
      showError("Yardımcı kütüphane yüklenemedi.");
      return;
    }

    var user = Api.getUser() || {};
    setText(
      "welcome-text",
      (user.full_name || user.username || "Kullanıcı") + " olarak giriş yaptınız."
    );

    loadStats();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window);
