<?php
/**
 * Government competitive exam module.
 *
 * The section is announced before it opens. Everything about how it is
 * presented — the navigation label, whether it still reads "soon", the
 * launch date and the two blocks of copy — is set in
 * Admin -> Govt Exam, so the page can go live without a code change.
 *
 * Nothing here needs the v6 tables to exist: on an install that has not
 * migrated yet the module simply reports itself as switched off.
 */

/** Is the section switched on at all? */
function govt_exam_on(): bool
{
    return module_on('govt_exam') && setting('govt_exam_enabled', '1') === '1';
}

/** 'soon' while it is being built, 'live' once it opens. */
function govt_exam_status(): string
{
    return setting('govt_exam_status', 'soon') === 'live' ? 'live' : 'soon';
}

/** Should it appear in the site navigation? */
function govt_exam_in_nav(): bool
{
    return govt_exam_on() && module_in_nav('govt_exam');
}

/** The launch date as Y-m-d, or '' if none has been set. */
function govt_exam_launch(): string
{
    $d = trim(setting('govt_exam_launch'));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
}

/** Whole days until launch. Negative once the date has passed, null if unset. */
function govt_exam_days_left(): ?int
{
    $d = govt_exam_launch();
    if ($d === '') {
        return null;
    }
    $diff = strtotime($d . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00');
    return (int) round($diff / 86400);
}

/** The exam streams offered on the interest form. */
function govt_exam_streams(): array
{
    return ['WBCS', 'SSC CGL', 'SSC CHSL', 'Railway (RRB)', 'Banking (IBPS/SBI)',
            'WB Police', 'PSC Clerkship', 'UPSC', 'Teaching (TET)', 'Other'];
}

/** How many people have joined the interest list. */
function govt_exam_signups(): int
{
    if (!has_table('exam_notify')) {
        return 0;
    }
    return (int) fetch_col('SELECT COUNT(*) FROM exam_notify', [], 0);
}

/**
 * Add someone to the interest list.
 * Returns [ok, message]. A repeat email is treated as success, not an error.
 */
function govt_exam_join(array $d): array
{
    if (!has_table('exam_notify')) {
        return [false, 'The notify list is not ready yet. Please try again later.'];
    }

    $email = trim((string) ($d['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'That does not look like a working email address.'];
    }

    $stream = (string) ($d['exam_pref'] ?? '');
    if ($stream !== '' && !in_array($stream, govt_exam_streams(), true)) {
        $stream = 'Other';
    }

    $existing = fetch_one('SELECT id FROM exam_notify WHERE email = ?', [$email]);
    $row = [
        'user_id'    => is_logged_in() ? (int) $_SESSION['user_id'] : null,
        'name'       => mb_substr(trim((string) ($d['name'] ?? '')), 0, 100) ?: null,
        'email'      => mb_substr($email, 0, 150),
        'phone'      => mb_substr(preg_replace('/[^\d+\- ]/', '',
                            (string) ($d['phone'] ?? '')), 0, 24) ?: null,
        'exam_pref'  => $stream ?: null,
        'note'       => mb_substr(trim((string) ($d['note'] ?? '')), 0, 190) ?: null,
        'ip_address' => client_ip(),
    ];

    if ($existing) {
        unset($row['ip_address']);
        update_row('exam_notify', $row, 'id = ?', [(int) $existing['id']]);
        return [true, 'You are already on the list — we have updated your details.'];
    }

    insert_row('exam_notify', $row);
    return [true, 'You are on the list. We will email you the moment it opens.'];
}
