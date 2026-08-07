(function () {
  "use strict";

  var flash = document.getElementById("flash");
  var form = document.getElementById("login-form");
  var btn = document.getElementById("login-btn");

  function getReturnUrl() {
    var params = new URLSearchParams(window.location.search || "");
    var raw = params.get("returnUrl");
    if (!raw) return null;
    try {
      var u = new URL(raw, window.location.origin);
      if (u.origin !== window.location.origin) return null;
      var path = (u.pathname || "").toLowerCase();
      if (
        path === "/admin" ||
        path === "/admin/" ||
        path.endsWith("/admin/index.html") ||
        path.endsWith("/index.html")
      ) {
        return null;
      }
      return u.pathname + u.search + u.hash;
    } catch (e) {
      return null;
    }
  }

  function defaultHome(role) {
    return role === "readonly" ? "documents.html" : "dashboard.html";
  }

  function goAfterAuth(user) {
    var ret = getReturnUrl();
    if (ret) {
      window.location.href = ret;
      return;
    }
    var role = user && user.role ? String(user.role) : "";
    window.location.href = defaultHome(role);
  }

  if (Api.getToken()) {
    goAfterAuth(Api.getUser() || {});
  }

  function showError(msg) {
    flash.textContent = msg;
    flash.classList.remove("d-none", "alert-success");
    flash.classList.add("alert-danger");
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    flash.classList.add("d-none");
    btn.setAttribute("aria-busy", "true");
    btn.disabled = true;

    var fd = new FormData(form);
    var username = String(fd.get("username") || "").trim();
    var password = String(fd.get("password") || "");

    try {
      var data = await Api.post("/api/auth/login", {
        username: username,
        password: password,
      });

      if (!data || !data.token) {
        throw new Error("Sunucudan token alınamadı");
      }

      Api.setToken(data.token);
      Api.setUser(data.user || {});
      goAfterAuth(data.user || {});
    } catch (err) {
      var msg = (err && err.message) || "Giriş başarısız";
      if (err && err.status === 401) {
        msg = "Kullanıcı adı veya şifre hatalı";
      } else if (err && err.status === 429) {
        msg = "Çok fazla deneme. Birkaç dakika sonra tekrar deneyin.";
      } else if (/Failed to fetch|NetworkError|fetch/i.test(msg)) {
        msg =
          "API'ye ulaşılamıyor. Adres: http://localhost:8080/admin/ olmalı (dosyayı doğrudan açmayın).";
      }
      showError(msg);
    } finally {
      btn.removeAttribute("aria-busy");
      btn.disabled = false;
    }
  });
})();
