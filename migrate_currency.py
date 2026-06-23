import pymysql

# ── Config ───────────────────────────────────────────────────────────────────
DB_CONFIG = {
    'host':     '100.98.160.119',
    'port':     3306,
    'user':     'movira_dev',
    'password': 'devjayaA9&',
    'database': 'migration_temp_venken',
    'charset':  'utf8mb4'
}

CREATED_BY   = 'system_migration'
TARGET_TABLE = 'currency'

# ── Data ─────────────────────────────────────────────────────────────────────
# NOTE: currency_id values below are truncated — replace with full UUIDs from source DB.
CURRENCY_DATA = [
    {
        'currency_id':     '3a83d987-cfd4-11ee-a',
        'currency_code':   'EUR',
        'currency_symbol': '€',
        'currency_name':   'Euro',
    },
    {
        'currency_id':     '3f3170cc-cfd4-11ee-a',
        'currency_code':   'USD',
        'currency_symbol': '$',
        'currency_name':   'US Dollar',
    },
    {
        'currency_id':     '439009dd-cfd4-11ee-a',
        'currency_code':   'CNY',
        'currency_symbol': '¥',
        'currency_name':   'Chinese Yuan',
    },
    {
        'currency_id':     '47f12c01-cfd4-11ee-a',
        'currency_code':   'SGD',
        'currency_symbol': 'S$',
        'currency_name':   'Singapore Dollar',
    },
    {
        'currency_id':     '6044d37e-d147-11ee-8',
        'currency_code':   'IDR',
        'currency_symbol': 'Rp',
        'currency_name':   'Indonesian Rupiah',
    },
    {
        'currency_id':     'a41b3fd6-be84-11ef-b',
        'currency_code':   'THB',
        'currency_symbol': '฿',
        'currency_name':   'Thai Baht',
    },
]

# ── Main ──────────────────────────────────────────────────────────────────────
conn   = pymysql.connect(**DB_CONFIG)
cursor = conn.cursor()

success = 0
skipped = 0
errors  = []

for row in CURRENCY_DATA:
    currency_id     = row['currency_id']
    currency_code   = row['currency_code']
    currency_symbol = row['currency_symbol']
    currency_name   = row['currency_name']

    # cek duplikat (unique: currency_id)
    cursor.execute(
        f"SELECT 1 FROM {TARGET_TABLE} WHERE currency_id = %s LIMIT 1",
        (currency_id,)
    )
    if cursor.fetchone():
        print(f"[SKIP]  {currency_code} ({currency_id}) already exists")
        skipped += 1
        continue

    try:
        cursor.execute(f"""
            INSERT INTO {TARGET_TABLE}
                (currency_id, currency_code, currency_symbol, currency_name, created_by)
            VALUES (%s, %s, %s, %s, %s)
        """, (currency_id, currency_code, currency_symbol, currency_name, CREATED_BY))
        success += 1
        print(f"[OK]    {currency_code} - {currency_name} ({currency_symbol})")
    except Exception as e:
        errors.append({'currency_code': currency_code, 'error': str(e)})
        print(f"[ERROR] {currency_code}: {e}")

conn.commit()
cursor.close()
conn.close()

print(f"\n✅ Success : {success}")
print(f"⏭️  Skipped : {skipped}")
print(f"❌ Errors  : {len(errors)}")
if errors:
    for e in errors:
        print(f"   - {e['currency_code']}: {e['error']}")
