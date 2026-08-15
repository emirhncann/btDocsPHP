(function (window) {
  "use strict";

  function pageKey() {
    var path = (window.location.pathname || "").toLowerCase();
    if (path.indexOf("/belge/") >= 0) return "documents";
    if (path.indexOf("users") >= 0) return "users";
    if (path.indexOf("settings") >= 0) return "settings";
    if (path.indexOf("belgeler") >= 0 || path.indexOf("documents") >= 0) return "documents";
    return "dashboard";
  }

  function currentUser() {
    return (window.Api && Api.getUser()) || {};
  }

  function currentRole() {
    return String(currentUser().role || "");
  }

  function capabilities(role) {
    role = role || currentRole();
    return {
      canUpload: role === "admin" || role === "user",
      canManageUsers: role === "admin",
      canMatch: role === "admin" || role === "user",
      canDelete: role === "admin",
      canEditNotes: role === "admin" || role === "user",
    };
  }

  function canUpload() {
    return capabilities().canUpload;
  }

  function canManageUsers() {
    return capabilities().canManageUsers;
  }

  function canMatch() {
    return capabilities().canMatch;
  }

  function canDelete() {
    return capabilities().canDelete;
  }

  function canEditNotes() {
    return capabilities().canEditNotes;
  }

  function isAdmin() {
    return currentRole() === "admin";
  }

  function setActiveNav() {
    var key = pageKey();
    document.querySelectorAll("[data-nav]").forEach(function (el) {
      if (el.getAttribute("data-nav") === key) {
        el.classList.add("active");
      } else {
        el.classList.remove("active");
      }
    });
  }

  function applyRoleUi(user) {
    var caps = capabilities((user && user.role) || "");

    document.querySelectorAll("[data-admin-only], [data-manage-users]").forEach(function (el) {
      el.classList.toggle("d-none", !caps.canManageUsers);
    });

    document.querySelectorAll("[data-write-only], [data-upload-only]").forEach(function (el) {
      el.classList.toggle("d-none", !caps.canUpload);
    });

    if ((pageKey() === "users" || pageKey() === "settings") && !caps.canManageUsers) {
      var path = (window.location.pathname || "").toLowerCase();
      window.location.href = path.indexOf("/goruntule/") >= 0 ? "belgeler.html" : "documents.html";
      return false;
    }

    return true;
  }

  async function fillUser() {
    var nameEl = document.getElementById("topbar-user-name");
    var roleEl = document.getElementById("topbar-user-role");

    var user = currentUser();
    try {
      var me = await Api.get("/api/me");
      user = me || user;
      Api.setUser(user);
    } catch (err) {
      // token geçersizse Api zaten yönlendirir
    }

    if (nameEl) {
      nameEl.textContent = user.full_name || user.username || "Kullanıcı";
    }
    if (roleEl) {
      roleEl.textContent = user.role || "";
    }

    return applyRoleUi(user);
  }

  function bindLogout() {
    function onLogout(e) {
      e.preventDefault();
      Api.logout();
    }

    document.querySelectorAll("[data-action='logout'], #logout-btn").forEach(function (el) {
      el.addEventListener("click", onLogout);
    });
  }

  var licenseModalShownThisPage = false;

  function formatBitis(value) {
    if (!value) return "";
    var s = String(value).slice(0, 10);
    var parts = s.split("-");
    if (parts.length === 3) {
      return parts[2] + "." + parts[1] + "." + parts[0];
    }
    return s;
  }

  function openLicenseModal(variant, title, bodyText) {
    if (!window.AdminModal || typeof AdminModal.open !== "function") {
      console.warn("AdminModal yok — lisans uyarısı gösterilemedi (modal.js yüklenmeli)");
      return;
    }

    var btnClass = variant === "warning" ? "btn-warning" : "btn-danger";
    AdminModal.open({
      title: title,
      variant: variant,
      size: "sm",
      bodyHtml: "<p class='mb-0'>" + bodyText + "</p>",
      footerHtml:
        '<button type="button" class="btn ' +
        btnClass +
        '" data-bs-dismiss="modal">Anladım</button>',
    });
  }

  async function checkAndShowLicenseModal() {
    if (licenseModalShownThisPage) return;
    if (!window.Api || !Api.getToken || !Api.getToken()) return;

    try {
      var data = await Api.get("/api/admin/license-status");
      if (!data) return;

      if (data.is_valid === false) {
        licenseModalShownThisPage = true;
        openLicenseModal(
          "danger",
          "Lisans Süresi Doldu",
          "⚠ Lisansınızın süresi doldu. Yeni veri girişi (dosya yükleme, imzalama, kullanıcı ekleme vb.) yapılamıyor. Lütfen lisansınızı yenileyin."
        );
        return;
      }

      var days = data.kalan_gun;
      if (data.is_valid === true && days != null && Number(days) <= 15) {
        licenseModalShownThisPage = true;
        openLicenseModal(
          "warning",
          "Lisans Süresi Yaklaşıyor",
          "⚠ Lisansınızın süresi " +
            Number(days) +
            " gün içinde (" +
            formatBitis(data.bitis_tarihi) +
            ") dolacak. Zamanında yenilemeyi unutmayın."
        );
      }
    } catch (_err) {
      /* sessiz — lisans paneli okunamazsa UI’yi kilitleme */
    }
  }

  function init(options) {
    options = options || {};
    if (!window.Api || !Api.requireAuth()) return false;

    setActiveNav();
    bindLogout();
    applyRoleUi(currentUser());
    fillUser();
    checkAndShowLicenseModal();
    return true;
  }

  window.AdminLayout = {
    init: init,
    pageKey: pageKey,
    currentRole: currentRole,
    capabilities: capabilities,
    canUpload: canUpload,
    canManageUsers: canManageUsers,
    canMatch: canMatch,
    canDelete: canDelete,
    canEditNotes: canEditNotes,
    isAdmin: isAdmin,
    checkAndShowLicenseModal: checkAndShowLicenseModal,
    // geriye dönük alias
    canWrite: canUpload,
  };
})(window);
