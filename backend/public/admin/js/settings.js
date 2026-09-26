(function () {
  "use strict";

  if (!AdminLayout.init()) return;

  var form = document.getElementById("settings-form");
  var firmaUnvani = document.getElementById("firma_unvani");
  var firmaAdres = document.getElementById("firma_adres");
  var firmaTelefon = document.getElementById("firma_telefon");
  var firmaVkn = document.getElementById("firma_vkn");
  var firmaEmail = document.getElementById("firma_email");
  var defaultBelgeTipi = document.getElementById("default_belge_tipi");
  var smsEnabled = document.getElementById("sms_enabled");
  var netgsmUsername = document.getElementById("netgsm_username");
  var netgsmPassword = document.getElementById("netgsm_password");
  var netgsmMsgheader = document.getElementById("netgsm_msgheader");
  var smsApiUrl = document.getElementById("sms_api_url");
  var firmaLogoInput = document.getElementById("firma_logo");
  var DEFAULT_SMS_API_URL = "https://btdocs.boluteknoloji.tr/api/sms_gonder.php";
  var logoPreviewWrap = document.getElementById("firma-logo-preview-wrap");
  var logoPreview = document.getElementById("firma-logo-preview");
  var btnUploadLogo = document.getElementById("btn-upload-logo");
  var btnRemoveLogo = document.getElementById("btn-remove-logo");
  var saveBtn = document.getElementById("btn-save-settings");
  var savedEl = document.getElementById("settings-saved");
  var flash = document.getElementById("flash");

  var infraForm = document.getElementById("infra-form");
  var logoHost = document.getElementById("logo_db_host");
  var logoName = document.getElementById("logo_db_name");
  var logoUser = document.getElementById("logo_db_user");
  var logoPass = document.getElementById("logo_db_pass");
  var appHost = document.getElementById("app_db_host");
  var appName = document.getElementById("app_db_name");
  var appUser = document.getElementById("app_db_user");
  var appPass = document.getElementById("app_db_pass");
  var docRoot = document.getElementById("doc_storage_root");
  var publicBaseUrl = document.getElementById("public_base_url");
  var btnTest = document.getElementById("btn-test-infra");
  var btnTestApp = document.getElementById("btn-test-app");
  var btnSaveInfra = document.getElementById("btn-save-infra");
  var btnCheckLicense = document.getElementById("btn-check-license");
  var licenseCheckResult = document.getElementById("license-check-result");
  var licenseStatusSummary = document.getElementById("license-status-summary");
  var infraFlash = document.getElementById("infra-flash");
  var infraTestResult = document.getElementById("infra-test-result");
  var infraAppTestResult = document.getElementById("infra-app-test-result");
  var infraSaved = document.getElementById("infra-saved");
  var infraTestedOk = false;
  var appTestedOk = false;
  var loadedInfra = null;

  function showFlash(msg, type) {
    if (!flash) return;
    flash.textContent = msg;
    flash.classList.remove("d-none", "alert-danger", "alert-success");
    flash.classList.add(type === "ok" ? "alert-success" : "alert-danger");
  }

  function hideFlash() {
    if (flash) flash.classList.add("d-none");
  }

  function showInfraFlash(msg, type) {
    if (!infraFlash) return;
    infraFlash.textContent = msg;
    infraFlash.classList.remove("d-none", "alert-danger", "alert-success", "alert-warning");
    infraFlash.classList.add(
      type === "ok" ? "alert-success" : type === "warn" ? "alert-warning" : "alert-danger"
    );
  }

  function hideInfraFlash() {
    if (infraFlash) infraFlash.classList.add("d-none");
  }

  function showTestResult(ok, msg) {
    if (!infraTestResult) return;
    infraTestResult.textContent = msg;
    infraTestResult.classList.remove("d-none", "alert-danger", "alert-success");
    infraTestResult.classList.add(ok ? "alert-success" : "alert-danger");
  }

  function showAppTestResult(ok, msg) {
    if (!infraAppTestResult) return;
    infraAppTestResult.textContent = msg;
    infraAppTestResult.classList.remove("d-none", "alert-danger", "alert-success");
    infraAppTestResult.classList.add(ok ? "alert-success" : "alert-danger");
  }

  function infraPayload() {
    return {
      logo_db_host: logoHost.value.trim(),
      logo_db_name: logoName.value.trim(),
      logo_db_user: logoUser.value.trim(),
      logo_db_pass: logoPass.value,
      app_db_host: appHost.value.trim(),
      app_db_name: appName.value.trim(),
      app_db_user: appUser.value.trim(),
      app_db_pass: appPass.value,
      doc_storage_root: docRoot.value.trim(),
      public_base_url: publicBaseUrl.value.trim(),
    };
  }

  function normPass(v) {
    var s = String(v == null ? "" : v);
    if (s === "" || s === "********") return "********";
    return s;
  }

  function appDbChanged() {
    if (!loadedInfra) return true;
    var p = infraPayload();
    return (
      p.app_db_host !== String(loadedInfra.app_db_host || "") ||
      p.app_db_name !== String(loadedInfra.app_db_name || "") ||
      p.app_db_user !== String(loadedInfra.app_db_user || "") ||
      normPass(p.app_db_pass) !== "********"
    );
  }

  function fillInfra(data) {
    logoHost.value = data.logo_db_host || "";
    logoName.value = data.logo_db_name || "";
    logoUser.value = data.logo_db_user || "";
    logoPass.value = data.logo_db_pass || "********";
    appHost.value = data.app_db_host || "";
    appName.value = data.app_db_name || "";
    appUser.value = data.app_db_user || "";
    appPass.value = data.app_db_pass || "********";
    docRoot.value = data.doc_storage_root || "";
    if (publicBaseUrl) publicBaseUrl.value = data.public_base_url || "";
    loadedInfra = {
      app_db_host: data.app_db_host || "",
      app_db_name: data.app_db_name || "",
      app_db_user: data.app_db_user || "",
    };
    infraTestedOk = false;
    appTestedOk = false;
  }

  /** PUBLIC_BASE_URL farklı host olsa bile önizlemeyi aynı origin'den aç. */
  function logoPreviewSrc(url) {
    if (!url) return null;
    var s = String(url).trim();
    if (!s) return null;
    var marker = "/assets/company-logo";
    var idx = s.indexOf(marker);
    if (idx >= 0) {
      return s.slice(idx);
    }
    return s;
  }

  function applyLogoPreview(url) {
    var src = logoPreviewSrc(url);
    var hasLogo = !!src;
    if (logoPreviewWrap && logoPreview) {
      if (hasLogo) {
        logoPreview.src = src;
        logoPreviewWrap.classList.remove("d-none");
      } else {
        logoPreview.removeAttribute("src");
        logoPreviewWrap.classList.add("d-none");
      }
    }
    if (btnRemoveLogo) {
      btnRemoveLogo.classList.toggle("d-none", !hasLogo);
    }
  }

  function applySmsFields(data) {
    if (smsEnabled) smsEnabled.checked = !!(data && data.sms_enabled);
    if (netgsmUsername) netgsmUsername.value = (data && data.netgsm_username) || "";
    if (netgsmPassword) {
      netgsmPassword.value =
        data && data.netgsm_password ? data.netgsm_password : "";
    }
    if (netgsmMsgheader) netgsmMsgheader.value = (data && data.netgsm_msgheader) || "";
    if (smsApiUrl) {
      smsApiUrl.value = (data && data.sms_api_url) || DEFAULT_SMS_API_URL;
    }
  }

  async function loadSettings() {
    try {
      var data = await Api.get("/api/settings");
      firmaUnvani.value = data.firma_unvani || "";
      firmaAdres.value = data.firma_adres || "";
      firmaTelefon.value = data.firma_telefon || "";
      if (firmaVkn) firmaVkn.value = data.firma_vkn || "";
      if (firmaEmail) firmaEmail.value = data.firma_email || "";
      if (defaultBelgeTipi) defaultBelgeTipi.value = data.default_belge_tipi || "tumu";
      applyLogoPreview(data.firma_logo_url || null);
      applySmsFields(data);
    } catch (err) {
      showFlash((err && err.message) || "Ayarlar yüklenemedi", "error");
    }
  }

  function showLicenseCheckResult(ok, msg) {
    if (!licenseCheckResult) return;
    licenseCheckResult.textContent = msg;
    licenseCheckResult.classList.remove("d-none", "alert-danger", "alert-success", "alert-warning");
    licenseCheckResult.classList.add(ok ? "alert-success" : "alert-danger");
  }

  function renderLicenseSummary(data) {
    if (!licenseStatusSummary || !data) return;
    var parts = [];
    parts.push(data.is_valid ? "Geçerli" : "Geçersiz / süresi dolmuş");
    if (data.bitis_tarihi) parts.push("bitiş: " + data.bitis_tarihi);
    if (data.kalan_gun != null) parts.push("kalan: " + data.kalan_gun + " gün");
    if (data.last_successful_check_at) {
      parts.push("son başarılı kontrol: " + data.last_successful_check_at);
    } else {
      parts.push("henüz başarılı kontrol yok");
    }
    if (data.writes_allowed === false) {
      parts.push("yazma kilitli");
    }
    licenseStatusSummary.textContent = parts.join(" · ");
  }

  async function loadLicenseStatus() {
    if (!licenseStatusSummary) return;
    try {
      var data = await Api.get("/api/admin/license-status");
      renderLicenseSummary(data || {});
    } catch (err) {
      licenseStatusSummary.textContent =
        (err && err.message) || "Lisans durumu okunamadı";
    }
  }

  async function loadInfra() {
    if (!infraForm) return;
    try {
      var data = await Api.get("/api/admin/settings/infra");
      fillInfra(data || {});
    } catch (err) {
      if (err && err.status === 403) {
        infraForm.closest(".card").classList.add("d-none");
        return;
      }
      showInfraFlash((err && err.message) || "Sistem ayarları yüklenemedi", "error");
    }
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    hideFlash();
    if (savedEl) savedEl.classList.add("d-none");
    saveBtn.disabled = true;
    try {
      var res = await Api.post("/api/admin/settings", {
        firma_unvani: firmaUnvani.value,
        firma_adres: firmaAdres.value,
        firma_telefon: firmaTelefon.value,
        firma_vkn: firmaVkn ? firmaVkn.value : "",
        firma_email: firmaEmail ? firmaEmail.value : "",
        default_belge_tipi: defaultBelgeTipi ? defaultBelgeTipi.value : "tumu",
        sms_enabled: smsEnabled ? !!smsEnabled.checked : false,
        netgsm_username: netgsmUsername ? netgsmUsername.value : "",
        netgsm_password: netgsmPassword ? netgsmPassword.value : "",
        netgsm_msgheader: netgsmMsgheader ? netgsmMsgheader.value : "",
        sms_api_url: smsApiUrl ? smsApiUrl.value : "",
      });
      firmaUnvani.value = res.firma_unvani || "";
      firmaAdres.value = res.firma_adres || "";
      firmaTelefon.value = res.firma_telefon || "";
      if (firmaVkn) firmaVkn.value = res.firma_vkn || "";
      if (firmaEmail) firmaEmail.value = res.firma_email || "";
      if (defaultBelgeTipi) defaultBelgeTipi.value = res.default_belge_tipi || "tumu";
      applyLogoPreview(res.firma_logo_url || null);
      applySmsFields(res);      if (savedEl) {
        savedEl.classList.remove("d-none");
        setTimeout(function () {
          savedEl.classList.add("d-none");
        }, 2500);
      }
      showFlash("Ayarlar güncellendi", "ok");
    } catch (err) {
      showFlash((err && err.message) || "Kayıt başarısız", "error");
    } finally {
      saveBtn.disabled = false;
    }
  });

  if (btnUploadLogo && firmaLogoInput) {
    btnUploadLogo.addEventListener("click", async function () {
      hideFlash();
      if (!firmaLogoInput.files || !firmaLogoInput.files[0]) {
        showFlash("Önce bir logo dosyası seçin", "error");
        return;
      }
      var fd = new FormData();
      fd.append("logo", firmaLogoInput.files[0]);
      btnUploadLogo.disabled = true;
      try {
        var res = await Api.post("/api/admin/settings/logo", fd);
        applyLogoPreview(res.firma_logo_url || null);
        firmaLogoInput.value = "";
        showFlash("Logo yüklendi", "ok");
      } catch (err) {
        showFlash((err && err.message) || "Logo yüklenemedi", "error");
      } finally {
        btnUploadLogo.disabled = false;
      }
    });
  }

  if (btnRemoveLogo) {
    btnRemoveLogo.addEventListener("click", async function () {
      hideFlash();
      if (!window.confirm("Firma logosunu kaldırmak istediğinize emin misiniz?")) {
        return;
      }
      btnRemoveLogo.disabled = true;
      try {
        var res = await Api.post("/api/admin/settings/logo/delete", {});
        applyLogoPreview(res.firma_logo_url || null);
        if (firmaLogoInput) firmaLogoInput.value = "";
        showFlash("Logo kaldırıldı", "ok");
      } catch (err) {
        showFlash((err && err.message) || "Logo kaldırılamadı", "error");
      } finally {
        btnRemoveLogo.disabled = false;
      }
    });
  }

  if (btnCheckLicense) {
    btnCheckLicense.addEventListener("click", async function () {
      hideInfraFlash();
      btnCheckLicense.disabled = true;
      try {
        var res = await Api.post("/api/admin/license/check", {});
        renderLicenseSummary(res || {});
        showLicenseCheckResult(true, (res && res.message) || "Lisans kontrolü tamamlandı");
      } catch (err) {
        var data = err && err.data;
        if (data) {
          renderLicenseSummary(data);
          showLicenseCheckResult(
            false,
            data.message || data.error || (err && err.message) || "Kontrol başarısız"
          );
        } else {
          showLicenseCheckResult(false, (err && err.message) || "Kontrol başarısız");
        }
      } finally {
        btnCheckLicense.disabled = false;
      }
    });
  }

  if (btnTest) {
    btnTest.addEventListener("click", async function () {
      hideInfraFlash();
      btnTest.disabled = true;
      try {
        var res = await Api.post(
          "/api/admin/settings/infra/test-connection",
          infraPayload()
        );
        if (res && res.success) {
          infraTestedOk = true;
          showTestResult(true, "Logo DB bağlantısı başarılı");
        } else {
          infraTestedOk = false;
          showTestResult(false, (res && res.error) || "Bağlantı başarısız");
        }
      } catch (err) {
        infraTestedOk = false;
        showTestResult(false, (err && err.message) || "Test başarısız");
      } finally {
        btnTest.disabled = false;
      }
    });
  }

  if (btnTestApp) {
    btnTestApp.addEventListener("click", async function () {
      hideInfraFlash();
      btnTestApp.disabled = true;
      try {
        var res = await Api.post(
          "/api/admin/settings/infra/test-app-connection",
          infraPayload()
        );
        if (res && res.success) {
          appTestedOk = true;
          showAppTestResult(true, "APP_DB bağlantısı başarılı (Users tablosu doğrulandı)");
        } else {
          appTestedOk = false;
          showAppTestResult(false, (res && res.error) || "Bağlantı başarısız");
        }
      } catch (err) {
        appTestedOk = false;
        showAppTestResult(false, (err && err.message) || "Test başarısız");
      } finally {
        btnTestApp.disabled = false;
      }
    });
  }

  ["logo_db_host", "logo_db_name", "logo_db_user", "logo_db_pass"].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener("input", function () {
        infraTestedOk = false;
      });
    }
  });

  ["app_db_host", "app_db_name", "app_db_user", "app_db_pass"].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener("input", function () {
        appTestedOk = false;
      });
    }
  });

  if (infraForm) {
    infraForm.addEventListener("submit", async function (e) {
      e.preventDefault();
      hideInfraFlash();
      if (infraSaved) infraSaved.classList.add("d-none");

      if (appDbChanged()) {
        var okApp = window.confirm(
          "Uygulama veritabanı bilgilerini değiştirmek üzeresiniz. Sistem önce yeni bağlantıyı test edecek, başarısız olursa hiçbir değişiklik yapılmayacak. Devam edilsin mi?"
        );
        if (!okApp) return;
      } else if (!infraTestedOk) {
        var okLogo = window.confirm(
          "Bağlantıyı test etmeden kaydetmek istediğinize emin misiniz? Yanlış bilgiler girerseniz sisteme erişimi kaybedebilirsiniz."
        );
        if (!okLogo) return;
      }

      btnSaveInfra.disabled = true;
      try {
        var res = await Api.post("/api/admin/settings/infra", infraPayload());
        fillInfra(res || {});
        // Kaydet sonrası her zaman dosyadan yeniden oku (yanıt şekli değişse bile form boşalmasın)
        try {
          var refreshed = await Api.get("/api/admin/settings/infra");
          fillInfra(refreshed || {});
        } catch (_e) {
          /* fillInfra(res) yeterli olabilir */
        }
        var msg =
          (res && res.message) ||
          "Ayarlar güncellendi ve bir sonraki istekten itibaren aktif.";
        showInfraFlash(msg, "ok");
        if (infraSaved) {
          infraSaved.textContent = msg;
          infraSaved.classList.remove("d-none");
        }
        if (infraTestResult) infraTestResult.classList.add("d-none");
        if (infraAppTestResult) infraAppTestResult.classList.add("d-none");
      } catch (err) {
        showInfraFlash((err && err.message) || "Kayıt başarısız", "error");
      } finally {
        btnSaveInfra.disabled = false;
      }
    });
  }

  loadSettings();
  loadInfra();
  loadLicenseStatus();
})();
