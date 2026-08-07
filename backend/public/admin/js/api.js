(function (window) {
  "use strict";

  const cfg = window.APP_CONFIG || { BASE_URL: "", TOKEN_KEY: "btdocs_admin_token", USER_KEY: "btdocs_admin_user" };

  function baseUrl() {
    return (cfg.BASE_URL || "").replace(/\/$/, "");
  }

  function setToken(token) {
    localStorage.setItem(cfg.TOKEN_KEY, token);
  }

  function getToken() {
    return localStorage.getItem(cfg.TOKEN_KEY);
  }

  function clearToken() {
    localStorage.removeItem(cfg.TOKEN_KEY);
    localStorage.removeItem(cfg.USER_KEY);
  }

  function setUser(user) {
    localStorage.setItem(cfg.USER_KEY, JSON.stringify(user || {}));
  }

  function getUser() {
    try {
      return JSON.parse(localStorage.getItem(cfg.USER_KEY) || "null");
    } catch {
      return null;
    }
  }

  function loginPath(returnUrl) {
    var path = "/admin/index.html";
    var currentPath = (window.location.pathname || "").toLowerCase();
    var onLogin =
      currentPath === "/admin" ||
      currentPath === "/admin/" ||
      currentPath.endsWith("/admin/index.html") ||
      currentPath.endsWith("/index.html");

    if (onLogin && returnUrl == null) {
      return path + (window.location.search || "");
    }

    var ret = returnUrl;
    if (ret == null || ret === "") {
      ret = window.location.href;
    }

    return path + "?returnUrl=" + encodeURIComponent(ret);
  }

  function isGoruntuleArea() {
    return (window.location.pathname || "").toLowerCase().indexOf("/goruntule/") >= 0;
  }

  function requireAuth() {
    if (!getToken()) {
      window.location.href = loginPath();
      return false;
    }
    return true;
  }

  function redirectIfAuthed() {
    if (!getToken()) return;
    window.location.href = isGoruntuleArea() ? "belgeler.html" : "dashboard.html";
  }

  async function request(path, options = {}) {
    const headers = Object.assign({}, options.headers || {});
    const token = getToken();
    const isFormData = typeof FormData !== "undefined" && options.body instanceof FormData;

    if (!isFormData && !headers["Content-Type"] && options.body) {
      headers["Content-Type"] = "application/json";
    }
    if (token) {
      headers.Authorization = "Bearer " + token;
    }

    const res = await fetch(baseUrl() + path, Object.assign({}, options, { headers }));

    if (res.status === 401) {
      clearToken();
      window.location.href = loginPath();
      throw new Error("Yetkisiz erişim");
    }

    const text = await res.text();
    let data = null;
    if (text) {
      try {
        data = JSON.parse(text);
      } catch {
        data = text;
      }
    }

    if (!res.ok) {
      const msg =
        (data && data.error) ||
        (typeof data === "string" && data) ||
        "İstek başarısız (" + res.status + ")";
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      throw err;
    }

    return data;
  }

  function get(path, query) {
    let url = path;
    if (query && typeof query === "object") {
      const params = new URLSearchParams();
      Object.keys(query).forEach(function (key) {
        const val = query[key];
        if (val !== undefined && val !== null && val !== "") {
          params.set(key, String(val));
        }
      });
      const qs = params.toString();
      if (qs) url += (url.indexOf("?") >= 0 ? "&" : "?") + qs;
    }
    return request(url, { method: "GET" });
  }

  function post(path, body) {
    return request(path, {
      method: "POST",
      body: body instanceof FormData ? body : JSON.stringify(body || {}),
    });
  }

  function put(path, body) {
    return request(path, {
      method: "PUT",
      body: JSON.stringify(body || {}),
    });
  }

  function del(path) {
    return request(path, { method: "DELETE" });
  }

  function logout() {
    clearToken();
    window.location.href = "/admin/index.html";
  }

  function publicBelgeUrl(uid) {
    return baseUrl() + "/belge/" + encodeURIComponent(uid);
  }

  async function fetchAuthenticatedBlobUrl(path) {
    const token = getToken();
    const res = await fetch(baseUrl() + path, {
      method: "GET",
      headers: token ? { Authorization: "Bearer " + token } : {},
    });

    if (res.status === 401) {
      clearToken();
      window.location.href = loginPath();
      throw new Error("Yetkisiz erişim");
    }

    const blob = await res.blob();
    return URL.createObjectURL(blob);
  }

  async function openAuthenticatedFile(path) {
    const url = await fetchAuthenticatedBlobUrl(path);
    const win = window.open(url, "_blank", "noopener");
    if (!win) {
      const a = document.createElement("a");
      a.href = url;
      a.target = "_blank";
      a.rel = "noopener";
      document.body.appendChild(a);
      a.click();
      a.remove();
    }
    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 60_000);
  }

  window.Api = {
    setToken,
    getToken,
    clearToken,
    setUser,
    getUser,
    loginPath,
    requireAuth,
    redirectIfAuthed,
    request,
    get,
    post,
    put,
    del,
    logout,
    publicBelgeUrl,
    fetchAuthenticatedBlobUrl,
    openAuthenticatedFile,
  };
})(window);
