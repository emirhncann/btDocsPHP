(function (window) {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function showToast(msg) {
    var toast = document.getElementById("toast");
    if (!toast) return;
    toast.textContent = msg;
    toast.hidden = false;
    setTimeout(function () {
      toast.hidden = true;
    }, 2200);
  }

  function setLoading(on) {
    var loading = document.getElementById("belge-loading");
    var root = document.getElementById("belge-root");
    if (loading) loading.classList.toggle("d-none", !on);
    if (root && on) root.classList.add("d-none");
  }

  function showError(msg) {
    setLoading(false);
    var err = document.getElementById("belge-error");
    if (!err) return;
    err.textContent = msg || "Yüklenemedi";
    err.classList.remove("d-none");
  }

  function tipLabel(tip) {
    var t = String(tip || "").toLowerCase();
    if (t === "fatura") return "Fatura";
    if (t === "irsaliye") return "İrsaliye";
    if (t === "siparis") return "Sipariş";
    return tip || "—";
  }

  function summaryHtml(doc) {
    return (
      '<div class="card mb-3 d-print-none">' +
      '<div class="card-body">' +
      '<div class="datagrid">' +
      '<div class="datagrid-item"><div class="datagrid-title">Belge No</div><div class="datagrid-content">' +
      esc(doc.belge_no || "—") +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Tip</div><div class="datagrid-content">' +
      esc(tipLabel(doc.belge_tipi)) +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Cari</div><div class="datagrid-content">' +
      esc(doc.cari_kod || "—") +
      "</div></div>" +
      "</div></div></div>"
    );
  }

  function fileCardHtml(file, opts) {
    opts = opts || {};
    var type = file.file_type;
    var label = AdminUtils.getFileTypeLabel(type);
    var badge = AdminUtils.getFileTypeBadgeClass(type);
    var who =
      file.uploaded_by_name && String(file.uploaded_by_name).trim()
        ? String(file.uploaded_by_name).trim()
        : "—";
    var when = file.uploaded_at || "—";
    var href = file.viewer_url || "#";
    var badges =
      '<span class="badge ' +
      esc(badge) +
      '">' +
      esc(label) +
      "</span>";
    if (type === "signed_document" && file.version != null) {
      badges +=
        '<span class="badge bg-secondary-lt">v' +
        esc(String(file.version)) +
        "</span>";
    }
    if (type === "signed_document" && file.is_current) {
      badges += '<span class="badge bg-green-lt">Güncel</span>';
    }

    var thumb;
    if ((type === "photo" || type === "signed_document") && file.raw_url) {
      thumb =
        '<img src="' + esc(file.raw_url) + '" alt="" loading="lazy" />';
    } else {
      thumb = '<i class="' + esc(AdminUtils.getFileTypeIcon(type)) + '"></i>';
    }

    return (
      '<a class="belge-file-card' +
      (opts.muted ? " opacity-75" : "") +
      '" href="' +
      esc(href) +
      '">' +
      '<div class="thumb">' +
      thumb +
      "</div>" +
      "<div>" +
      '<div class="d-flex flex-wrap gap-1 mb-1">' +
      badges +
      "</div>" +
      '<div class="fname">' +
      esc(file.original_name || label) +
      "</div>" +
      '<div class="sub">' +
      esc(who + " · " + when) +
      "</div>" +
      "</div></a>"
    );
  }

  function renderIndex(doc) {
    var files = doc.files || [];
    var main = [];
    var oldSigned = [];
    files.forEach(function (f) {
      if (f.file_type === "signed_document" && !f.is_current) {
        oldSigned.push(f);
      } else {
        main.push(f);
      }
    });

    var html = summaryHtml(doc);
    html += '<h3 class="mb-2 d-print-none">Dosyalar</h3>';
    if (!main.length && !oldSigned.length) {
      html +=
        '<div class="empty card card-body text-secondary d-print-none">Bu belgeye henüz dosya yüklenmemiş.</div>';
    } else {
      html +=
        '<div class="belge-file-list d-print-none">' +
        main.map(function (f) {
          return fileCardHtml(f);
        }).join("") +
        "</div>";
    }

    if (oldSigned.length) {
      html +=
        '<details class="mt-3 d-print-none">' +
        "<summary class='text-secondary'>Önceki versiyonlar (" +
        oldSigned.length +
        ")</summary>" +
        '<div class="belge-file-list mt-2">' +
        oldSigned
          .map(function (f) {
            return fileCardHtml(f, { muted: true });
          })
          .join("") +
        "</div></details>";
    }

    var root = document.getElementById("belge-root");
    root.innerHTML = html;
    root.classList.remove("d-none");
    setLoading(false);

    var title = document.getElementById("belge-page-title");
    if (title) {
      title.textContent = "Belge " + (doc.belge_no || "");
    }
  }

  function guessMime(file) {
    var name = String(file.original_name || "").toLowerCase();
    if (/\.pdf$/.test(name) || file.file_type === "attachment") {
      return "application/pdf";
    }
    if (/\.png$/.test(name)) return "image/png";
    if (/\.jpe?g$/.test(name)) return "image/jpeg";
    if (file.file_type === "photo" || file.file_type === "signed_document") {
      return "image/png";
    }
    return "application/octet-stream";
  }

  function bindViewerToolbar(shareUrl, downloadUrl, pdfPrintUrl) {
    var printBtn = document.getElementById("btn-print");
    if (printBtn) {
      printBtn.addEventListener("click", function () {
        if (printBtn.getAttribute("data-print-mode") === "pdf") {
          var pdfUrl = printBtn.getAttribute("data-print-url");
          if (pdfUrl) window.open(pdfUrl, "_blank", "noopener");
          return;
        }
        window.print();
      });
    }

    var shareBtn = document.getElementById("btn-share");
    if (shareBtn) {
      shareBtn.addEventListener("click", async function () {
        var url = shareUrl || window.location.href;
        try {
          if (navigator.share) {
            await navigator.share({ title: document.title, url: url });
            return;
          }
        } catch (e) {}
        try {
          await navigator.clipboard.writeText(url);
          showToast("Bağlantı kopyalandı");
        } catch (e2) {
          prompt("Bağlantıyı kopyalayın:", url);
        }
      });
    }
  }

  function renderFileViewer(doc, file) {
    var mime = guessMime(file);
    var isImage = mime.indexOf("image/") === 0;
    var isPdf = mime === "application/pdf" || file.file_type === "attachment";
    var rawUrl = file.raw_url || "";
    var downloadUrl = rawUrl
      ? rawUrl + (rawUrl.indexOf("?") >= 0 ? "&" : "?") + "download=1"
      : "#";
    var shareUrl = window.location.href;
    var label = AdminUtils.getFileTypeLabel(file.file_type);
    var name = file.original_name || label;

    var printBtn =
      isPdf && rawUrl
        ? '<button type="button" class="btn" id="btn-print" data-print-mode="pdf" data-print-url="' +
          esc(rawUrl) +
          '">Yazdır (Yeni Sekmede Aç)</button>'
        : '<button type="button" class="btn" id="btn-print" data-print-mode="page">Yazdır</button>';

    var preview;
    if (isImage && rawUrl) {
      preview =
        '<div class="belge-preview print-area"><img src="' +
        esc(rawUrl) +
        '" alt="' +
        esc(name) +
        '" /></div>';
    } else if (isPdf && rawUrl) {
      preview =
        '<div class="belge-preview belge-preview-pdf print-area"><iframe src="' +
        esc(rawUrl) +
        '" title="' +
        esc(name) +
        '"></iframe></div>';
    } else {
      preview =
        '<div class="card card-body text-secondary">Bu dosya türü önizlenemiyor. İndir butonunu kullanın.</div>';
    }

    var html =
      summaryHtml(doc) +
      '<div class="mb-2 d-print-none"><a href="/belge/' +
      encodeURIComponent(doc.uid) +
      '" class="btn btn-link px-0"><i class="ti ti-arrow-left"></i> Tüm dosyalar</a></div>' +
      '<div class="belge-toolbar d-flex flex-wrap gap-2 mb-3 d-print-none">' +
      '<button type="button" class="btn" id="btn-share">Paylaş</button>' +
      printBtn +
      '<a class="btn btn-primary" id="btn-download" href="' +
      esc(downloadUrl) +
      '">İndir</a>' +
      "</div>" +
      '<div class="mb-2 d-print-none"><span class="badge ' +
      esc(AdminUtils.getFileTypeBadgeClass(file.file_type)) +
      '">' +
      esc(label) +
      "</span> " +
      '<span class="fw-bold">' +
      esc(name) +
      "</span></div>" +
      preview;

    var root = document.getElementById("belge-root");
    root.innerHTML = html;
    root.classList.remove("d-none");
    setLoading(false);

    var title = document.getElementById("belge-page-title");
    if (title) title.textContent = name;

    bindViewerToolbar(shareUrl, downloadUrl, isPdf ? rawUrl : null);
  }

  async function loadIndex(uid) {
    var doc = await Api.get("/api/documents/" + encodeURIComponent(uid), {
      all_versions: 1,
    });
    renderIndex(doc);
  }

  async function loadFile(uid, fileId) {
    var doc = await Api.get("/api/documents/" + encodeURIComponent(uid), {
      all_versions: 1,
    });
    var files = doc.files || [];
    var file = null;
    for (var i = 0; i < files.length; i++) {
      if (Number(files[i].id) === Number(fileId)) {
        file = files[i];
        break;
      }
    }
    if (!file) {
      showError("Dosya bulunamadı veya silinmiş.");
      return;
    }
    renderFileViewer(doc, file);
  }

  async function init() {
    if (!window.Api) {
      return;
    }
    if (!Api.getToken()) {
      window.location.href = Api.loginPath();
      return;
    }

    try {
      await Api.get("/api/me");
    } catch (err) {
      // 401 → Api zaten returnUrl ile login'e yönlendirir
      return;
    }

    if (!window.AdminLayout || !AdminLayout.init()) return;
    if (!window.AdminUtils) {
      showError("Yardımcı kütüphane yüklenemedi.");
      return;
    }

    var mode = document.body.getAttribute("data-belge-mode") || "index";
    var uid = document.body.getAttribute("data-belge-uid") || "";
    var fileId = document.body.getAttribute("data-belge-file-id") || "";

    if (!uid) {
      showError("Geçersiz belge.");
      return;
    }

    try {
      if (mode === "file") {
        await loadFile(uid, fileId);
      } else {
        await loadIndex(uid);
      }
    } catch (err) {
      showError((err && err.message) || "Belge yüklenemedi.");
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window);
