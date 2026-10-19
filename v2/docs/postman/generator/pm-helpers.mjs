// Shared helpers for building the Postman collection programmatically.
import crypto from 'node:crypto';

export function uid() {
  return crypto.randomUUID();
}

const STATUS_TEXT = {
  200: 'OK', 201: 'Created', 204: 'No Content', 400: 'Bad Request',
  401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found',
  405: 'Method Not Allowed', 409: 'Conflict', 410: 'Gone',
  422: 'Unprocessable Entity', 429: 'Too Many Requests',
  500: 'Internal Server Error',
};

function buildUrl(path, query = []) {
  const segments = path.split('/').filter(Boolean);
  const variable = [];
  const pathOut = segments.map((seg) => {
    if (seg.startsWith(':')) {
      const name = seg.slice(1);
      variable.push({ key: name, value: query.__pathValues?.[name] ?? '', description: '' });
    }
    return seg;
  });
  const activeQuery = query.filter((q) => !q.__meta);
  const qs = activeQuery.length
    ? '?' + activeQuery.map((q) => `${q.key}=${encodeURIComponent(q.value ?? '')}`).join('&')
    : '';
  return {
    raw: `{{base_url}}/${pathOut.join('/')}${qs}`,
    host: ['{{base_url}}'],
    path: pathOut,
    query: activeQuery.map((q) => ({
      key: q.key,
      value: String(q.value ?? ''),
      description: q.description || '',
      disabled: !!q.disabled,
    })),
    variable,
  };
}

// pathVars: { name: value } — supplies literal example values for :name segments in `path`
export function req({
  name,
  method,
  path,
  description = '',
  query = [],
  pathVars = {},
  body = null,
  bodyRaw = null,
  noAuth = false,
  examples = [],
  extraHeaders = [],
}) {
  query.__pathValues = pathVars;
  const url = buildUrl(path, query);
  const header = [...extraHeaders];
  if (body !== null || bodyRaw !== null) {
    header.push({ key: 'Content-Type', value: 'application/json' });
  }
  const request = {
    method,
    header,
    url,
    description,
  };
  if (body !== null) {
    request.body = { mode: 'raw', raw: JSON.stringify(body, null, 2), options: { raw: { language: 'json' } } };
  } else if (bodyRaw !== null) {
    request.body = { mode: 'raw', raw: bodyRaw, options: { raw: { language: 'text' } } };
  }
  if (noAuth) {
    request.auth = { type: 'noauth' };
  }
  const item = {
    name,
    request,
    response: examples.map((ex) => buildExample(ex, request, url)),
  };
  return item;
}

function buildExample(ex, originalRequest, url) {
  const code = ex.code;
  const statusText = ex.status || STATUS_TEXT[code] || '';
  const isBinary = ex.binary === true;
  let bodyStr;
  if (isBinary) {
    bodyStr = ex.body || `<binary ${ex.contentType || 'file'} response — not representable as JSON in Postman>`;
  } else {
    bodyStr = typeof ex.body === 'string' ? ex.body : JSON.stringify(ex.body, null, 2);
  }
  const headers = ex.headers || [{ key: 'Content-Type', value: ex.contentType || 'application/json' }];
  return {
    name: ex.name || `${code} ${statusText}`,
    originalRequest: {
      method: originalRequest.method,
      header: originalRequest.header,
      url,
      body: originalRequest.body,
    },
    status: statusText,
    code,
    _postman_previewlanguage: isBinary ? 'text' : 'json',
    header: headers,
    cookie: [],
    body: bodyStr,
  };
}

export function folder(name, item, description = '') {
  return { name, description, item };
}

// Standard envelope helpers -------------------------------------------------

export function ok(message, data) {
  return { status_code: 200, status_message: message, data };
}
export function created(message, data) {
  return { status_code: 201, status_message: message, data };
}
export function badReq(message, data = []) {
  return { status_code: 400, status_message: message, data };
}
export function notFound(message, data = []) {
  return { status_code: 404, status_message: message, data };
}
export function conflict(message, data = []) {
  return { status_code: 409, status_message: message, data };
}
export function forbidden(message, data = []) {
  return { status_code: 403, status_message: message, data };
}
export function serverErr(message, data = []) {
  return { status_code: 500, status_message: message, data };
}

export const authHeader401 = () => notFound; // placeholder unused

export function paginated(rows, total = 42, page = 1, limit = 10) {
  return { data: rows, pagination: { total, page, limit, total_pages: Math.max(1, Math.ceil(total / limit)) } };
}

