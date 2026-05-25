# User API — Refactor Changelog

**Date:** 2026-05-25  
**Scope:** `/user/` module  

---

## Summary of Changes

| # | Old File | New File | Change Type |
|---|----------|----------|-------------|
| 1 | `login.php` | `login.php` | Updated — now issues JWT |
| 2 | `createuser.php` | `register.php` | Renamed + hardened |
| 3 | `forgotpassword.php` | `forgot-password.php` | Renamed + hardened |
| 4 | `detailuser.php` | `profile.php` (GET) | Merged into profile |
| 5 | `updatepassword.php` | `profile.php` (PATCH) | Merged into profile |
| 6 | `deleteuser.php` | `profile.php` (DELETE) | Merged into profile |
| 7 | `resetaccount.php` | `reset-account.php` | Renamed + JWT added |
| 8 | `createuniqueid.php` | `referral.php` | Renamed + JWT added |

> **Old files have been removed.** Update all frontend calls to use the new endpoints listed below.

---

## New Standard Response Format

All endpoints now return a consistent JSON structure:

```json
{
  "statusCode": 200,
  "status": "Success" | "Error",
  "message": "Human-readable message",
  "data": { ... }
}
```

- `data` is only present on successful responses that return a payload.
- HTTP status codes are used correctly: `200 OK`, `201 Created`, `400 Bad Request`, `401 Unauthorized`, `404 Not Found`, `405 Method Not Allowed`, `409 Conflict`, `422 Unprocessable Entity`, `500 Internal Server Error`.

---

## JWT Authentication

Protected endpoints require:
```
Authorization: Bearer <token>
```

Token is obtained from `POST /user/login.php`. It expires after **10 hours**.

---

## Endpoint Reference

### 1. `POST /user/login.php` — Login
**Auth required:** No

**Request body** (`form-data`):
| Field | Type | Required |
|-------|------|----------|
| `username` | string | Yes |
| `password` | string | Yes |

**Response `200`:**
```json
{
  "statusCode": 200,
  "status": "Success",
  "token": "eyJ...",
  "expiresIn": 36000,
  "firstName": "John",
  "lastName": "Doe",
  "username": "johndoe",
  "permissionAccess": "admin",
  "companyNameString": "PT Venken",
  "companyId": "abc-123"
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `203` | Username not found |
| `204` | Password mismatch |

---

### 2. `POST /user/register.php` — Register New User
**Auth required:** No  
**Was:** `createuser.php`

**Request body** (`form-data`):
| Field | Type | Required |
|-------|------|----------|
| `first_name` | string | Yes |
| `last_name` | string | Yes |
| `username` | string | Yes |
| `password` | string | Yes |
| `verification_code` | string | Yes |

**Response `201`:**
```json
{
  "statusCode": 201,
  "status": "Success",
  "message": "User registered successfully"
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing required fields |
| `409` | Username already exists |
| `422` | Verification code not found / already used / expired |
| `500` | Database error |

---

### 3. `POST /user/forgot-password.php` — Forgot Password
**Auth required:** No  
**Was:** `forgotpassword.php`

Resets the user's password to `123456` after verifying a valid, unused verification code.

**Request body** (`form-data`):
| Field | Type | Required |
|-------|------|----------|
| `username` | string | Yes |
| `verification_code` | string | Yes |

> **Note:** Old file used `verfication_code` (typo). New file corrects this to `verification_code`.

**Response `200`:**
```json
{
  "statusCode": 200,
  "status": "Success",
  "message": "Password has been reset to default. Please change it after logging in."
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing required fields |
| `422` | Verification code not found / already used / expired |
| `500` | Database error |

---

### 4. `GET /user/profile.php` — Get User Profile
**Auth required:** Yes (JWT)  
**Was:** `detailuser.php`

**Query params:**
| Param | Type | Required |
|-------|------|----------|
| `username` | string | Yes |

**Example:** `GET /user/profile.php?username=johndoe`

**Response `200`:**
```json
{
  "statusCode": 200,
  "status": "Success",
  "data": {
    "full_name": "John Doe",
    "username": "johndoe",
    "permission_access": "admin"
  }
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing `username` param |
| `401` | Missing or invalid JWT |
| `404` | User not found |

---

### 5. `PATCH /user/profile.php` — Update Password
**Auth required:** Yes (JWT)  
**Was:** `updatepassword.php`

**Request body** (`application/json`):
| Field | Type | Required |
|-------|------|----------|
| `username` | string | Yes |
| `new_password` | string | Yes |

**Response `200`:**
```json
{
  "statusCode": 200,
  "status": "Success",
  "message": "Password updated successfully"
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing required fields |
| `401` | Missing or invalid JWT |
| `404` | User not found |
| `500` | Database error |

---

### 6. `DELETE /user/profile.php` — Delete User
**Auth required:** Yes (JWT)  
**Was:** `deleteuser.php`

**Request body** (`application/json`):
| Field | Type | Required |
|-------|------|----------|
| `username` | string | Yes |

**Response `200`:**
```json
{
  "statusCode": 200,
  "status": "Success",
  "message": "User deleted successfully"
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing `username` field |
| `401` | Missing or invalid JWT |
| `404` | User not found |
| `500` | Database error |

---

### 7. `POST /user/reset-account.php` — Admin Reset Account Password
**Auth required:** Yes (JWT)  
**Was:** `resetaccount.php`

Resets any user's password to `123456` without requiring a verification code. Intended for admin use only.

**Request body** (`application/json`):
| Field | Type | Required |
|-------|------|----------|
| `username` | string | Yes |

**Response `200`:**
```json
{
  "statusCode": 200,
  "status": "Success",
  "message": "Account password has been reset to default"
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing `username` field |
| `401` | Missing or invalid JWT |
| `404` | User not found |
| `500` | Database error |

---

### 8. `POST /user/referral.php` — Create Referral Code
**Auth required:** Yes (JWT)  
**Was:** `createuniqueid.php`

Generates a 6-character alphanumeric referral code tied to a company. This code is used during user registration (`verification_code` flow).

**Request body** (`application/json`):
| Field | Type | Required |
|-------|------|----------|
| `company_id` | string | Yes |
| `limit_user` | integer | Yes |

**Response `201`:**
```json
{
  "statusCode": 201,
  "status": "Success",
  "message": "Referral created successfully",
  "data": {
    "referralId": "aB3kR9",
    "companyId": "abc-123",
    "limitUser": 10
  }
}
```

**Error responses:**
| Code | Meaning |
|------|---------|
| `400` | Missing required fields |
| `401` | Missing or invalid JWT |
| `404` | Company not found |
| `500` | Database error |

---

## Migration Notes

### Request body format changes
| Endpoint | Old format | New format |
|----------|-----------|------------|
| `profile.php` PATCH | `form-data` | `application/json` |
| `profile.php` DELETE | `form-data` | `application/json` |
| `reset-account.php` | `form-data` | `application/json` |
| `referral.php` | `form-data` | `application/json` |

### Typo fix
- `forgotpassword.php` used `verfication_code` (missing `i`). The new `forgot-password.php` uses the correct spelling `verification_code`.

### Security improvements
- All protected endpoints now require a valid JWT (`Authorization: Bearer <token>`).
- SQL queries in `profile.php`, `register.php`, `forgot-password.php`, `reset-account.php`, and `referral.php` use **prepared statements** — eliminating SQL injection risk.
- `register.php` returns a specific `409 Conflict` when username is taken (old file returned `203` which is not a standard error code).
- Verification code errors now return `422 Unprocessable Entity` instead of `500`.
