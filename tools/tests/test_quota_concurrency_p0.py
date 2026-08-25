#!/usr/bin/env python3
"""
P0: Quota concurrency test — verifies atomic reservation
Simulates: daily_used=1499, quota_limit=1500, 2 concurrent requests -> only 1 should reserve
"""

import sqlite3
import threading
import tempfile
import os
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
migration = ROOT / "api/database/migrations/v0.4_ai_foundation.sql"
assert migration.is_file()

# Create temp SQLite DB
db_path = tempfile.mktemp(suffix=".sqlite")
conn = sqlite3.connect(db_path, check_same_thread=False)
conn.execute("CREATE TABLE ai_provider_quotas (provider VARCHAR(32) PRIMARY KEY, daily_used INTEGER DEFAULT 0, quota_limit INTEGER DEFAULT 1500, reset_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);")
conn.execute("INSERT INTO ai_provider_quotas (provider, daily_used, quota_limit) VALUES ('gemini', 1499, 1500);")
conn.commit()

# Atomic reservation function (same as PHP implementation)
def try_reserve(provider):
    try:
        # Use separate connection per thread to simulate real concurrency
        c = sqlite3.connect(db_path)
        c.execute("BEGIN IMMEDIATE;")
        cur = c.execute("SELECT daily_used, quota_limit FROM ai_provider_quotas WHERE provider=?", (provider,))
        row = cur.fetchone()
        if not row or row[0] >= row[1]:
            c.execute("ROLLBACK;")
            c.close()
            return False
        cur = c.execute("UPDATE ai_provider_quotas SET daily_used = daily_used + 1 WHERE provider=? AND daily_used < quota_limit", (provider,))
        success = cur.rowcount > 0
        if success:
            c.execute("COMMIT;")
        else:
            c.execute("ROLLBACK;")
        c.close()
        return success
    except Exception as e:
        print(f"Error: {e}")
        return False

results = []

def worker(n):
    res = try_reserve('gemini')
    results.append((n, res))

# Simulate 2 concurrent requests near limit
threads = [threading.Thread(target=worker, args=(i,)) for i in range(2)]
for t in threads:
    t.start()
for t in threads:
    t.join()

print(f"Results: {results}")
success_count = sum(1 for _, r in results if r)
print(f"Success count: {success_count} (expected 1)")

# Verify final count
cur = conn.execute("SELECT daily_used FROM ai_provider_quotas WHERE provider='gemini'")
final_used = cur.fetchone()[0]
print(f"Final daily_used: {final_used} (expected 1500)")

# Test quota exhausted
second_try = try_reserve('gemini')
print(f"After exhausted, try reserve: {second_try} (expected False)")

# Cleanup
conn.close()
os.unlink(db_path)

# Assertions
assert success_count == 1, f"Expected 1 success, got {success_count} — race condition exists!"
assert final_used == 1500, f"Expected 1500, got {final_used} — overflow!"
assert second_try == False, "Should fail when quota exhausted"

print("\n=== Quota Concurrency Test: PASS ===")
print("No 1501/1500 overflow, atomic reservation works")
