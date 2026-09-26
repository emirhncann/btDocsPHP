(function () {
  "use strict";

  if (!AdminLayout.init()) return;

  var resultsBody = document.getElementById("results-tbody");
  var flash = document.getElementById("flash");
  var searchBtn = document.getElementById("search-btn");
  var firmaInput = document.getElementById("firma_no");
  var donemInput = document.getElementById("donem_no");
  var advancedFilters = document.getElementById("advanced-filters");
  var firmaDonemHint = document.getElementById("firma-donem-hint");
  var cariSearch = document.getElementById("cari-search");
  var cariSuggestions = document.getElementById("cari-suggestions");
  var selectedCariBox = document.getElementById("selected-cari");
  var selectedCariTitle = document.getElementById("selected-cari-title");
  var selectedCariMeta = document.getElementById("selected-cari-meta");
  var tarihBaslangic = document.getElementById("tarih_baslangic");
  var tarihBitis = document.getElementById("tarih_bitis");
  var dateQuickButtons = document.querySelectorAll("[data-date-quick]");

  var currentUid = null;
  var currentMatchContext = null;
  var resultsCache = [];
  var selectedCari = null;
  var cariTimer = null;
  var pendingUploadFiles = [];
  var activeDateQuick = null;

  function showFlash(msg, type) {
    flash.textContent = msg;
    flash.classList.remove("d-none", "alert-danger", "alert-success");
    flash.classList.add(type === "ok" ? "alert-success" : "alert-danger");
  }

  function hideFlash() {
    flash.classList.add("d-none");
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function formatMoney(n) {
    return Number(n || 0).toLocaleString("tr-TR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function toYmd(date) {
    var m = String(date.getMonth() + 1).padStart(2, "0");
    var day = String(date.getDate()).padStart(2, "0");
    return date.getFullYear() + "-" + m + "-" + day;
  }

  function todayYmd() {
    return toYmd(new Date());
  }

  function setDateQuickActive(key) {
    activeDateQuick = key || null;
    dateQuickButtons.forEach(function (btn) {
      var isActive = btn.getAttribute("data-date-quick") === activeDateQuick;
      btn.classList.toggle("active", isActive);
      btn.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function applyDateQuick(key, autoSearch) {
    var today = new Date();
    today.setHours(0, 0, 0, 0);

    if (key === "all" || key === "tumu") {
      tarihBaslangic.value = "";
      tarihBitis.value = "";
      setDateQuickActive("all");
    } else {
      var start = new Date(today);
      var end = new Date(today);

      if (key === "yesterday") {
        start.setDate(start.getDate() - 1);
        end.setDate(end.getDate() - 1);
      } else {
        key = "today";
      }

      tarihBaslangic.value = toYmd(start);
      tarihBitis.value = toYmd(end);
      setDateQuickActive(key);
    }

    if (autoSearch !== false && firmaInput.value && donemInput.value) {
      runDocumentSearch();
    }
  }

  function updateFirmaDonemHint() {
    var firma = firmaInput.value.trim();
    var donem = donemInput.value.trim();
    firmaDonemHint.textContent =
      firma || donem
        ? "· Firma " + (firma || "—") + " / Dönem " + (donem || "—")
        : "· firma/dönem tanımlı değil";
  }

  function updateSearchEnabled() {
    searchBtn.disabled = !(firmaInput.value && donemInput.value);
  }

  async function applyUserDefaults() {
    applyDateQuick("today", false);

    try {
      var me = await Api.get("/api/me");
      Api.setUser(me);
      if (me.default_firma_no != null) firmaInput.value = String(me.default_firma_no);
      if (me.default_donem_no != null) donemInput.value = String(me.default_donem_no);
      if (me.default_firma_no == null || me.default_donem_no == null) {
        advancedFilters.open = true;
      }
    } catch (err) {
      advancedFilters.open = true;
      showFlash((err && err.message) || "Kullanıcı varsayılanları alınamadı", "error");
    }

    var belgeTipiSelect = document.getElementById("belge_tipi");
    try {
      var settings = await Api.get("/api/settings");
      var defaultTip = String((settings && settings.default_belge_tipi) || "tumu");
      if (belgeTipiSelect && ["tumu", "siparis", "irsaliye", "fatura"].indexOf(defaultTip) !== -1) {
        belgeTipiSelect.value = defaultTip;
      }
    } catch (_e) {
      /* ayar okunamazsa HTML varsayılanı (Tümü) kalır */
    }

    updateFirmaDonemHint();
    updateSearchEnabled();
    if (firmaInput.value && donemInput.value) {
      runDocumentSearch();
    } else {
      resultsBody.innerHTML =
        '<tr><td colspan="6" class="text-secondary">Firma/dönem tanımlayın (Gizli filtreler), sonra arayın.</td></tr>';
    }
  }

  function hideSuggestions() {
    cariSuggestions.classList.remove("show");
    cariSuggestions.innerHTML = "";
  }

  function renderCariSuggestions(rows) {
    if (!rows.length) {
      cariSuggestions.innerHTML =
        '<div class="cari-suggestion-empty text-secondary">Sonuç yok</div>';
      cariSuggestions.classList.add("show");
      return;
    }

    cariSuggestions.innerHTML = rows
      .map(function (c, idx) {
        return (
          '<button type="button" class="cari-suggestion-item" data-idx="' +
          idx +
          '">' +
          "<div class='fw-bold'>" +
          escapeHtml(c.cari_kod) +
          " — " +
          escapeHtml(c.cari_unvan || "") +
          "</div>" +
          '<div class="text-secondary small">' +
          (c.vkn ? "VKN/TCKN: " + escapeHtml(c.vkn) : "VKN yok") +
          (c.adres ? " · " + escapeHtml(c.adres) : "") +
          "</div>" +
          "</button>"
        );
      })
      .join("");
    cariSuggestions.classList.add("show");
    cariSuggestions._rows = rows;
  }

  async function searchCari(q) {
    q = String(q || "").trim();
    if (!firmaInput.value || !donemInput.value) {
      advancedFilters.open = true;
      showFlash("Firma/dönem gerekli (Gizli filtreler)", "error");
      return;
    }

    try {
      var rows = await Api.get("/api/cari/search", {
        firma_no: firmaInput.value,
        donem_no: donemInput.value,
        q: q,
      });
      renderCariSuggestions(rows || []);
    } catch (err) {
      hideSuggestions();
      showFlash(err.message || "Cari arama başarısız", "error");
    }
  }

  function selectCari(cari) {
    selectedCari = cari;
    selectedCariTitle.textContent = cari.cari_kod + " — " + (cari.cari_unvan || "");
    selectedCariMeta.textContent =
      (cari.vkn ? "VKN/TCKN: " + cari.vkn : "VKN yok") +
      (cari.adres ? " · " + cari.adres : "");
    selectedCariBox.classList.remove("d-none");
    cariSearch.value = cari.cari_kod + " — " + (cari.cari_unvan || "");
    hideSuggestions();
    updateSearchEnabled();
    if (firmaInput.value && donemInput.value) {
      runDocumentSearch();
    }
  }

  function clearCari() {
    selectedCari = null;
    selectedCariBox.classList.add("d-none");
    cariSearch.value = "";
    updateSearchEnabled();
    if (firmaInput.value && donemInput.value) {
      runDocumentSearch();
    } else {
      resultsBody.innerHTML =
        '<tr><td colspan="6" class="text-secondary">Firma/dönem tanımlayın (Gizli filtreler), sonra arayın.</td></tr>';
    }
  }

  cariSearch.addEventListener("input", function () {
    var q = cariSearch.value.trim();
    if (selectedCari && q !== selectedCari.cari_kod + " — " + (selectedCari.cari_unvan || "")) {
      selectedCari = null;
      selectedCariBox.classList.add("d-none");
      updateSearchEnabled();
    }
    window.clearTimeout(cariTimer);
    if (q.length < 2) {
      hideSuggestions();
      return;
    }
    cariTimer = window.setTimeout(function () {
      searchCari(q);
    }, 300);
  });

  cariSuggestions.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-idx]");
    if (!btn) return;
    var rows = cariSuggestions._rows || [];
    var item = rows[Number(btn.getAttribute("data-idx"))];
    if (item) selectCari(item);
  });

  document.addEventListener("click", function (e) {
    if (!e.target.closest(".cari-typeahead")) hideSuggestions();
  });

  document.getElementById("btn-clear-cari").addEventListener("click", clearCari);
  firmaInput.addEventListener("input", function () {
    updateFirmaDonemHint();
    updateSearchEnabled();
  });
  donemInput.addEventListener("input", function () {
    updateFirmaDonemHint();
    updateSearchEnabled();
  });

  dateQuickButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      applyDateQuick(btn.getAttribute("data-date-quick"), true);
    });
  });

  function onManualDateChange() {
    setDateQuickActive(null);
  }
  tarihBaslangic.addEventListener("input", onManualDateChange);
  tarihBitis.addEventListener("input", onManualDateChange);

  async function runDocumentSearch() {
    hideFlash();
    if (!firmaInput.value || !donemInput.value) {
      advancedFilters.open = true;
      showFlash("Firma No ve Dönem No gerekli (Gizli filtreler)", "error");
      return;
    }

    searchBtn.setAttribute("aria-busy", "true");
    searchBtn.disabled = true;

    var belgeTipi = document.getElementById("belge_tipi").value;
    var baseQuery = {
      firma_no: firmaInput.value,
      donem_no: donemInput.value,
      belge_no: document.getElementById("belge_no").value.trim(),
      tarih_baslangic: tarihBaslangic.value,
      tarih_bitis: tarihBitis.value,
    };
    if (selectedCari && selectedCari.cari_kod) {
      baseQuery.cari_kod = selectedCari.cari_kod;
    }

    try {
      // "Tümü" → belge_tipi gönderme (backend tüm tipleri birleştirir)
      if (belgeTipi === "tumu" || belgeTipi === "") {
        resultsCache = await Api.get("/api/documents/search", baseQuery);
      } else {
        resultsCache = await Api.get(
          "/api/documents/search",
          Object.assign({}, baseQuery, { belge_tipi: belgeTipi })
        );
      }
      renderResults(resultsCache || []);
    } catch (err) {
      resultsBody.innerHTML = '<tr><td colspan="6">Arama başarısız</td></tr>';
      showFlash(err.message || "Arama başarısız", "error");
    } finally {
      searchBtn.removeAttribute("aria-busy");
      updateSearchEnabled();
    }
  }

  searchBtn.addEventListener("click", runDocumentSearch);

  function belgeTipiLabel(tip) {
    var t = String(tip || "").toLowerCase();
    if (t === "fatura") return "Fatura";
    if (t === "irsaliye") return "İrsaliye";
    if (t === "siparis") return "Sipariş";
    if (t === "tumu") return "Tümü";
    return tip || "—";
  }

  function formatFisBelgeNo(row) {
    var fis = String((row && (row.fis_no || row.belge_no)) || "").trim();
    var doc = String((row && row.doc_no) || "").trim();
    if (fis && doc && fis !== doc) {
      return fis + " / " + doc;
    }
    return fis || doc || "—";
  }

  function signedIconHtml(imzali) {
    if (imzali) {
      return (
        '<span class="text-success" title="İmzalı" aria-label="İmzalı">' +
        '<i class="ti ti-circle-check" aria-hidden="true"></i>' +
        "</span>"
      );
    }
    return (
      '<span class="text-danger" title="İmzasız" aria-label="İmzasız">' +
      '<i class="ti ti-circle-x" aria-hidden="true"></i>' +
      "</span>"
    );
  }

  function isSignedFlag(value) {
    return value === true || value === 1 || value === "1";
  }

  function renderResults(rows) {
    if (!rows.length) {
      resultsBody.innerHTML =
        '<tr><td colspan="6" class="text-secondary">Sonuç bulunamadı</td></tr>';
      return;
    }

    resultsBody.innerHTML = rows
      .map(function (r, idx) {
        return (
          "<tr class='clickable-row' data-idx='" +
          idx +
          "'>" +
          "<td class='text-nowrap'>" +
          signedIconHtml(isSignedFlag(r.imzali)) +
          "</td>" +
          "<td>" +
          escapeHtml(formatFisBelgeNo(r)) +
          "</td>" +
          "<td>" +
          escapeHtml(belgeTipiLabel(r.belge_tipi)) +
          "</td>" +
          "<td>" +
          escapeHtml(r.cari_kod) +
          "<br><small class='text-secondary'>" +
          escapeHtml(r.cari_unvan || "") +
          "</small></td>" +
          "<td>" +
          escapeHtml(r.tarih || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(formatMoney(r.tutar)) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  resultsBody.addEventListener("click", async function (e) {
    var row = e.target.closest("tr[data-idx]");
    if (!row) return;
    var item = resultsCache[Number(row.getAttribute("data-idx"))];
    if (!item) return;
    await openDetailModal(item);
  });

  function detailBodyHtml(item) {
    return (
      '<div class="mb-3">' +
      '<div class="text-secondary" id="detail-sub">Eşleştiriliyor…</div>' +
      "</div>" +
      '<div class="mb-3">' +
      "<h4 class='mb-2'>Özet</h4>" +
      '<div class="datagrid">' +
      '<div class="datagrid-item"><div class="datagrid-title">Fiş No</div><div class="datagrid-content">' +
      escapeHtml(item.fis_no || item.belge_no || "—") +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Belge No</div><div class="datagrid-content">' +
      escapeHtml(item.doc_no || "—") +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Tip</div><div class="datagrid-content">' +
      escapeHtml(belgeTipiLabel(item.belge_tipi)) +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Tarih</div><div class="datagrid-content">' +
      escapeHtml(item.tarih || "—") +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Tutar</div><div class="datagrid-content" id="detail-tutar">' +
      escapeHtml(formatMoney(item.tutar)) +
      "</div></div>" +
      '<div class="datagrid-item"><div class="datagrid-title">Cari</div><div class="datagrid-content">' +
      escapeHtml(item.cari_kod || "") +
      " — " +
      escapeHtml(item.cari_unvan || "") +
      "</div></div>" +
      "</div></div>" +
      '<div class="mb-3" id="kalemler-section">' +
      "<h4 class='mb-2'>Kalemler</h4>" +
      '<div class="table-responsive">' +
      '<table class="table table-sm table-vcenter">' +
      "<thead><tr>" +
      "<th>Ürün</th><th class='text-end'>Miktar</th><th class='text-end'>Birim Fiyat</th>" +
      "<th class='text-end'>İskonto</th><th class='text-end'>Tutar</th>" +
      "</tr></thead>" +
      '<tbody id="kalemler-body"><tr><td colspan="5" class="text-secondary">Yükleniyor…</td></tr></tbody>' +
      "</table></div>" +
      '<div class="text-secondary small mt-1" id="kalemler-totals"></div>' +
      "</div>" +
      '<div class="mb-3">' +
      "<h4 class='mb-2'>Not</h4>" +
      '<textarea class="form-control" id="doc-notes" rows="3" placeholder="Belge notu..."></textarea>' +
      '<div class="d-flex align-items-center gap-2 mt-2">' +
      '<button type="button" class="btn btn-sm btn-primary" id="btn-save-notes" data-write-only>Notu Kaydet</button>' +
      '<span id="notes-saved" class="text-success small d-none">Kaydedildi</span>' +
      "</div></div>" +
      '<div class="mb-3">' +
      "<h4 class='mb-2'>Yüklü dosyalar</h4>" +
      '<div id="files-list" class="doc-files"></div>' +
      "</div>" +
      '<div id="upload-section" data-upload-only>' +
      "<hr />" +
      "<h4 class='mb-2'>Dosya Ekle</h4>" +
      '<p class="text-secondary mb-2">JPG/PNG fotoğraf, PDF evrak olarak otomatik kaydedilir. Birden fazla dosya seçebilirsiniz. İmzalı belge Android üzerinden yüklenir.</p>' +
      '<form id="upload-form">' +
      '<input type="file" class="d-none" id="upload-file" accept="image/jpeg,image/png,image/jpg,application/pdf" multiple />' +
      '<div id="dropzone" class="dropzone">Dosyaları buraya sürükleyin veya tıklayarak seçin</div>' +
      '<div id="upload-preview" class="upload-preview mt-3 d-none"></div>' +
      '<button type="submit" class="btn btn-primary mt-3" id="upload-btn" disabled>Yükle</button>' +
      "</form>" +
      "</div>"
    );
  }

  function filePath(uid, fileId) {
    return (
      "/api/documents/" +
      encodeURIComponent(uid) +
      "/files/" +
      encodeURIComponent(fileId)
    );
  }

  function uploaderLabel(file) {
    var who =
      file.uploaded_by_name && String(file.uploaded_by_name).trim()
        ? String(file.uploaded_by_name).trim()
        : file.uploaded_by != null && file.uploaded_by !== ""
          ? "Kullanıcı #" + file.uploaded_by
          : "Bilinmeyen kullanıcı";
    var when = file.uploaded_at || "—";
    return who + " · " + when;
  }

  function buildFileCardHtml(file, opts) {
    opts = opts || {};
    var type = file.file_type;
    var label = AdminUtils.getFileTypeLabel(type);
    var icon = AdminUtils.getFileTypeIcon(type);
    var badge = AdminUtils.getFileTypeBadgeClass(type);
    var isSigned = type === "signed_document";
    var isPhoto = type === "photo";
    var clickable = true;
    var versionBadge =
      isSigned && file.version != null
        ? '<span class="badge bg-secondary-lt">v' +
          escapeHtml(String(file.version)) +
          "</span>"
        : "";
    var currentBadge =
      isSigned && file.is_current
        ? '<span class="badge bg-green-lt">Güncel</span>'
        : "";

    var thumbInner = isPhoto
      ? '<img alt="" data-thumb-file-id="' +
        Number(file.id) +
        '" src="" hidden />' +
        '<i class="' +
        icon +
        '" data-thumb-fallback></i>'
      : '<i class="' + icon + '"></i>';

    return (
      '<div class="doc-file-card' +
      (clickable ? " is-clickable" : "") +
      '" role="button" tabindex="0" data-file-id="' +
      Number(file.id) +
      '" data-file-type="' +
      escapeHtml(type) +
      '" data-is-current="' +
      (file.is_current ? "1" : "0") +
      '" data-viewer-url="' +
      escapeHtml(file.viewer_url || "") +
      '"' +
      (opts.muted ? ' style="opacity:0.85"' : "") +
      ">" +
      '<div class="doc-file-thumb">' +
      thumbInner +
      "</div>" +
      '<div class="doc-file-meta">' +
      '<div class="title-row">' +
      '<span class="badge ' +
      badge +
      '">' +
      escapeHtml(label) +
      "</span>" +
      versionBadge +
      currentBadge +
      "</div>" +
      '<div class="file-name">' +
      escapeHtml(file.original_name || label) +
      "</div>" +
      '<div class="sub">' +
      escapeHtml(uploaderLabel(file)) +
      "</div>" +
      "</div>" +
      (AdminLayout.canDelete()
        ? '<button type="button" class="btn btn-ghost-danger btn-icon doc-file-delete" title="Sil" aria-label="Sil" data-file-delete="' +
          Number(file.id) +
          '"><i class="ti ti-trash"></i></button>'
        : "") +
      "</div>"
    );
  }

  async function openDetailModal(item) {
    hideFlash();
    currentUid = null;
    pendingUploadFiles = [];

    currentMatchContext = {
      firma_no: Number(firmaInput.value),
      donem_no: Number(donemInput.value),
      logo_logicalref: Number(item.logo_logicalref),
      belge_tipi: item.belge_tipi,
      cari_kod: item.cari_kod,
      belge_no: item.belge_no,
    };

    var handle = AdminModal.open({
      title: "Belge " + formatFisBelgeNo(item),
      size: "lg",
      bodyHtml: detailBodyHtml(item),
      footerHtml:
        '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>',
      onHidden: function () {
        if (firmaInput.value && donemInput.value) {
          runDocumentSearch();
        }
      },
    });

    var filesList = handle.body.querySelector("#files-list");
    var dropzone = handle.body.querySelector("#dropzone");
    var uploadInput = handle.body.querySelector("#upload-file");
    var uploadForm = handle.body.querySelector("#upload-form");
    var detailSub = handle.body.querySelector("#detail-sub");
    var uploadSection = handle.body.querySelector("#upload-section");

    if (uploadSection && !AdminLayout.canUpload()) {
      uploadSection.classList.add("d-none");
    }

    try {
      var resolved;
      if (AdminLayout.canMatch()) {
        resolved = await Api.post("/api/documents/match", {
          firma_no: currentMatchContext.firma_no,
          donem_no: currentMatchContext.donem_no,
          logo_logicalref: currentMatchContext.logo_logicalref,
          belge_tipi: currentMatchContext.belge_tipi,
          cari_kod: currentMatchContext.cari_kod,
        });
      } else {
        resolved = await Api.get("/api/documents/lookup", {
          firma_no: currentMatchContext.firma_no,
          donem_no: currentMatchContext.donem_no,
          logo_logicalref: currentMatchContext.logo_logicalref,
          belge_tipi: currentMatchContext.belge_tipi,
        });
      }
      currentUid = resolved.uid;
      detailSub.textContent =
        "UID: " + currentUid + " · " + (item.cari_unvan || item.cari_kod || "");
      await refreshDocumentFiles(filesList);
      await loadAndBindNotes(handle.body);
      await loadDocumentKalemler(handle.body);
    } catch (err) {
      detailSub.textContent =
        err && err.status === 404
          ? "Belge henüz sisteme alınmamış"
          : "Açılamadı";
      filesList.innerHTML = "";
      var kalemBody = handle.body.querySelector("#kalemler-body");
      if (kalemBody) {
        kalemBody.innerHTML =
          '<tr><td colspan="5" class="text-secondary">Kalemler yüklenemedi</td></tr>';
      }
      showFlash(err.message || "Detay açılamadı", "error");
    }

    if (AdminLayout.canUpload() && dropzone && uploadInput && uploadForm) {
      bindUploadUi(dropzone, uploadInput, uploadForm, filesList);
    }
  }

  async function loadDocumentKalemler(root) {
    var body = root.querySelector("#kalemler-body");
    var totalsEl = root.querySelector("#kalemler-totals");
    var tutarEl = root.querySelector("#detail-tutar");
    if (!body || !currentUid) return;

    try {
      var detail = await Api.get(
        "/api/documents/" + encodeURIComponent(currentUid) + "/detail"
      );
      var rows = detail.kalemler || [];
      if (!rows.length) {
        body.innerHTML =
          '<tr><td colspan="5" class="text-secondary">Kalem yok</td></tr>';
      } else {
        body.innerHTML = rows
          .map(function (k) {
            var name = k.urun_adi && String(k.urun_adi).trim() ? k.urun_adi : "—";
            return (
              "<tr>" +
              "<td>" +
              escapeHtml(name) +
              "</td>" +
              '<td class="text-end">' +
              escapeHtml(formatQty(k.miktar)) +
              "</td>" +
              '<td class="text-end">' +
              escapeHtml(formatMoney(k.birim_fiyat)) +
              "</td>" +
              '<td class="text-end">' +
              escapeHtml(formatMoney(k.iskonto_tutari || 0)) +
              "</td>" +
              '<td class="text-end">' +
              escapeHtml(formatMoney(k.tutar)) +
              "</td>" +
              "</tr>"
            );
          })
          .join("");
      }

      if (tutarEl && detail.toplam_tutar != null) {
        tutarEl.textContent = formatMoney(detail.toplam_tutar);
      }
      if (totalsEl) {
        var parts = ["Ara toplam: " + formatMoney(detail.toplam_tutar || 0)];
        if (detail.kdv_tutari != null) {
          parts.push("KDV: " + formatMoney(detail.kdv_tutari));
        }
        if (detail.genel_toplam != null) {
          parts.push("Genel: " + formatMoney(detail.genel_toplam));
        }
        totalsEl.textContent = parts.join(" · ");
      }
    } catch (err) {
      body.innerHTML =
        '<tr><td colspan="5" class="text-secondary">Kalemler yüklenemedi</td></tr>';
    }
  }

  function formatQty(n) {
    var x = Number(n);
    if (!isFinite(x)) return "—";
    return x.toLocaleString("tr-TR", {
      maximumFractionDigits: 3,
    });
  }

  async function refreshDocumentFiles(filesList) {
    if (!currentUid) return;

    var doc = await Api.get("/api/documents/" + encodeURIComponent(currentUid), {
      all_versions: 1,
    });

    var files = doc.files || [];
    if (!files.length) {
      filesList.innerHTML =
        '<div class="doc-files-empty">Henüz dosya yok</div>';
      return;
    }

    var mainFiles = [];
    var oldSigned = [];
    files.forEach(function (f) {
      if (f.file_type === "signed_document" && !f.is_current) {
        oldSigned.push(f);
      } else {
        mainFiles.push(f);
      }
    });

    var html = mainFiles.map(function (f) {
      return buildFileCardHtml(f);
    }).join("");

    if (oldSigned.length) {
      html +=
        '<details class="mt-1">' +
        '<summary class="text-secondary">Önceki versiyonlar (' +
        oldSigned.length +
        ")</summary>" +
        '<div class="doc-files mt-2">' +
        oldSigned
          .map(function (f) {
            return buildFileCardHtml(f, { muted: true });
          })
          .join("") +
        "</div></details>";
    }

    filesList.innerHTML = html;
    bindFileCardActions(filesList);
    await loadPhotoThumbnails(filesList);
  }

  async function loadAndBindNotes(root) {
    var notesEl = root.querySelector("#doc-notes");
    var saveBtn = root.querySelector("#btn-save-notes");
    var savedEl = root.querySelector("#notes-saved");
    if (!notesEl || !currentUid) return;

    var canEdit = AdminLayout.canEditNotes();
    notesEl.readOnly = !canEdit;
    notesEl.disabled = false;
    if (saveBtn) {
      saveBtn.classList.toggle("d-none", !canEdit);
    }

    try {
      var doc = await Api.get("/api/documents/" + encodeURIComponent(currentUid), {
        all_versions: 1,
      });
      notesEl.value = doc.notes || "";
    } catch (err) {
      notesEl.value = "";
    }

    if (!canEdit || !saveBtn) return;

    saveBtn.addEventListener("click", async function () {
      saveBtn.disabled = true;
      if (savedEl) savedEl.classList.add("d-none");
      try {
        var res = await Api.post(
          "/api/documents/" + encodeURIComponent(currentUid) + "/notes",
          { notes: notesEl.value }
        );
        notesEl.value = res.notes != null ? res.notes : notesEl.value;
        if (savedEl) {
          savedEl.classList.remove("d-none");
          setTimeout(function () {
            savedEl.classList.add("d-none");
          }, 2000);
        }
      } catch (err) {
        showFlash(err.message || "Not kaydedilemedi", "error");
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  function bindFileCardActions(filesList) {
    filesList.querySelectorAll(".doc-file-card").forEach(function (card) {
      function openCard() {
        var viewerUrl = card.getAttribute("data-viewer-url");
        if (!viewerUrl) {
          showFlash("Görüntüleme bağlantısı yok", "error");
          return;
        }
        window.open(viewerUrl, "_blank", "noopener");
      }

      card.addEventListener("click", function (e) {
        if (e.target.closest("[data-file-delete]")) return;
        openCard();
      });
      card.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          openCard();
        }
      });
    });

    filesList.querySelectorAll("[data-file-delete]").forEach(function (btn) {
      btn.addEventListener("click", async function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!currentUid) return;
        var fileId = btn.getAttribute("data-file-delete");
        if (!confirm("Bu dosyayı silmek istediğinize emin misiniz?")) return;
        btn.disabled = true;
        try {
          await Api.post(
            "/api/documents/" +
              encodeURIComponent(currentUid) +
              "/files/" +
              encodeURIComponent(fileId) +
              "/delete",
            {}
          );
          showFlash("Dosya silindi", "ok");
          await refreshDocumentFiles(filesList);
        } catch (err) {
          showFlash(err.message || "Silme başarısız", "error");
          btn.disabled = false;
        }
      });
    });
  }

  async function loadPhotoThumbnails(filesList) {
    var imgs = filesList.querySelectorAll("img[data-thumb-file-id]");
    await Promise.all(
      Array.prototype.map.call(imgs, async function (img) {
        var fileId = img.getAttribute("data-thumb-file-id");
        var fallback = img.parentElement
          ? img.parentElement.querySelector("[data-thumb-fallback]")
          : null;

        function showIcon() {
          img.hidden = true;
          img.removeAttribute("src");
          if (fallback) fallback.hidden = false;
        }

        function showImage() {
          img.hidden = false;
          if (fallback) fallback.hidden = true;
        }

        try {
          var url = await Api.fetchAuthenticatedBlobUrl(
            filePath(currentUid, fileId)
          );
          await new Promise(function (resolve) {
            img.onload = function () {
              showImage();
              resolve();
            };
            img.onerror = function () {
              URL.revokeObjectURL(url);
              showIcon();
              resolve();
            };
            img.src = url;
          });
        } catch (err) {
          showIcon();
        }
      })
    );
  }

  function guessClientFileType(file) {
    var mime = String((file && file.type) || "").toLowerCase();
    if (mime === "image/jpg") mime = "image/jpeg";
    if (mime === "image/jpeg" || mime === "image/png") {
      return "photo";
    }
    if (!mime) {
      var name = String((file && file.name) || "").toLowerCase();
      if (/\.(jpe?g|png)$/.test(name)) return "photo";
    }
    return "attachment";
  }

  function collectSelectedFiles(uploadInput) {
    if (uploadInput.files && uploadInput.files.length) {
      return Array.prototype.slice.call(uploadInput.files);
    }
    return pendingUploadFiles.slice();
  }

  function renderUploadPreview(previewEl, files, uploadBtn) {
    if (!previewEl) return;

    if (!files || !files.length) {
      previewEl.classList.add("d-none");
      previewEl.innerHTML = "";
      if (uploadBtn) uploadBtn.disabled = true;
      return;
    }

    previewEl.classList.remove("d-none");
    previewEl.innerHTML = files
      .map(function (file) {
        var type = guessClientFileType(file);
        var label =
          type === "photo"
            ? "Fotoğraf olarak yüklenecek"
            : "Evrak olarak yüklenecek";
        var badge = AdminUtils.getFileTypeBadgeClass(type);
        return (
          '<div class="upload-preview-item">' +
          '<div class="fw-medium text-truncate">' +
          escapeHtml(file.name || "dosya") +
          "</div>" +
          '<span class="badge ' +
          escapeHtml(badge) +
          '">' +
          escapeHtml(label) +
          "</span>" +
          "</div>"
        );
      })
      .join("");

    if (uploadBtn) uploadBtn.disabled = false;
  }

  function bindUploadUi(dropzone, uploadInput, uploadForm, filesList) {
    var previewEl = uploadForm.querySelector("#upload-preview");
    var uploadBtn = uploadForm.querySelector("#upload-btn");

    function syncFromInput() {
      pendingUploadFiles = [];
      renderUploadPreview(previewEl, collectSelectedFiles(uploadInput), uploadBtn);
    }

    ["dragenter", "dragover"].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add("dragover");
      });
    });
    ["dragleave", "drop"].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove("dragover");
      });
    });

    dropzone.addEventListener("drop", function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;

      pendingUploadFiles = Array.prototype.slice.call(files);
      try {
        var dt = new DataTransfer();
        pendingUploadFiles.forEach(function (f) {
          dt.items.add(f);
        });
        uploadInput.files = dt.files;
        pendingUploadFiles = [];
      } catch (err) {
        // DataTransfer yoksa pending listesini kullan
      }
      renderUploadPreview(previewEl, collectSelectedFiles(uploadInput), uploadBtn);
    });

    uploadInput.addEventListener("change", syncFromInput);

    dropzone.addEventListener("click", function () {
      uploadInput.click();
    });

    uploadForm.addEventListener("submit", async function (e) {
      e.preventDefault();
      if (!currentUid) {
        showFlash("Önce bir belge seçin", "error");
        return;
      }

      var files = collectSelectedFiles(uploadInput);
      if (!files.length) {
        showFlash("Dosya seçin", "error");
        return;
      }

      uploadBtn.disabled = true;
      var ok = 0;
      var fail = 0;
      var lastError = "";

      try {
        for (var i = 0; i < files.length; i++) {
          var fd = new FormData();
          fd.append("file", files[i]);
          try {
            await Api.post(
              "/api/documents/" + encodeURIComponent(currentUid) + "/files",
              fd
            );
            ok++;
          } catch (err) {
            fail++;
            lastError = (err && err.message) || "Yükleme başarısız";
          }
        }

        uploadForm.reset();
        pendingUploadFiles = [];
        renderUploadPreview(previewEl, [], uploadBtn);

        if (ok > 0 && fail === 0) {
          showFlash(ok === 1 ? "Dosya yüklendi" : ok + " dosya yüklendi", "ok");
        } else if (ok > 0 && fail > 0) {
          showFlash(ok + " yüklendi, " + fail + " hata: " + lastError, "error");
        } else {
          showFlash(lastError || "Yükleme başarısız", "error");
        }

        await refreshDocumentFiles(filesList);
      } finally {
        uploadBtn.disabled = collectSelectedFiles(uploadInput).length === 0;
      }
    });
  }

  applyUserDefaults();
})();
