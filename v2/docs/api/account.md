# Account API

> **Last updated:** 2026-07-07 08:11:31 WIB
> **Base URL:** `/api/v2/account`
> **Auth:** No auth required, except `GET /account/my-permissions` which requires `Authorization: Bearer <token>`

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v2/account/login` | Authenticate a user and receive a JWT |
| POST | `/api/v2/account/send-otp` | Send a registration OTP via WhatsApp |
| POST | `/api/v2/account/register` | Register a new user into an existing company |
| GET | `/api/v2/account/my-permissions` | Get the permission list granted to the caller's role |

---

### POST `/api/v2/account/login`

Authenticates an existing Aluria user. Returns a signed JWT (8-hour expiry) and minimal user and company metadata.

#### Request body (`application/json`)

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Yes | Valid email format, max 50 chars. Trimmed and lowercased before lookup. |
| `password` | string | Yes | Min 8 chars, max 128 chars. Must not be empty. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Login successful",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
      "user_id": "usr_64a1b2c3d4e5f",
      "first_name": "Budi",
      "last_name": "Santoso",
      "email": "budi@example.com",
      "position_id": "pos_admin_sales",
      "position_name": "Admin Sales",
      "language": "id"
    },
    "company": {
      "company_id": "cmp_xyz789",
      "company_name": "PT Maju Bersama",
      "days_remaining": 23
    }
  }
}
```

**JWT payload claims:**

| Claim | Type | Description |
|---|---|---|
| `user_id` | string | User's ID (`usr_` prefix) |
| `username` | string | User's username (same as email) |
| `email` | string | User's email address |
| `first_name` | string | First name |
| `last_name` | string | Last name |
| `app_id` | string | Always `"aluria"` |
| `app_role_id` | string | Role assigned to this user |
| `company_id` | string | Company this user belongs to |
| `position_id` | string | Position ID |
| `days_remaining` | int | Days until next billing. `0` if no active subscription. |
| `language` | string | `"id"` or `"en"` |
| `iat` | int | Issued-at Unix timestamp |
| `exp` | int | Expiry Unix timestamp (`iat + 28800`, i.e. 8 hours) |

#### Failure responses

| Code | `error_code` | `status_message` | When |
|---|---|---|---|
| 400 | — | `The email field is required.` | Missing field |
| 400 | — | `The password field is required.` | Missing field |
| 401 | `AUTH_001` | `Invalid email or password` | User not found or wrong password |
| 401 | `AUTH_002` | `Account is not verified yet` | `account_status = 'pending'` |
| 401 | `AUTH_003` | `Account is pending payment verification` | `account_status = 'waiting_payment'` |
| 403 | `AUTH_004` | `Account has been suspended. Contact your administrator.` | `account_status = 'suspended'` |
| 403 | `AUTH_005` | `Account has been disabled.` | `account_status = 'disabled'` |
| 403 | `AUTH_006` | `Your company account is not active.` | Company `status = 'inactive'` |
| 429 | `RATE_001` | `Too many login attempts. Please try again in 15 minutes.` | 5+ failed attempts for this email in 15 min |
| 429 | `RATE_002` | `Too many login attempts. Please try again later.` | 20+ requests from this IP in 1 min |
| 500 | — | `An unexpected error occurred. Please try again.` | Server error |

---

### POST `/api/v2/account/send-otp`

Generates a 6-digit OTP for the `register` purpose and sends it via WhatsApp (WAHA) to the phone number provided. Must be called before `POST /account/register`, whose `otp_code` field is validated against the code sent here.

Delivery to WhatsApp is best-effort: a WAHA failure is logged server-side but does not fail the request, since the OTP has already been generated and stored.

