<?php
// Task inbox: "done" / "later" per open item. Notification centre: Extended reminder types on/off.
$db->exec('CREATE TABLE IF NOT EXISTS task_state (
    user_id INTEGER NOT NULL,
    task_key TEXT NOT NULL,
    status TEXT NOT NULL,          -- done | later
    until TEXT,                    -- later: hidden until this day
    PRIMARY KEY (user_id, task_key)
)');
$db->exec('CREATE TABLE IF NOT EXISTS notification_prefs (
    user_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, kind)
)');
