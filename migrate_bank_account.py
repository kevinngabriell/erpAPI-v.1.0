import uuid
import pymysql

# ── Config ────────────────────────────────────────────────────────────────────
DB_CONFIG = {
    'host':     '100.98.160.119',
    'port':     3306,
    'user':     'movira_dev',
    'password': 'devjayaA9&',
    'database': 'migration_temp_venken',  # ganti sama nama DB yang bener
    'charset':  'utf8mb4'
}

COMPANY_ID     = '1252f67e-bfda-11ee-9dcf-0e799759a249'   # ganti dengan company_id yang sesuai
CREATED_BY     = 'system_migration'
TARGET_TABLE   = 'bank_account'
CURRENCY_TABLE = 'currency'             # ganti jika nama tabelnya beda
CURRENCY_ID_COL   = 'currency_id'       # ganti jika nama kolomnya beda
CURRENCY_CODE_COL = 'currency_name'     # ganti jika nama kolomnya beda

# ── Data ──────────────────────────────────────────────────────────────────────
# bank_number: gunakan placeholder string jika tidak ada nomor rekening
BANK_DATA = [
    {'bank_number': 'KAS_KECIL',    'bank_name': 'KAS KECIL',  'bank_branch': None,                   'currency_code': 'IDR'},
    {'bank_number': '0042126128',   'bank_name': 'SINARMAS',   'bank_branch': None,                   'currency_code': 'IDR'},
    {'bank_number': '800152783500', 'bank_name': 'CIMB NIAGA', 'bank_branch': 'JUANDA BEKASI',        'currency_code': 'IDR'},
    {'bank_number': '8015373888',   'bank_name': 'BCA IDR',    'bank_branch': 'KCP FINANCIAL CENTER', 'currency_code': 'IDR'},
    {'bank_number': '8015593888',   'bank_name': 'BCA USD',    'bank_branch': 'KCP FINANCIAL CENTER', 'currency_code': 'USD'},
]

# ── Helpers ───────────────────────────────────────────────────────────────────
def generate_uuid() -> str:
    return str(uuid.uuid4())

def load_currency_map(cursor) -> dict:
    cursor.execute(f"SELECT {CURRENCY_ID_COL}, {CURRENCY_CODE_COL} FROM {CURRENCY_TABLE}")
    return {row[1]: row[0] for row in cursor.fetchall()}

# ── Main ──────────────────────────────────────────────────────────────────────
conn   = pymysql.connect(**DB_CONFIG)
cursor = conn.cursor()

currency_map = load_currency_map(cursor)
print(f"[INFO] Loaded {len(currency_map)} currencies: {list(currency_map.keys())}\n")

success = 0
skipped = 0
errors  = []

for row in BANK_DATA:
    bank_number   = row['bank_number'].strip()
    bank_name     = row['bank_name'].strip()
    bank_branch   = row['bank_branch'].strip() if row.get('bank_branch') else None
    currency_code = row['currency_code']

    currency_id = currency_map.get(currency_code)
    if not currency_id:
        msg = f"currency_code '{currency_code}' not found in {CURRENCY_TABLE}"
        errors.append({'bank_number': bank_number, 'error': msg})
        print(f"[ERROR] {bank_number}: {msg}")
        continue

    # cek duplikat (unique: bank_number + company_id)
    cursor.execute(
        f"SELECT 1 FROM {TARGET_TABLE} WHERE bank_number = %s AND company_id = %s LIMIT 1",
        (bank_number, COMPANY_ID)
    )
    if cursor.fetchone():
        print(f"[SKIP]  {bank_number} ({bank_name}) already exists")
        skipped += 1
        continue

    try:
        bank_account_id = generate_uuid()
        cursor.execute(f"""
            INSERT INTO {TARGET_TABLE}
                (bank_account_id, bank_number, bank_name, bank_branch,
                 currency_id, is_primary, company_id, created_by)
            VALUES (%s, %s, %s, %s, %s, 0, %s, %s)
        """, (bank_account_id, bank_number, bank_name, bank_branch,
              currency_id, COMPANY_ID, CREATED_BY))
        success += 1
        print(f"[OK]    {bank_number} - {bank_name} ({currency_code})")
    except Exception as e:
        errors.append({'bank_number': bank_number, 'error': str(e)})
        print(f"[ERROR] {bank_number}: {e}")

conn.commit()
cursor.close()
conn.close()

print(f"\n✅ Success : {success}")
print(f"⏭️  Skipped : {skipped}")
print(f"❌ Errors  : {len(errors)}")
if errors:
    for e in errors:
        print(f"   - {e['bank_number']}: {e['error']}")
