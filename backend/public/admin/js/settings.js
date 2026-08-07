(function () {
  "use strict";

  if (!AdminLayout.init()) return;

  var form = document.getElementById("settings-form");
  var firmaUnvani = document.getElementById("firma_unvani");
  var firmaAdres = document.getElementById("firma_adres");
  var firmaTelefon = document.getElementById("firma_telefon");
  var saveBtn = document.getElementById("btn-save-settings");
  var savedEl = document.getElementById("settings-saved");
  var flash = document.getElementById("flash");

  function showFlash(msg, type) {
    if (!flash) return;
    flash.textContent = msg;
    flash.classList.remove("d-none", "alert-danger", "alert-success");
    flash.classList.add(type === "ok" ? "alert-success" : "alert-danger");
  }

  function hideFlash() {
    if (flash) flash.classList.add("d-none");
  }

  async function loadSettings() {
    try {
      var data = await Api.get("/api/settings");
      firmaUnvani.value = data.firma_unvani || "";
      firmaAdres.value = data.firma_adres || "";
      firmaTelefon.value = data.firma_telefon || "";
    } catch (err) {
      showFlash((err && err.message) || "Ayarlar yüklenemedi", "error");
    }
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    hideFlash();
    if (savedEl) savedEl.classList.add("d-none");
    saveBtn.disabled = true;
    try {
      var res = await Api.put("/api/admin/settings", {
        firma_unvani: firmaUnvani.value,
        firma_adres: firmaAdres.value,
        firma_telefon: firmaTelefon.value,
      });
      firmaUnvani.value = res.firma_unvani || "";
      firmaAdres.value = res.firma_adres || "";
      firmaTelefon.value = res.firma_telefon || "";
      if (savedEl) {
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

  loadSettings();
})();