#### Request body (`application/json`)

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Yes | Valid email format, max 50 chars. Trimmed and lowercased. Used as the OTP `identifier`. |
| `phone_number` | string | Yes | Indonesian format: starts with `08` or `+62`, 10–15 digits total. Where the WhatsApp message is sent. |

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "A WhatsApp message with your registration code has been sent to the phone number provided.",
  "data": null
}
```

#### Failure responses

| Code | `error_code` | `status_message` | When |
|---|---|---|---|
| 400 | — | `The {field} field is required.` | Missing field |
| 400 | — | `The email field must be a valid email address (max 50 characters).` | Invalid email format |
| 400 | — | `The phone_number field must be a valid Indonesian number...` | Invalid phone format |
| 409 | `REG_003` | `An account with this email already exists.` | Email already registered |
| 429 | `RATE_003` | `Too many requests. Please try again later.` | 10+ requests from this IP, or 3+ for this email, in 1 hour |
| 500 | — | `An unexpected error occurred. Please try again.` | Server error |

#### Notes

- The OTP expires after 10 minutes and allows up to 5 verification attempts (enforced in `register.php`).
- Requesting a new OTP invalidates any previously issued, still-unused `register`-purpose code for the same email.

---

### POST `/api/v2/account/register`

Registers a new user into an **existing** company. The company must already exist and be active. An OTP (sent separately via `POST /account/send-otp`) must be verified as part of this call.

On success:
- If this is the **first user** of the company: account is immediately `verified` and assigned the **Business Owner** role.
- If subsequent user: account is created with the default fallback role and status `pending`. **`position_id` and (if needed) `app_role_id` are no longer chosen at registration** — a super admin assigns them as part of the existing account-approval step, since a new registrant has no way to look up valid position IDs.

No JWT is issued on registration. The user must log in after approval.

#### Request body (`application/json`)

| Field | Type | Required | Rules |
|---|---|---|---|
| `first_name` | string | Yes | 2–100 chars. Letters, spaces, hyphens only. |
| `last_name` | string | Yes | 2–100 chars. Letters, spaces, hyphens only. |
| `email` | string | Yes | Valid email format, max 50 chars. |
| `phone_number` | string | Yes | Indonesian format: starts with `08` or `+62`, 10–15 digits total. |
| `password` | string | Yes | See password policy below. |
| `password_confirmation` | string | Yes | Must exactly match `password`. |
| `company_code` | string | Yes | Must match an active Aluria company. Case-insensitive. |
| `otp_code` | string | Yes | 6-digit numeric string. Must be valid, unused, and not expired. |
| `language` | string | No | `"id"` or `"en"`. Defaults to `"id"`. |

**Password policy** — all of the following must be satisfied:
- Minimum 8 characters, maximum 128 characters
- At least one uppercase letter (A–Z)
- At least one lowercase letter (a–z)
- At least one digit (0–9)
- At least one special character: `! @ # $ % ^ & * ( ) - _ = + [ ] { } ; ' : " , . < > ? / \ |`
- Must not be a common password
- Must not contain `first_name`, `last_name`, or the local part of `email` as a substring (case-insensitive)

#### Response `201 Created`

```json
{
  "status_code": 201,
  "status_message": "Registration successful. Your account is pending approval by your company administrator.",
  "data": {
    "user_id": "usr_64a1b2c3d4e5f",
    "email": "sari@example.com",
    "first_name": "Sari",
    "last_name": "Dewi",
    "account_status": "pending",
    "company_name": "PT Maju Bersama"
  }
}
```

> When the registering user is the **first user** of the company, `account_status` will be `"verified"` (not `"pending"`) and they receive the Business Owner role immediately.

#### Failure responses

