(function (window) {
  "use strict";

  var FILE_TYPE_LABELS = {
    photo: "Fotoğraf",
    attachment: "Evrak",
    signed_document: "İmzalı Belge",
  };

  var FILE_TYPE_ICONS = {
    photo: "ti ti-camera",
    attachment: "ti ti-paperclip",
    signed_document: "ti ti-writing-sign",
  };

  var FILE_TYPE_BADGE = {
    photo: "bg-azure-lt",
    attachment: "bg-yellow-lt",
    signed_document: "bg-green-lt",
  };

  function getFileTypeLabel(type) {
    return FILE_TYPE_LABELS[type] || String(type || "Dosya");
  }

  function getFileTypeIcon(type) {
    return FILE_TYPE_ICONS[type] || "ti ti-file";
  }

  function getFileTypeBadgeClass(type) {
    return FILE_TYPE_BADGE[type] || "bg-secondary-lt";
  }

  /**
   * 1024 tabanlı okunabilir boyut (B / KB / MB / GB / TB).
   * @param {number|string|null|undefined} bytes
   * @returns {string}
   */
  function formatFileSize(bytes) {
    var n = Number(bytes);
    if (!isFinite(n) || n < 0) n = 0;
    n = Math.floor(n);

    var units = ["B", "KB", "MB", "GB", "TB"];
    var i = 0;
    var value = n;
    while (value >= 1024 && i < units.length - 1) {
      value = value / 1024;
      i++;
    }

    if (i === 0) {
      return n + " B";
    }

    return (
      value.toLocaleString("tr-TR", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 1,
      }) +
      " " +
      units[i]
    );
  }

  /** Upload select options — value enum kalır, görünen metin Türkçe */
  function fileTypeSelectOptionsHtml(allowedTypes) {
    allowedTypes = allowedTypes || ["photo", "attachment"];
    return allowedTypes
      .map(function (type) {
        return (
          '<option value="' +
          type +
          '">' +
          getFileTypeLabel(type) +
          "</option>"
        );
      })
      .join("");
  }

  window.AdminUtils = {
    getFileTypeLabel: getFileTypeLabel,
    getFileTypeIcon: getFileTypeIcon,
    getFileTypeBadgeClass: getFileTypeBadgeClass,
    formatFileSize: formatFileSize,
    fileTypeSelectOptionsHtml: fileTypeSelectOptionsHtml,
  };
})(window);
