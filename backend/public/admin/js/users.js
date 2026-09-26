(function () {
  "use strict";

  if (!AdminLayout.init()) return;

  var tbody = document.getElementById("users-tbody");
  var flash = document.getElementById("flash");
  var filterRole = document.getElementById("filter-role");
  var filterActive = document.getElementById("filter-active");
  var usersCache = [];

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

  function findUser(id) {
    return usersCache.find(function (u) {
      return Number(u.id) === Number(id);
    });
  }

  async function loadUsers() {
    hideFlash();
    tbody.innerHTML = '<tr><td colspan="6" class="text-secondary">Yükleniyor…</td></tr>';
    try {
      usersCache = await Api.get("/api/admin/users", {
        role: filterRole.value,
        is_active: filterActive.value,
      });
      renderUsers(usersCache || []);
    } catch (err) {
      tbody.innerHTML = '<tr><td colspan="6">Liste alınamadı</td></tr>';
      showFlash(err.message || "Liste alınamadı", "error");
    }
  }

  function renderUsers(users) {
    if (!users.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-secondary">Kayıt yok</td></tr>';
      return;
    }

    tbody.innerHTML = users
      .map(function (u) {
        var activeBadge = u.is_active
          ? '<span class="badge bg-success-lt">Aktif</span>'
          : '<span class="badge bg-secondary-lt">Pasif</span>';
        var firmaDonem =
          u.default_firma_no != null || u.default_donem_no != null
            ? escapeHtml(String(u.default_firma_no ?? "—")) +
              " / " +
              escapeHtml(String(u.default_donem_no ?? "—"))
            : '<span class="text-secondary">—</span>';

        return (
          "<tr>" +
          "<td>" +
          escapeHtml(u.username) +
          "</td>" +
          "<td>" +
          escapeHtml(u.full_name) +
          "</td>" +
          "<td><span class='badge bg-blue-lt'>" +
          escapeHtml(u.role) +
          "</span></td>" +
          "<td>" +
          firmaDonem +
          "</td>" +
          "<td>" +
          activeBadge +
          "</td>" +
          "<td>" +
          "<div class='btn-list flex-nowrap'>" +
          "<button type='button' class='btn btn-sm btn-outline-primary btn-edit' data-id='" +
          u.id +
          "'>Düzenle</button>" +
          "<button type='button' class='btn btn-sm btn-outline-secondary btn-reset' data-id='" +
          u.id +
          "'>Şifre</button>" +
          (u.is_active
            ? "<button type='button' class='btn btn-sm btn-outline-danger btn-deactivate' data-id='" +
              u.id +
              "'>Pasifleştir</button>"
            : "") +
          "</div></td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function userFormHtml(mode, user) {
    user = user || {};
    var isEdit = mode === "edit";
    return (
      '<form id="user-form" novalidate>' +
      '<input type="hidden" id="user-id" value="' +
      escapeHtml(isEdit ? String(user.id) : "") +
      '" />' +
      '<div class="mb-3' +
      (isEdit ? " d-none" : "") +
      '" id="username-wrap">' +
      '<label class="form-label" for="user-username">Kullanıcı adı</label>' +
      '<input type="text" class="form-control" id="user-username" ' +
      (isEdit ? "" : "required ") +
      'value="' +
      escapeHtml(user.username || "") +
      '" />' +
      "</div>" +
      '<div class="mb-3' +
      (isEdit ? " d-none" : "") +
      '" id="password-wrap">' +
      '<label class="form-label" for="user-password">Şifre</label>' +
      '<input type="password" class="form-control" id="user-password" ' +
      (isEdit ? "" : "required ") +
      "/>" +
      "</div>" +
      '<div class="mb-3">' +
      '<label class="form-label" for="user-full-name">Ad Soyad</label>' +
      '<input type="text" class="form-control" id="user-full-name" required value="' +
      escapeHtml(user.full_name || "") +
      '" />' +
      "</div>" +
      '<div class="mb-3">' +
      '<label class="form-label" for="user-role">Rol</label>' +
      '<select class="form-select" id="user-role" required>' +
      '<option value="admin"' +
      (user.role === "admin" ? " selected" : "") +
      ">admin</option>" +
      '<option value="user"' +
      (!user.role || user.role === "user" ? " selected" : "") +
      ">Kullanıcı</option>" +
      '<option value="readonly"' +
      (user.role === "readonly" ? " selected" : "") +
      ">readonly</option>" +
      "</select>" +
      "</div>" +
      '<div class="row g-2">' +
      '<div class="col-6 mb-3">' +
      '<label class="form-label" for="user-default-firma">Varsayılan Firma No</label>' +
      '<input type="number" class="form-control" id="user-default-firma" min="1" placeholder="opsiyonel" value="' +
      escapeHtml(user.default_firma_no != null ? String(user.default_firma_no) : "") +
      '" />' +
      "</div>" +
      '<div class="col-6 mb-3">' +
      '<label class="form-label" for="user-default-donem">Varsayılan Dönem No</label>' +
      '<input type="number" class="form-control" id="user-default-donem" min="1" placeholder="opsiyonel" value="' +
      escapeHtml(user.default_donem_no != null ? String(user.default_donem_no) : "") +
      '" />' +
      "</div>" +
      "</div>" +
      (isEdit
        ? '<div class="mb-0 form-check">' +
          '<input class="form-check-input" type="checkbox" id="user-is-active"' +
          (user.is_active ? " checked" : "") +
          " />" +
          '<label class="form-check-label" for="user-is-active">Aktif</label>' +
          "</div>"
        : "") +
      '<div id="user-form-error" class="alert alert-danger mt-3 d-none"></div>' +
      "</form>"
    );
  }

  function readDefaults(root) {
    var firmaRaw = root.querySelector("#user-default-firma").value.trim();
    var donemRaw = root.querySelector("#user-default-donem").value.trim();
    return {
      default_firma_no: firmaRaw === "" ? null : Number(firmaRaw),
      default_donem_no: donemRaw === "" ? null : Number(donemRaw),
    };
  }

  function openUserModal(mode, user) {
    var isEdit = mode === "edit";
    var handle = AdminModal.open({
      title: isEdit ? "Kullanıcı Düzenle" : "Yeni Kullanıcı",
      size: "",
      bodyHtml: userFormHtml(mode, user),
      footerHtml:
        '<button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">İptal</button>' +
        '<button type="submit" form="user-form" class="btn btn-primary" id="user-save">Kaydet</button>',
    });

    var form = handle.body.querySelector("#user-form");
    var errBox = handle.body.querySelector("#user-form-error");

    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      errBox.classList.add("d-none");

      var username = form.querySelector("#user-username").value.trim();
      var password = form.querySelector("#user-password").value;
      var fullName = form.querySelector("#user-full-name").value.trim();
      var role = form.querySelector("#user-role").value;
      var defaults = readDefaults(form);

      if (!isEdit) {
        if (!username) {
          errBox.textContent = "Kullanıcı adı zorunlu";
          errBox.classList.remove("d-none");
          return;
        }
        if (!password) {
          errBox.textContent = "Şifre zorunlu";
          errBox.classList.remove("d-none");
          return;
        }
      }
      if (!fullName) {
        errBox.textContent = "Ad soyad zorunlu";
        errBox.classList.remove("d-none");
        return;
      }

      var saveBtn = document.getElementById("user-save");
      if (saveBtn) saveBtn.disabled = true;

      try {
        if (!isEdit) {
          await Api.post("/api/admin/users", {
            username: username,
            password: password,
            full_name: fullName,
            role: role,
            default_firma_no: defaults.default_firma_no,
            default_donem_no: defaults.default_donem_no,
          });
          showFlash("Kullanıcı oluşturuldu", "ok");
        } else {
          await Api.post("/api/admin/users/" + user.id, {
            full_name: fullName,
            role: role,
            is_active: form.querySelector("#user-is-active").checked,
            default_firma_no: defaults.default_firma_no,
            default_donem_no: defaults.default_donem_no,
          });
          showFlash("Kullanıcı güncellendi", "ok");
        }
        AdminModal.close();
        await loadUsers();
      } catch (err) {
        errBox.textContent = err.message || "Kayıt başarısız";
        errBox.classList.remove("d-none");
      } finally {
        if (saveBtn) saveBtn.disabled = false;
      }
    });
  }

  function openResetModal(user) {
    var handle = AdminModal.open({
      title: "Şifre Sıfırla",
      size: "sm",
      bodyHtml:
        '<form id="reset-form">' +
        '<p class="text-secondary">' +
        escapeHtml(user.username) +
        " — yeni şifre belirleyin</p>" +
        '<div class="mb-0">' +
        '<label class="form-label" for="reset-password">Yeni şifre</label>' +
        '<input type="password" class="form-control" id="reset-password" required />' +
        "</div>" +
        '<div id="reset-form-error" class="alert alert-danger mt-3 d-none"></div>' +
        "</form>",
      footerHtml:
        '<button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">İptal</button>' +
        '<button type="submit" form="reset-form" class="btn btn-primary">Sıfırla</button>',
    });

    var form = handle.body.querySelector("#reset-form");
    var errBox = handle.body.querySelector("#reset-form-error");
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      var password = form.querySelector("#reset-password").value;
      if (!password) {
        errBox.textContent = "Şifre zorunlu";
        errBox.classList.remove("d-none");
        return;
      }
      try {
        await Api.post("/api/admin/users/" + user.id + "/reset-password", {
          new_password: password,
        });
        AdminModal.close();
        showFlash("Şifre güncellendi", "ok");
      } catch (err) {
        errBox.textContent = err.message || "Şifre sıfırlama başarısız";
        errBox.classList.remove("d-none");
      }
    });
  }

  document.getElementById("btn-new-user").addEventListener("click", function () {
    openUserModal("create");
  });
  document.getElementById("btn-refresh").addEventListener("click", loadUsers);
  filterRole.addEventListener("change", loadUsers);
  filterActive.addEventListener("change", loadUsers);

  tbody.addEventListener("click", async function (e) {
    var btn = e.target.closest("button");
    if (!btn) return;
    var id = btn.getAttribute("data-id");
    var user = findUser(id);
    if (!user) return;

    if (btn.classList.contains("btn-edit")) {
      openUserModal("edit", user);
      return;
    }
    if (btn.classList.contains("btn-reset")) {
      openResetModal(user);
      return;
    }
    if (btn.classList.contains("btn-deactivate")) {
      if (!confirm("Kullanıcı pasifleştirilsin mi?")) return;
      try {
        await Api.post("/api/admin/users/" + id + "/deactivate", {});
        showFlash("Kullanıcı pasifleştirildi", "ok");
        await loadUsers();
      } catch (err) {
        showFlash(err.message || "İşlem başarısız", "error");
      }
    }
  });

  loadUsers();
})();