| Code | `error_code` | `status_message` | When |
|---|---|---|---|
| 400 | — | `The {field} field is required.` | Any required field is missing or empty |
| 400 | — | `The first_name field must be 2-100 characters...` | Invalid first_name format |
| 400 | — | `The last_name field must be 2-100 characters...` | Invalid last_name format |
| 400 | — | `The email field must be a valid email address (max 50 characters).` | Invalid email format |
| 400 | — | `The phone_number field must be a valid Indonesian number...` | Invalid phone format |
| 400 | — | `The otp_code field must be a 6-digit numeric code.` | Non-numeric or wrong length |
| 400 | `REG_007` | `Password must be at least 8 characters and contain uppercase, lowercase, number, and special character.` | Password complexity failure |
| 400 | `REG_007` | `Password is too common. Please choose a more unique password.` | Common password detected |
| 400 | `REG_007` | `Password must not contain your name or email address.` | Name/email substring in password |
| 400 | `REG_008` | `Password and confirmation do not match.` | `password !== password_confirmation` |
| 400 | `REG_004` | `Invalid or expired OTP code.` | OTP not found, expired, already used, or wrong code |
| 404 | `REG_001` | `Company not found. Please check your company code.` | `company_code` not found |
| 409 | `REG_003` | `An account with this email already exists.` | Email already registered |
| 422 | `REG_002` | `This company account is not active.` | Company found but not active |
| 429 | `REG_005` | `Too many OTP attempts. Request a new code.` | OTP `attempt_count >= 5` |
| 429 | `RATE_003` | `Too many registration attempts. Please try again later.` | IP or email rate limit hit |
| 500 | — | `An unexpected error occurred. Please try again.` | Server error |

---

### GET `/api/v2/account/my-permissions`

Returns the flat and grouped permission list granted to the caller's role (`app_role_id` from the JWT). Requires `Authorization: Bearer <token>`.

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Permissions retrieved successfully",
  "data": {
    "app_role_id": "role0a1152b01417134d",
    "role_name": "Admin Purchase",
    "permissions": [
      "dashboard.view_full",
      "view.purchase"
    ],
    "modules": {
      "Dashboard": [
        {
          "permission_key": "dashboard.view_full",
          "label": "View Full Dashboard",
          "description": null
        }
      ],
      "Purchase": [
        {
          "permission_key": "view.purchase",
          "label": "View Purchase",
          "description": null
        }
      ]
    }
  }
}
```

#### Failure responses

| Code | `status_message` | When |
|---|---|---|
| 400 | `No role assigned to this account yet.` | JWT has no `app_role_id` |
| 401 | `Authorization header missing or malformed` \| `Invalid token signature` \| `Token expired` | Missing, malformed, or expired Bearer token |
| 404 | `Role not found` | `app_role_id` no longer exists in `app_role` |
| 405 | `Method not allowed` | Any method other than `GET` |
| 500 | `An unexpected error occurred. Please try again.` | Server error |

---

## Error response shape

All error responses include an optional `error_code` field for frontend localisation. Success responses omit `error_code`.

```json
{
  "status_code": 401,
  "status_message": "Invalid email or password",
  "error_code": "AUTH_001",
  "data": null
}
```

---

## Notes

- **Permission map is not in the JWT.** After login, fetch `GET /account/my-permissions` separately to get the permission list for the session — both a flat `permissions` array (for quick `hasPermission(key)` checks) and a `modules`-grouped shape (for rendering a permissions/access-control screen).
- **Call `POST /account/send-otp` before calling register** to have an OTP generated and delivered via WhatsApp.
- **Token expiry is 8 hours.** There is no refresh token endpoint; the user must log in again after expiry.
- **Email enumeration prevention.** The login endpoint returns the same `AUTH_001` message whether the email does not exist or the password is wrong. Do not try to infer existence from the response.
- **Rate limit counters are server-side only.** The remaining attempt count is never exposed in the response.
- **Position is assigned post-registration.** A newly registered (non-first) user has no `position_id` or specific role until a company admin approves the account and assigns them, since the registrant has no way to look up valid position IDs at signup time.
- **`position_name` is `null` until assigned.** The login response resolves `position_name` via a `LEFT JOIN` on `app_position`, so it is `null` for any user without a `position_id` yet.
