---
name: user-admin
description: "Use when user wants to see when a user last logged in, find users who haven't been active, lock a user account, or get an activity summary for a user."
---

# User Admin Skill

## Available Tools

| Tool | When to use |
|------|-------------|
| `get_user_last_login` | Look up when a specific user last logged in to the site. |
| `find_inactive_users` | Find users who haven't been active for a specified number of days. |
| `lock_user_account` | Revoke a user's capabilities and destroy all their active sessions. |
| `get_user_activity_summary` | Get post count, comment count, last activity dates, and active sessions for a user. |

## Workflows

### Investigate a specific user

1. Call `get_user_last_login` to find when they last accessed the site.
2. Call `get_user_activity_summary` for a full picture of their contributions.
3. Present findings including post count, comment count, and active sessions.

### Inactive user cleanup audit

1. Call `find_inactive_users` with the desired `days` threshold (e.g. 90 or 180).
2. Optionally filter by `role` (e.g. "subscriber") to focus on specific user types.
3. Present the list to the user for review before taking any action.

### Lock a compromised account

1. Confirm the `user_id` or `user_login` with the user.
2. Warn that `lock_user_account` will destroy all active sessions immediately.
3. Call `lock_user_account` with an optional `reason`.
4. Confirm the lock was applied and provide unlock instructions.

## Rules

- Last login data depends on either the `agentic_last_login` usermeta (set by Siteloom on login) or `session_tokens` (set by WordPress core). Users who have never had a session will show no last login.
- `lock_user_account` cannot be used to lock an administrator unless the calling user is also an administrator — and it will never allow a user to lock their own account.
- Always confirm before calling `lock_user_account` — it is immediate and will log the user out.
- `find_inactive_users` with a very low `days` value may return a large number of users — encourage the user to set a reasonable threshold (90+ days).
