"""Test helper (3.64.0): gives an account a paid Job Hunt pack, so suites that use one-click letters, whole-resume
tailoring or mock interviews run as a customer who has bought them."""
import json, uuid


def give_job_hunt(db, email, days=30):
    uid = db.q("select id from users where email=?", email)[0][0]
    limits = {"type": "plan", "rank": 2, "savedResumes": 10, "savedLetters": 10, "atsScans": 20, "exports": 20, "mockInterviews": 4}
    db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) "
         f"values(?,?,'job-hunt','paid',29900,'demo',?,{days},datetime('now'),datetime('now','+{days} days'),datetime('now'),datetime('now'))",
         "test-" + uuid.uuid4().hex[:12], uid, json.dumps(limits))
    return uid