// Generic CRUD builder for simple master-data modules ------------------------
// cfg: {
//   resource, path, displayName (Title Case singular), idParam, idExample,
//   companyScoped (bool), searchParam (string|null) - query param name for search,
//   extraListQuery: [{key,value,description}],
//   listRow: object, detailRow: object (defaults to listRow),
//   createBody: object, createdField: string, createdIdExample: string,
//   messages: { listFound, listEmpty, itemFound, itemNotFound, created,
//               requiredField: {field, message}, duplicate, updated,
//               noFields, deleted },
//   extraCreate400: [ {message} ]  additional 400 example bodies for create,
//   extraCreate404: [ {message} ]  additional 404 example bodies for create (FK checks),
//   extraUpdate400: [...], extraUpdate404: [...],
//   updateBody: object (defaults derived from createBody minus required-only tweaks),
//   notes: string (folder description)
// }
export function crudModule(cfg) {
  const {
    path, displayName, idParam, idExample, searchParam = 'search',
    extraListQuery = [], listRow, detailRow = listRow,
    createBody, createdField, createdIdExample,
    messages, extraCreate400 = [], extraCreate404 = [], extraCreate409 = null,
    updateBody, extraUpdate400 = [], extraUpdate404 = [], extraUpdate409 = null,
    notes = '',
  } = cfg;

  const listQuery = [
    { key: 'page', value: '1', description: 'Page number' },
    { key: 'limit', value: '15', description: 'Items per page (max 15)' },
  ];
  if (searchParam) {
    listQuery.push({ key: searchParam, value: '', description: 'Search filter', disabled: true });
  }
  extraListQuery.forEach((q) => listQuery.push({ ...q, disabled: q.disabled ?? true }));

  const items = [];

  items.push(req({
    name: `List ${displayName}s`,
    method: 'GET',
    path: `${path}`,
    description: `Paginated list of ${displayName.toLowerCase()}s.`,
    query: listQuery,
    examples: [
      { code: 200, body: ok(messages.listFound, paginated([listRow])) },
      { code: 404, body: notFound(messages.listEmpty) },
      { code: 401, body: badReq('Authorization header missing or malformed') },
    ],
  }));

  const create400 = [
    { code: 400, body: badReq(messages.requiredMessage) },
    ...extraCreate400.map((m) => ({ code: 400, body: badReq(m) })),
  ];
  const create404 = extraCreate404.map((m) => ({ code: 404, body: notFound(m) }));
  const createExamples = [
    { code: 201, body: created(messages.created, { [createdField]: createdIdExample }) },
    ...create400,
    ...create404,
  ];
  if (extraCreate409 !== null) {
    createExamples.push({ code: 409, body: conflict(extraCreate409) });
  } else if (messages.duplicate) {
    createExamples.push({ code: 409, body: conflict(messages.duplicate) });
  }
  items.push(req({
    name: `Create ${displayName}`,
    method: 'POST',
    path: `${path}`,
    description: `Create a new ${displayName.toLowerCase()}.`,
    body: createBody,
    examples: createExamples,
  }));

  items.push(req({
    name: `Get ${displayName} Detail`,
    method: 'GET',
    path: `${path}/:${idParam}`,
    pathVars: { [idParam]: idExample },
    description: `Get detail of a single ${displayName.toLowerCase()}.`,
    examples: [
      { code: 200, body: ok(messages.itemFound, detailRow) },
      { code: 404, body: notFound(messages.itemNotFound) },
    ],
  }));

  const upd400 = [
    { code: 400, body: badReq(messages.noFields) },
    ...extraUpdate400.map((m) => ({ code: 400, body: badReq(m) })),
  ];
  const upd404 = [
    { code: 404, body: notFound(messages.itemNotFound) },
    ...extraUpdate404.map((m) => ({ code: 404, body: notFound(m) })),
  ];
  const updExamples = [
    { code: 200, body: ok(messages.updated, []) },
    ...upd400,
    ...upd404,
  ];
  if (extraUpdate409 !== null) {
    updExamples.push({ code: 409, body: conflict(extraUpdate409) });
  } else if (messages.duplicate) {
    updExamples.push({ code: 409, body: conflict(messages.duplicate) });
  }
  items.push(req({
    name: `Update ${displayName}`,
    method: 'PUT',
    path: `${path}/:${idParam}`,
    pathVars: { [idParam]: idExample },
    description: `Update a ${displayName.toLowerCase()}. Only send the fields you want to change.`,
    body: updateBody ?? createBody,
    examples: updExamples,
  }));

  items.push(req({
    name: `Delete ${displayName}`,
    method: 'DELETE',
    path: `${path}/:${idParam}`,
    pathVars: { [idParam]: idExample },
    description: `Soft-delete a ${displayName.toLowerCase()} (sets deleted_at).`,
    examples: [
      { code: 200, body: ok(messages.deleted, []) },
      { code: 404, body: notFound(messages.itemNotFound) },
    ],
  }));

  return folder(`${displayName} (/${path})`, items, notes);
}

export const commonErrorNote = (extra = '') =>
  `Common errors: 400 validation failed, 401 missing/expired token, 404 not found, 405 method not allowed, 409 duplicate, 500 server error.${extra ? '\n' + extra : ''}`;
