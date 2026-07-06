# GMA Testimonial Plugins — Photo Reminder & Photo Uploader

This repo contains two companion WordPress plugins for [Global Mentoring Academy](https://globalmentoringacademy.com) that together close the "testimonial without a photo" gap:

| Plugin | File | Version | Role |
|---|---|---|---|
| **GMA Testimonial Photo Reminder** | `gma-testimonial-reminder.php` | 5.10 | Emails members who submitted a testimonial but haven't uploaded a photo |
| **GMA Testimonial Photo Uploader** | `gma-testimonial-photo-uploader.php` | 1.0 | The members-only upload page those reminder emails link to |

---

# Plugin 1: GMA Testimonial Photo Reminder (v5.10)

## Requirements

### Business requirement
- Testimonials without photos are less compelling on the public testimonials page.
- Members who submitted a testimonial without a photo should be politely reminded by email to add one — automatically, on a schedule, without pestering anyone indefinitely.

### Functional requirements
1. Daily automated reminder emails at a configurable time (IST).
2. Candidates = Strong Testimonials posts (`wpm-testimonial`) that have **no featured image** and **do have an email** meta value.
3. **Max reminders per person** is configurable (default 2) and strictly enforced across auto and manual sends.
4. Email subject and HTML body are editable in wp-admin, with `{first_name}`, `{name}`, `{email}` placeholders.
5. Admin copies (Cc) go to GMA admins on every send.
6. Manual "send now" and per-recipient test sends from the admin page.
7. Full activity log (capped at 500 entries) with IST timestamps and per-recipient send count (e.g. `2/2`).
8. Admin page shows last run, next scheduled run, and live cron status.

### Technical requirements
- WP-Cron based (single events, rescheduled after each run) — no server crontab needed.
- All dates/times hardcoded to **Asia/Kolkata (IST)**, independent of WordPress/server timezone settings.
- Reliable email delivery via WP Mail SMTP.

## Solution

Single class `GMA_TR` handles everything:

1. **Scheduling** — `schedule_next()` computes the next run at the configured IST time (today if still ahead, otherwise tomorrow), clears any old event, and schedules a single `gma_tr_event`. The run handler always calls `schedule_next()` at the end, so the chain never breaks.
2. **Self-healing cron (FIX 1)** — on every page load (`init`), `maybe_reschedule()` re-queues the event if it has vanished (plugin update, cron clear, hosting hiccup). The admin page shows a red "NOT SCHEDULED — will self-heal" warning if that state is ever observed.
3. **Day guard (FIX 4)** — auto-runs are limited to once per IST calendar day via `gma_tr_last_sent_day`, and the guard still reschedules before exiting (FIX 2a).
4. **Per-recipient enforcement (FIX 3)** — a persistent `gma_tr_send_counts` option maps email → count; recipients at/over the max are skipped in both auto and manual runs. A one-time migration (`migrate_counts()`) rebuilt counts from the pre-v5.9 log history.
5. **Sending** — placeholders substituted into the HTML body; sent via `wp_mail()` from `support@globalmentoringacademy.com` with admin Cc's; every send appended to the capped log with IST timestamp and `n/max` count.
6. **Lifecycle** — activation seeds default settings only if none exist (config survives re-activation); deactivation clears the scheduled hook.

## Code map

| Element | Purpose |
|---|---|
| `activate()` / `deactivate()` | Default settings on first activate; cron cleanup on deactivate |
| `maybe_reschedule()` on `init` | Self-healing cron |
| `migrate_counts()` | One-time send-count rebuild from legacy logs |
| `schedule_next()` | IST-aware single-event scheduling |
| `get_candidates()` | Testimonials with email but no featured image |
| `run($manual)` | Day guard → per-recipient max check → send → log → count → reschedule |
| `page()` | Settings form, cron status, manual send, activity log in wp-admin |

---

# Plugin 2: GMA Testimonial Photo Uploader (v1.0)

## Requirements

### Business requirement
- Members submit testimonials via WPForms, which are stored as **Strong Testimonials** posts (`wpm-testimonial` custom post type).
- Many testimonials are published without a photo, which weakens their impact on the public testimonials page.
- Members should be able to add or update their testimonial photo themselves, without admin involvement and without access to wp-admin.

### Functional requirements
1. Only **logged-in users** can upload a photo.
2. The plugin must automatically match the logged-in user to **their own testimonial** — matched by comparing the user's account email to the testimonial's `email` meta field.
3. The uploaded image is set as the testimonial's **featured image** (which Strong Testimonials displays on the public page).
4. If a featured photo already exists, the user is shown the current photo and can replace it.
5. After a successful upload, the user gets a confirmation with a link to the public testimonials page.

### Technical requirements
- WordPress with the **Strong Testimonials** plugin active (`wpm-testimonial` post type).
- Testimonials must carry the submitter's email in an `email` post meta key (populated by the WPForms → Strong Testimonials pipeline).
- Users must have WordPress accounts whose email matches their testimonial email (handled by the GMA Testimonial Auto Reg plugin).

---

## Solution

The plugin registers a single shortcode:

```
[gma_testimonial_photo_upload]
```

Placed on a page (e.g. a members-only "Upload Your Photo" page), it renders a self-contained upload flow:

1. **Login gate** — anonymous visitors see a "please log in" message.
2. **Testimonial lookup** — the logged-in user's email is used in a `meta_query` against the `wpm-testimonial` post type to find their testimonial. If none is found, a friendly message is shown.
3. **Existing photo preview** — if the testimonial already has a featured image, it is displayed (medium size) with a note, so the user knows a re-upload will replace it.
4. **Upload form** — a simple `multipart/form-data` form with a file input restricted to `image/*`.
5. **Upload handling** — on submit, WordPress core media functions (`media_handle_upload`) process the file into the Media Library, and `set_post_thumbnail()` attaches it to the testimonial as the featured image.
6. **Confirmation** — a thank-you message with a link to the public testimonials page.

### Design decisions
- **Email-based matching** keeps the flow zero-configuration for the member — no testimonial IDs or tokens required.
- **Core media APIs** (`wp-admin/includes/file.php`, `media.php`, `image.php`) are used rather than manual file handling, so all standard WordPress upload security, MIME checks, and image size generation apply.
- **Featured image** is used as the storage target because Strong Testimonials natively renders the featured image with each testimonial — no template changes needed.

---

## Code

Single-file plugin: `gma-testimonial-photo-uploader.php`

| Element | Purpose |
|---|---|
| Plugin header | Registers the plugin with WordPress (v1.0) |
| `ABSPATH` guard | Prevents direct file access |
| `add_shortcode('gma_testimonial_photo_upload', ...)` | Registers the entire flow as one shortcode callback |
| `wp_get_current_user()` | Gets the logged-in user's email for matching |
| `get_posts()` with `meta_query` on `email` | Finds the user's `wpm-testimonial` post |
| `media_handle_upload('gma_photo', 0)` | Sideloads the uploaded file into the Media Library |
| `set_post_thumbnail($post_id, $attachment_id)` | Sets the image as the testimonial's featured image |
| `has_post_thumbnail()` / `get_the_post_thumbnail()` | Shows the current photo, if any, before the form |
| Output buffering (`ob_start` / `ob_get_clean`) | Returns the form markup as a string, as shortcodes require |

### Installation
1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or copy `gma-testimonial-photo-uploader.php` into `wp-content/plugins/gma-testimonial-photo-uploader/`.
2. Activate **GMA Testimonial Photo Uploader**.
3. Create a page and add the shortcode `[gma_testimonial_photo_upload]`.
4. Link to that page from the Photo Reminder emails.

---

## Development workflow

Standard process for every change to this plugin:

1. Modify the source and **increment the version number** (plugin header + zip filename, e.g. `gma-testimonial-photo-uploader-v1_1_0.zip`).
2. Run the PHP verification script before packaging.
3. Build the zip (internal folder name must match the plugin slug exactly).
4. **Push the updated source to this GitHub repo** and update this README when the requirements, solution, or code structure change.

All fixes are carried forward — each version is cumulative; no separate patch zips.
