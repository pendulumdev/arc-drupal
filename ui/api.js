function apiRoot() {
  const base = window.drupalSettings?.path?.baseUrl || "/";
  return base.endsWith("/") ? base.slice(0, -1) : base;
}

function token() {
  return window.drupalSettings?.pendulumArc?.csrfToken || "";
}

async function request(path, options = {}) {
  const headers = {
    Accept: "application/json",
    "X-CSRF-Token": token(),
  };
  if (options.body) {
    headers["Content-Type"] = "application/json";
  }
  const response = await fetch(apiRoot() + path, {
    method: options.method || "GET",
    headers,
    body: options.body ? JSON.stringify(options.body) : undefined,
    credentials: "same-origin",
  });
  let data = {};
  try {
    data = await response.json();
  }
  catch {
    data = {};
  }
  if (!response.ok) {
    const error = new Error(typeof data.message === "string" ? data.message : "");
    error.data = data;
    throw error;
  }
  return data;
}

export function connectWithApiKey(apiKey) {
  return request("/admin/pendulum-arc/connect/api-key", {
    method: "POST",
    body: { apiKey },
  });
}

export function startOAuth() {
  return request("/admin/pendulum-arc/connect/oauth/start", { method: "POST" });
}

export function reconnect() {
  return request("/admin/pendulum-arc/connect/reconnect", { method: "POST" });
}

export function createSite(draft) {
  return request("/admin/pendulum-arc/connect/site", {
    method: "POST",
    body: draft,
  });
}

export function pairSite(siteId) {
  return request("/admin/pendulum-arc/connect/pair", {
    method: "POST",
    body: { siteId },
  });
}

export function disconnect() {
  return request("/admin/pendulum-arc/connection", { method: "DELETE" });
}

export function fetchScores() {
  return request("/admin/pendulum-arc/scores");
}

export function fetchAnalytics() {
  return request("/admin/pendulum-arc/analytics");
}

export function fetchReport(service) {
  return request(`/admin/pendulum-arc/reports/${encodeURIComponent(service)}`);
}

export function fetchRuns() {
  return request("/admin/pendulum-arc/runs");
}

export function saveConsent(settings) {
  return request("/admin/pendulum-arc/consent", {
    method: "POST",
    body: settings,
  });
}

export function renewConsent() {
  return request("/admin/pendulum-arc/consent/renew", { method: "POST" });
}

export function resetConsent() {
  return request("/admin/pendulum-arc/consent/reset", { method: "POST" });
}

export function resetConsentStyles() {
  return request("/admin/pendulum-arc/consent/styles/reset", { method: "POST" });
}

export async function uploadConsentIcon(file) {
  const body = new FormData();
  body.append("icon", file);
  const response = await fetch(apiRoot() + "/admin/pendulum-arc/consent/icon", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "X-CSRF-Token": token(),
    },
    body,
    credentials: "same-origin",
  });
  let data = {};
  try {
    data = await response.json();
  }
  catch {
    data = {};
  }
  if (!response.ok) {
    const error = new Error(typeof data.message === "string" ? data.message : "");
    error.data = data;
    throw error;
  }
  return data;
}

export function enqueueRun(service) {
  return request("/admin/pendulum-arc/runs", {
    method: "POST",
    body: { service },
  });
}

export function fieldErrors(error) {
  if (error && error.data && error.data.fields) {
    return error.data.fields;
  }
  return {};
}

export function errorMessage(error, fallback) {
  if (error && typeof error.message === "string" && error.message) {
    return error.message;
  }
  return fallback;
}
