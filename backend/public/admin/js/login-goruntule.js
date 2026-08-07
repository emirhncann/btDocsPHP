(function () {
  "use strict";

  var flash = document.getElementById("flash");
  var form = document.getElementById("login-form");
  var btn = document.getElementById("login-btn");

  Api.redirectIfAuthed();

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
      window.location.href = "belgeler.html";
    } catch (err) {
      var msg = (err && err.message) || "Giriş başarısız";
      if (err && err.status === 401) {
        msg = "Kullanıcı adı veya şifre hatalı";
      } else if (err && err.status === 429) {
        msg = "Çok fazla deneme. Birkaç dakika sonra tekrar deneyin.";
      } else if (/Failed to fetch|NetworkError|fetch/i.test(msg)) {
        msg = "API'ye ulaşılamıyor. Adresi kontrol edin.";
      }
      showError(msg);
    } finally {
      btn.removeAttribute("aria-busy");
      btn.disabled = false;
    }
  });
})();
