(function (window) {
  "use strict";

  var root = null;
  var dialog = null;
  var headerEl = null;
  var titleEl = null;
  var bodyEl = null;
  var footerEl = null;
  var instance = null;
  var onHidden = null;

  function ensureDom() {
    if (root) return;

    root = document.createElement("div");
    root.className = "modal modal-blur fade";
    root.id = "admin-modal";
    root.tabIndex = -1;
    root.setAttribute("aria-hidden", "true");
    root.innerHTML =
      '<div class="modal-dialog modal-dialog-centered" role="document">' +
      '<div class="modal-content">' +
      '<div class="modal-header" id="admin-modal-header">' +
      '<h5 class="modal-title" id="admin-modal-title"></h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>' +
      "</div>" +
      '<div class="modal-body" id="admin-modal-body"></div>' +
      '<div class="modal-footer" id="admin-modal-footer"></div>' +
      "</div>" +
      "</div>";

    document.body.appendChild(root);
    dialog = root.querySelector(".modal-dialog");
    headerEl = root.querySelector("#admin-modal-header");
    titleEl = root.querySelector("#admin-modal-title");
    bodyEl = root.querySelector("#admin-modal-body");
    footerEl = root.querySelector("#admin-modal-footer");

    root.addEventListener("hidden.bs.modal", function () {
      if (typeof onHidden === "function") {
        var cb = onHidden;
        onHidden = null;
        cb();
      }
    });
  }

  function getBootstrapModal() {
    ensureDom();
    if (!window.bootstrap || !window.bootstrap.Modal) {
      throw new Error("Bootstrap Modal yüklenmedi");
    }
    if (!instance) {
      instance = window.bootstrap.Modal.getOrCreateInstance(root, {
        backdrop: true,
        keyboard: true,
        focus: true,
      });
    }
    return instance;
  }

  function setSize(size) {
    ensureDom();
    dialog.className = "modal-dialog modal-dialog-centered";
    if (size === "sm") dialog.classList.add("modal-sm");
    else if (size === "lg") dialog.classList.add("modal-lg");
    else if (size === "xl") dialog.classList.add("modal-xl");
  }

  function setVariant(variant) {
    ensureDom();
    headerEl.className = "modal-header";
    if (variant === "danger") {
      headerEl.classList.add("bg-danger-lt", "text-danger");
    } else if (variant === "warning") {
      headerEl.classList.add("bg-warning-lt");
    }
  }

  /**
   * @param {object} options
   * @param {string} [options.title]
   * @param {string} [options.bodyHtml]
   * @param {string} [options.footerHtml]
   * @param {'sm'|'lg'|'xl'|''} [options.size]
   * @param {'danger'|'warning'|''} [options.variant]
   * @param {function():void} [options.onHidden]
   */
  function open(options) {
    options = options || {};
    ensureDom();
    setSize(options.size || "lg");
    setVariant(options.variant || "");
    titleEl.textContent = options.title || "";
    bodyEl.innerHTML = options.bodyHtml || "";
    footerEl.innerHTML = options.footerHtml || "";
    footerEl.style.display = options.footerHtml ? "" : "none";
    onHidden = typeof options.onHidden === "function" ? options.onHidden : null;
    getBootstrapModal().show();
    return {
      root: root,
      body: bodyEl,
      footer: footerEl,
      title: titleEl,
      hide: close,
    };
  }

  function close() {
    if (!root) return;
    getBootstrapModal().hide();
  }

  function setBody(html) {
    ensureDom();
    bodyEl.innerHTML = html || "";
  }

  function setFooter(html) {
    ensureDom();
    footerEl.innerHTML = html || "";
    footerEl.style.display = html ? "" : "none";
  }

  function setTitle(text) {
    ensureDom();
    titleEl.textContent = text || "";
  }

  window.AdminModal = {
    open: open,
    close: close,
    setBody: setBody,
    setFooter: setFooter,
    setTitle: setTitle,
    getRoot: function () {
      ensureDom();
      return root;
    },
  };
})(window);
